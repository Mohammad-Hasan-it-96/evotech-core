# Module: Licenses

The machine-readable credential that proves a **Company**'s entitlement to a product, derived from a **Subscription**. This is a **composition module** — it references the [Subscriptions](subscriptions.md) and [Companies](companies.md) modules and reacts to their events, and depends on the [Gateway](gateway.md) module's `ProductContext` **contract** for its product-facing endpoints (an accepted, acyclic dependency; `Licenses → {Subscriptions, Companies, Gateway·contract} → Core`).

The platform rule: **subscription = entitlement, license = the credential.** Activating a subscription auto-issues (or renews) its license; the license then hands out up to `max_activations` device/domain **activation slots**.

## Endpoints (all `auth:sanctum`, under `/api/v1`)

| Method | Path | Name | Description |
|---|---|---|---|
| GET | `/licenses` | `licenses.index` | Paginated list, each with company + subscription + product and `activations_used`. |
| POST | `/licenses` | `licenses.store` | Manually issue for a subscription — body: `subscription` (uuid), `max_activations?`. |
| GET | `/licenses/{license}` | `licenses.show` | Show by uuid, including its activations. |
| POST | `/licenses/{license}/suspend` | `licenses.suspend` | Reversible pause. |
| POST | `/licenses/{license}/reactivate` | `licenses.reactivate` | Undo a suspension. |
| POST | `/licenses/{license}/revoke` | `licenses.revoke` | Terminal — sets `revoked_at`, never resurrected. |
| GET | `/licenses/{license}/activations` | `licenses.activations.index` | List the license's activation slots. |
| POST | `/licenses/{license}/activations` | `licenses.activations.store` | Claim a slot — body: `identifier_type` (`domain`/`device`), `identifier`, `name?`. |
| DELETE | `/licenses/{license}/activations/{activation}` | `licenses.activations.destroy` | Release a slot (204). Scoped to the license. |

## Endpoints — product-facing (`auth:product` + `throttle:product`, under `/api/v1/product`)

Authenticated by a **per-product API key** ([Gateway](gateway.md) module, [ADR 0004](../adr/0004-product-to-platform-auth-api-keys.md)). A product references its license by `key` and may only act on licenses belonging to **its own** product — any other license is `404`. Ledger events from here are attributed to the `product` actor (`actor_id` = product slug).

| Method | Path | Name | Description |
|---|---|---|---|
| POST | `/product/licenses/activate` | `product.licenses.activate` | Self-activate a slot — body: `key`, `identifier_type`, `identifier`, `name?`. `201`. |
| POST | `/product/licenses/validate` | `product.licenses.validate` | Online validation — body: `key`, `identifier?` (heartbeats a matching activation). |
| POST | `/product/licenses/deactivate` | `product.licenses.deactivate` | Release own slot — body: `key`, `identifier`. `204`. |
| POST | `/product/licenses/token` | `product.licenses.token` | Issue a **signed offline token** for an already-activated device — body: `key`, `identifier`. `201`. |

The activate/validate/deactivate response reports `valid`, `status`, `product` (slug), `expires_at`, `max_activations`, `activations_used`, and the `activation` the call concerns. Activation limit and usable-license rules are identical to the admin activation endpoints (a `422` on breach).

## Signed offline tokens (ADR 0005)

For IoT/devices that must confirm entitlement **without connectivity**. `POST /product/licenses/token` returns an **EdDSA (Ed25519) JWS** (`{token, algorithm, key_id, issued_at, expires_at}`) that a device verifies offline with the platform's public key. Requirements: the license is currently valid and the `identifier` is an **active activation** of it (else `422`); the token's `exp` is the default TTL (`LICENSE_TOKEN_TTL_DAYS`, 14) **clamped to the license's own expiry**; issuance appends a `token_issued` ledger event (product actor). Token claims: `iss`, `sub`=license key, `aud`=product slug, `jti`, `iat`/`nbf`/`exp`, plus `license` and `device` blocks — see the ADR for the exact contract.

| Method | Path | Name | Auth | Description |
|---|---|---|---|---|
| GET | `/product/keys` | `product.keys` | **public** | Ed25519 public verification key(s) as RFC 8037 JWKs (`{data:{algorithm, keys:[…]}}`). Devices fetch and cache this while online; `kid` supports rotation. |

**Keys are managed secrets** (§6.10) supplied via env (`LICENSE_TOKEN_PRIVATE_KEY`, `LICENSE_TOKEN_PUBLIC_KEY`, base64; `LICENSE_TOKEN_KEY_ID`, `LICENSE_TOKEN_ISSUER`, `LICENSE_TOKEN_TTL_DAYS`). Generate a keypair with `php artisan licenses:keygen`.

## Domain & lifecycle

| Class | Notes |
|---|---|
| `Domain\Models\License` | `HasUuid`, `SoftDeletes`. `belongsTo` Subscription + Company; `hasMany` events + activations. `isCurrentlyValid()` (status + expiry). |
| `Domain\Models\LicenseActivation` | `HasUuid`. One row per `(license, identifier)`; `isActive()` = not revoked. Reactivated in place when a deactivated identifier returns. |
| `Domain\Models\LicenseEvent` | Append-only ledger (constitution §6) — `const UPDATED_AT = null`; never updated/deleted. |
| `Domain\Enums\LicenseStatus` | `active` / `suspended` / `revoked` / `expired`. Only `active` (pre-expiry) entitles. |
| `Domain\Enums\LicenseEventType` | `issued` / `renewed` / `suspended` / `reactivated` / `revoked` / `expired` / `activated` / `deactivated` / `token_issued`. |
| `Domain\Contracts\OfflineTokenSigner` | Signs the offline JWS and publishes the JWK. Impl `Infrastructure\Signing\SodiumOfflineTokenSigner` (EdDSA/Ed25519 via ext-sodium, ADR 0005). |
| `Application\Services\OfflineTokenService` | Builds the claim set, clamps `exp` to the license expiry, signs, and records the ledger event. Returns `Application\DTO\IssuedOfflineToken`. |
| `Application\Services\LicenseService` | `issueForSubscription` / `syncForSubscription` (idempotent issue-or-extend) / suspend / reactivate / revoke / `activate` / `deactivate` / `expireDue()`; product-facing `resolveForProduct` (key → license, enforcing product ownership in SQL) and `heartbeat` (validation check-in). Every state change appends to the ledger; the `activate`/`deactivate` actor may be a user or a `product`. |
| `Application\DTO\LicenseValidationResult` | Product-facing result (license + optional activation) rendered by `ProductLicenseResource`. |
| `Application\Support\LicenseKeyGenerator` | Unique `EVO-XXXX-XXXX-XXXX-XXXX` keys from an unambiguous alphabet (no `0/O`, `1/I`). |
| `Application\Listeners\IssueLicenseOnActivation` | Listens for `Subscriptions\...\SubscriptionActivated` → `syncForSubscription`. |
| `Console\ExpireLicensesCommand` | `php artisan licenses:expire` — **scheduled daily** (module `Routes/console.php`) to mark past-due active licenses expired. |
| `Console\GenerateSigningKeyCommand` | `php artisan licenses:keygen` — generates an Ed25519 keypair and prints the env lines for offline-token signing (ADR 0005). |

### Activation rules
- **Idempotent per identifier:** re-activating an already-active identifier just refreshes `last_seen_at`; re-activating a previously deactivated one reactivates its existing row.
- **Limit enforced:** a new slot is refused (`422 VALIDATION_FAILED`) once `max_activations` active activations exist. Deactivating frees a slot.
- **Requires a usable license:** activating a suspended/revoked/expired license is refused (`422`).

## Tests

- `LicenseLifecycleTest` — auto-issue on subscription activation, renewal extends the same license, manual issue, suspend/reactivate/revoke transitions, revoked-not-resurrected, the expire command, enriched list payload, key uniqueness.
- `LicenseActivationTest` — auth guard, activate (device/domain), idempotency, limit enforcement, deactivate frees a slot, reactivation reuses the row, non-active license refusal, cross-license scoping, and `activations_used` on show.
- `ProductLicenseTest` — the `product` guard (accept valid key, reject missing/invalid/revoked/expired, `X-Api-Key` header), product-owns-license isolation (`404` across products), self-activation + limit + expired-license refusal, online validation + heartbeat, revoked-license reported invalid, and product self-deactivation.
- `OfflineTokenTest` — offline token issuance and **signature verification with the public key** (the offline promise), `exp` clamped to license expiry, refusal for an unactivated device / non-active license, cross-product `404`, the public keys endpoint, and tamper rejection.
