# ADR 0005 — Signed offline license tokens: EdDSA (Ed25519) JWS

- **Status:** Accepted
- **Date:** 2026-07-08
- **Deciders:** Founder/CTO, Chief Software Architect
- **Related:** `docs/ARCHITECTURE.md` §6.1 (offline/IoT auth), §6.10 (key management), §7 (API contract), Commandment #3 (a license format is a promise); `docs/ROADMAP.md` Phase 4; [ADR 0004](0004-product-to-platform-auth-api-keys.md).

## Context

IoT/smart controllers and other devices must be able to confirm their license
entitlement **without connectivity** — a restaurant's smart controller cannot
depend on a live call to the platform to keep working through a network outage.
The constitution (§6.1) already commits to the mechanism in the abstract:
"Offline / IoT devices: cryptographically **signed license tokens** (asymmetric;
platform signs, device verifies offline)."

This ADR fixes the concrete token format. It is a **contract**: once devices in
the field verify a token shape, we cannot break it without breaking them
(Commandment #3). So the format, algorithm, and claim set are decided here, not
left implicit in code.

Requirements:
- **Asymmetric** — the platform holds the private key; devices carry only the
  public key and can verify but never mint tokens.
- **Offline-verifiable** — self-contained; a device verifies signature + expiry
  with no platform round-trip.
- **Interoperable** — device teams write in varied stacks (C/C++, Rust, .NET,
  JS); the format must be verifiable with off-the-shelf libraries.
- **No heavy new dependency** on the platform side (constitution §0 — boring,
  native, minimal surface).

## Decision

Issue **JWS compact tokens signed with EdDSA over Ed25519** (RFC 8037).

- **Algorithm: `EdDSA` / Ed25519.** Produced with PHP's native **ext-sodium**
  (`sodium_crypto_sign_detached`) — no JWT/crypto library is added. Ed25519 is
  compact (64-byte signatures, 32-byte keys), fast, misuse-resistant, and
  supported by every mainstream JWT library, so device teams verify with standard
  tooling. RS256 was considered (universally available via ext-openssl) but Ed25519
  is smaller and more modern; ext-sodium is present in our runtime.
- **Format: JWS compact** — `base64url(header).base64url(payload).base64url(sig)`.
  Header: `{"typ":"JWT","alg":"EdDSA","kid":"<key id>"}`.
- **Claim set (the contract):**
  ```json
  {
    "iss": "evotech-platform",
    "sub": "<license key>",
    "aud": "<product slug>",
    "jti": "<uuid>",
    "iat": 0, "nbf": 0, "exp": 0,
    "license": { "key": "...", "status": "active",
                 "expires_at": "<iso8601|null>", "max_activations": 1 },
    "device":  { "identifier_type": "device|domain", "identifier": "..." }
  }
  ```
- **Expiry (`exp`) is short and clamped.** Default TTL is 14 days
  (`LICENSE_TOKEN_TTL_DAYS`), and `exp` is never later than the license's own
  `expires_at`. Devices re-fetch a fresh token while online before the old one
  lapses; a revoked/expired license simply stops getting new tokens, so offline
  validity is bounded without needing online revocation.
- **Issuance is authenticated and bounded.** Only a product (per its API key,
  ADR 0004) may request a token, only for **its own** license, and only for a
  device that is already an **active activation** of that license. Each issuance
  is recorded in the immutable `license_events` ledger as `token_issued`
  (Commandment #2 / §6.14).
- **Public-key distribution.** The Ed25519 public key is published as an RFC 8037
  JWK (`kty=OKP, crv=Ed25519`) at `GET /api/v1/product/keys` (public,
  throttled). Devices fetch and cache it while online; the `kid` lets us rotate
  keys by publishing a new one alongside the old during a transition window.
- **Key management (§6.10).** Private and public keys are **managed secrets**
  supplied via env (`LICENSE_TOKEN_PRIVATE_KEY`, `LICENSE_TOKEN_PUBLIC_KEY`,
  base64), documented in `.env.example` with dummy values, never committed. The
  `php artisan licenses:keygen` command generates a keypair for an environment.

## Consequences

**Positive**
- Standard, interoperable, offline-verifiable tokens with **zero new
  dependencies**; device teams use any EdDSA-capable JWT library.
- Entitlement survives outages up to `exp`, bounded and clamped to the license's
  own lifetime; issuance is authenticated, product-scoped, activation-bounded, and
  audited.
- `kid`-based rotation path is built in from day one.

**Negative / Risks**
- **Offline tokens cannot be revoked before `exp`.** Mitigation: short, clamped
  TTL; this is the accepted, inherent trade-off of offline validation. Sensitive
  changes take effect at the next online refresh.
- We must **operate signing keys** carefully (generation, storage as secrets,
  rotation, compromise response). Mitigated by env-injected secrets, the keygen
  command, and `kid` rotation; a leaked private key is rotated and the old `kid`
  retired.
- ext-sodium becomes a runtime requirement for token issuance (already present;
  should be asserted in deployment provisioning).

## Compliance note

This implements the existing §6.1 offline-token commitment; it does not amend a
constitutional decision, but it **establishes the token format as a versioned
contract** under Commandment #3. Any breaking change to the header, algorithm, or
claim set requires a superseding ADR and a device-compatible migration (publish
new `kid`/format alongside the old through a deprecation window).
