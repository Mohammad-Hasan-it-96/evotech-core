# ADR 0008 — Download Center: storage & signed delivery

- **Status:** Accepted
- **Date:** 2026-07-08
- **Deciders:** Platform architect
- **Supersedes / superseded by:** —
- **Related:** ARCHITECTURE.md §3 (`Downloads` module), §5.6 (Storage), §16.7 (file uploads), ADR 0004 (product-to-platform auth), ADR 0005 (offline tokens)

## Context

Phase 6 introduces the **Download Center** (`Downloads` module): the platform surface
through which EVOTECH products publish and deliver versioned artifacts (installers,
updaters, firmware). Two audiences consume it:

1. **Staff** manage releases and upload artifacts through the authenticated dashboard.
2. **Products** poll for the latest release on their channel and self-update, authenticated
   by their per-product API key (ADR 0004).

The constitution is prescriptive about how binaries are stored and served (§5.6, §16.7,
§14): **never store binaries on the app server**, use **S3-compatible object storage** in
every non-local environment, keep artifact disks **private**, and hand out **time-limited
signed URLs** — never public paths. Artifacts must be validated by **content** (not
extension) and carry a checksum. We need a delivery mechanism that satisfies all of this,
works with the local filesystem in development (no S3 dependency to run the test suite),
and is CDN-ready in production.

## Decision

**1. A dedicated, private, swappable disk.**
Artifacts live on a Laravel filesystem disk selected by `config('downloads.disk')`
(env `DOWNLOADS_DISK`). It defaults to a new **private `downloads` local disk**
(`storage/app/private/downloads`) for local development and CI; production points the same
config at the existing **`s3`** disk. No code path assumes a driver — swapping the env is
the only change between environments. Binaries are therefore never committed and, in prod,
never touch the app server's disk.

**2. Signed, time-limited delivery — never a public path.**
A client never receives a storage path or a public URL. Instead it requests a download and
receives a **short-lived signed URL** (TTL `config('downloads.link_ttl_minutes')`, default
15) to the `downloads.deliver` route, protected by Laravel's `signed` middleware. The route
validates the signature and **streams** the file from the private disk with the original
filename; an expired or tampered URL is rejected (`403`). Because the mechanism is a signed
URL, production can front the same route (or S3 pre-signed URLs) with a CDN (§14) without
changing the contract.

**3. The download ledger records intent at issue-time.**
Issuing a signed URL is the auditable "download" event: the service appends an immutable
`download_events` row (actor type/id, company, ip, user-agent) and increments the artifact's
`download_count` **when the link is minted**, not when bytes are served. This keeps the
ledger consistent across delivery backends — a future S3 pre-signed redirect or CDN edge
never hits the app, so issue-time is the only point the platform observes every audience.

**4. Content-validated, checksummed uploads.**
On upload the service records the file size, the **content-detected** MIME type
(`UploadedFile::getMimeType()`, finfo-based — not the client extension), and a **SHA-256**
checksum, stored on the artifact and echoed to clients so a device can verify integrity
after download. Upload size is capped by `config('downloads.max_upload_kilobytes')`.

**5. Product scoping.** A product may only see and download artifacts belonging to **its own**
product (matched via `ProductContext`, ADR 0004); any other artifact is `404`, exactly as the
Licenses product endpoints behave.

## Consequences

- **Positive:** runs with zero external services locally and in CI (`Storage::fake`), yet is
  S3/CDN-ready in prod by env alone; artifacts are private by default; every download is
  attributable and integrity-verifiable; the contract is stable across delivery backends.
- **Negative / trade-offs:** with the local disk, delivery streams through the app process
  (fine at platform scale; in prod S3 + CDN offloads this). `download_count` counts *links
  issued*, a deliberate proxy for downloads (documented in the module doc).
- **Deferred (additive, no contract change):** direct **S3 pre-signed redirect** for the `s3`
  disk; **CDN** fronting; a **virus-scan pipeline** for user-supplied binaries (§16.7); and
  **per-company entitlement gating** of downloads via a `Core` entitlement port fulfilled by
  the Licenses module (product self-update is already gated by the revocable API key).
