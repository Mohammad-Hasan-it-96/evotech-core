# Module: Downloads

The **Download Center**: where EVOTECH products publish versioned artifacts and where
products **self-update**. A **composition module** — it references the [Products](products.md)
catalog (reference data) and depends on the [Gateway](gateway.md) module's `ProductContext`
**contract** for its product-facing endpoints (acyclic: `Downloads → {Products, Gateway·contract} → Core`).

The model: a **Release** is a versioned publication of a product on a **channel**
(`stable`/`beta`/`alpha`); it groups one **Artifact** per **platform + variant**
(`windows`/`macos`/`linux`/`android`/`ios`/`web`/`any`). Only a **Published** release is
visible to products and downloadable. Files never live at a public path — they sit on a
**private disk** and are handed out only as **short-lived signed URLs** ([ADR 0008](../adr/0008-download-center-delivery.md)).

## Storage & delivery ([ADR 0008](../adr/0008-download-center-delivery.md))

- Artifacts are stored on the disk named by `config('downloads.disk')` (env `DOWNLOADS_DISK`),
  defaulting to the private **`downloads`** local disk; production points it at **`s3`**.
- A client never receives a storage path. The **link** endpoints mint a signed URL
  (TTL `config('downloads.link_ttl_minutes')`, default 15) to the `downloads.deliver` route,
  guarded by Laravel's `signed` middleware; an expired or tampered URL is rejected (`403`).
- **Issue-time is the auditable download event**: minting a link appends an immutable
  `download_events` row and increments the artifact's `download_count`. `download_count` therefore
  counts *links issued* — a deliberate proxy that stays consistent across delivery backends
  (a future S3 pre-signed redirect / CDN never reaches the app).
- On upload the service records the file **size**, the **content-detected** MIME type (finfo,
  not the client extension), and a **SHA-256** checksum, echoed to clients for integrity checks.

## Endpoints — staff (all `auth:sanctum`, under `/api/v1`)

| Method | Path | Name | Description |
|---|---|---|---|
| GET | `/releases` | `releases.index` | Paginated list; filters: `product` (slug), `channel`, `status`. |
| POST | `/releases` | `releases.store` | Create a **draft** — body: `product` (slug), `channel`, `version`, `name?`, `notes?`. |
| GET | `/releases/{release}` | `releases.show` | Show by uuid, with its artifacts. |
| PATCH | `/releases/{release}` | `releases.update` | Edit `channel`/`version`/`name`/`notes`. |
| DELETE | `/releases/{release}` | `releases.destroy` | Soft-delete the release and its artifacts (files removed). `204`. |
| POST | `/releases/{release}/publish` | `releases.publish` | Publish — **requires ≥1 artifact** (`422` otherwise); audited `release.published`. |
| POST | `/releases/{release}/archive` | `releases.archive` | Retire a release. |
| GET | `/releases/{release}/artifacts` | `releases.artifacts.index` | List the release's per-platform artifacts. |
| POST | `/releases/{release}/artifacts` | `releases.artifacts.store` | Upload (multipart: `file`, `platform`, optional `variant`) — checksummed; replaces an existing platform+variant pair, so a second ABI sits alongside the first rather than overwriting it. `201`. Audited `artifact.uploaded`. **Extension allowlist** (installers, archives, firmware images): the endpoint serves files from the platform's own origin, so an `.html` or `.svg` artifact would be script running as this site — and the recorded content type is detected, never enforced. Checked by extension rather than MIME because an APK and a JAR are both `application/zip`. |
| DELETE | `/artifacts/{artifact}` | `artifacts.destroy` | Soft-delete an artifact and remove its file. `204`. |
| POST | `/artifacts/{artifact}/link` | `artifacts.link` | Mint a signed download URL (`{data:{url, expires_at}}`); records a `staff` download event. |
| GET | `/downloads/events` | `downloads.events.index` | Read the download ledger; filter by `artifact` (uuid). |

## Endpoint — signed delivery (`signed` middleware, under `/api/v1`)

| Method | Path | Name | Description |
|---|---|---|---|
| GET | `/downloads/deliver/{artifact}` | `downloads.deliver` | Validate the signature, then stream the file from its private disk with its real filename. The only route that serves bytes; never linked to directly. |

## Endpoint — permanent public download (no auth, `throttle:30,1`, under `/api/v1`)

| Method | Path | Name | Description |
|---|---|---|---|
| GET | `/downloads/latest/{product}/{platform}/{variant?}` | `downloads.latest` | 302 to a freshly minted signed link for the **current published** build. `?channel=` defaults to `stable`. `404` for an unknown product/platform/variant/channel or a release that is not published. |

**`variant`** distinguishes builds of one platform — Android's `arm64-v8a` and
`armeabi-v7a`. Omitting it addresses the **universal** build specifically; it does
not pick one of the ABIs. Guessing would hand an arm64 APK to an armeabi device:
an install failure, with nothing explaining why. Stored as an empty string, which
is why the column is `NOT NULL` — SQL treats NULLs as distinct, so a nullable
column would let two rows both claim the universal slot unnoticed.

**Why this exists next to signed links.** A signed URL is right for an authenticated
product self-updating — per-product, audited, short-lived. It is useless for the one
thing a *consumer app* needs: a URL that can sit inside a config file cached for
minutes, or a message sent to a customer, and still work tomorrow.

This URL never expires **because it does not name a file**. It names *the current
build for a platform* and resolves at request time, so publishing 1.0.1 changes what
it serves with no config edit and no link to reissue — every already-cached copy
starts pointing at the new build on its own.

It **redirects rather than streaming**, so exactly one route still serves bytes and
the ledger is reused unchanged (`actor_type` `public`). It is **unauthenticated**
because the artifacts it can reach are, by definition, published builds already
handed to anyone who asks — draft and archived releases stay unreachable. Throttled
per IP because it is the one unauthenticated route that can move gigabytes.

Other modules reach these URLs through Core's **`ReleaseDownloadLocator`** port
(§2.4) rather than this module — `NullReleaseDownloadLocator` is the safe default,
and `ReleaseDownloadUrlLocator` here supplies the real answer. `DeviceSubscriptions`
uses it to fill the consumer apps' remote-config download links automatically.

## Endpoints — product-facing (`auth:product` + `throttle:product`, under `/api/v1/product`)

Authenticated by a **per-product API key** ([Gateway](gateway.md), [ADR 0004](../adr/0004-product-to-platform-auth-api-keys.md)).
A product only ever sees/acts on **its own** product's artifacts — any other is `404`.

| Method | Path | Name | Description |
|---|---|---|---|
| GET | `/product/releases/latest` | `releases.latest` | The latest **published** release on `channel` (default `stable`), optionally filtered to a `platform`; `404` if none. The auto-update check. |
| POST | `/product/artifacts/{artifact}/link` | `artifacts.link` | Mint a signed download URL for one of the product's own artifacts; records a `product` download event. **Requires the release to be published** — owning the product is not enough, or a product knowing a uuid could pull an unreleased build. `404`, matching the cross-product case. |

## Configuration (`config/downloads.php`)

| Key | Env | Default | Purpose |
|---|---|---|---|
| `disk` | `DOWNLOADS_DISK` | `downloads` | Private disk artifacts live on; set to `s3` in production. |
| `max_upload_kilobytes` | `DOWNLOADS_MAX_UPLOAD_KB` | `2097152` (2 GB) | Upload size cap. |
| `link_ttl_minutes` | `DOWNLOADS_LINK_TTL_MINUTES` | `15` | Signed download URL lifetime. |
| `default_channel` | `DOWNLOADS_DEFAULT_CHANNEL` | `stable` | Channel assumed when a request omits one. |

## Deferred (additive, no contract change)

- Direct **S3 pre-signed redirect** for the `s3` disk and **CDN** fronting of delivery.
- A **virus-scan pipeline** for uploaded binaries (constitution §16.7).
- **Per-company entitlement gating** via a `Core` entitlement port fulfilled by [Licenses](licenses.md)
  (product self-update is already gated by the revocable per-product API key).
