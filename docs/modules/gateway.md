# Module: Gateway

The **product-to-platform edge** (constitution §4). It owns the credential and the
guard that let a real EVOTECH product (POS, ERP, IoT gateway, …) authenticate to
the platform machine-to-machine, and publishes a contract so other modules read
the authenticated product without touching Gateway's internals.

Authentication mechanism: **per-product hashed API keys** — see
[ADR 0004](../adr/0004-product-to-platform-auth-api-keys.md), which supersedes the
constitution's original Passport-OAuth2 choice for this audience.

## How a product authenticates

1. Staff mint an API key for a `Product` (endpoints below). The **plaintext token**
   (`evo_<prefix>_<secret>`) is returned **once** and never again.
2. The product sends it on every call as `Authorization: Bearer <token>` (or
   `X-Api-Key: <token>`).
3. The **`product` guard** (`config/auth.php`, driver registered by this module via
   `Auth::viaRequest`) hashes the token, looks up the active key, records
   `last_used_at`, and authenticates the request. Product-facing routes use
   `auth:product` + `throttle:product`.
4. Downstream modules inject **`ProductContext`** to learn which product is calling.

Only a SHA-256 hash of the token is stored (`key_hash`, unique); the non-secret
`prefix` is kept for display. Keys are revocable (`revoked_at`) and optionally
expirable (`expires_at`); authentication requires a key that is neither.

## Endpoints — key management (staff, `auth:sanctum`, under `/api/v1`)

| Method | Path | Name | Description |
|---|---|---|---|
| GET | `/products/{product}/api-keys` | `products.api-keys.index` | List a product's keys (never the plaintext). |
| POST | `/products/{product}/api-keys` | `products.api-keys.store` | Mint a key — body: `name`, `expires_at?`. Returns the one-time `key`. |
| DELETE | `/product-api-keys/{apiKey}` | `product-api-keys.destroy` | Revoke a key (204). |

`{product}` is bound by slug; `{apiKey}` by uuid.

The keys authenticate the **product-facing** endpoints defined by other modules —
today, license self-activation/validation in the [Licenses](licenses.md) module.

## Domain & application

| Class | Notes |
|---|---|
| `Domain\Models\ProductApiKey` | `HasUuid`, is `Authenticatable` (the `product` guard's identity). `belongsTo` Product. `isActive()` = not revoked and not expired. `key_hash` is hidden. |
| `Domain\Contracts\ProductContext` | Published contract: `isAuthenticated()`, `productId()`, `productSlug()` for the current request. Consumers depend on this, **not** on `ProductApiKey` (§2.1). |
| `Infrastructure\Auth\RequestProductContext` | Request-scoped implementation backed by the `product` guard. |
| `Application\Services\ProductApiKeyService` | `mint` (one-time plaintext), `authenticate` (token → active key, touches `last_used_at`), `revoke`, `forProduct`. |
| `Application\Support\ApiKeyGenerator` | Generates `evo_<prefix>_<secret>` and its SHA-256 hash. |
| `Providers\GatewayServiceProvider` | Registers the `product-api-key` guard driver and binds `ProductContext`. |

## Security notes

- The plaintext is shown once; lost keys are **rotated** (mint new + revoke old),
  never recovered.
- A product may act **only on resources belonging to its own product**; that
  ownership check is enforced by the consuming module (e.g. Licenses resolves a
  license only if it belongs to the authenticated product, else `404`).
- Minting/revoking are security-relevant staff actions and are rate-limited by the
  standard `auth:sanctum` + `api` limiters; the product edge has its own
  `product` limiter (§6.13), keyed per API key.

## Tests

- `ProductApiKeyManagementTest` — auth guard on management, one-time plaintext +
  hashed-at-rest storage, per-product scoping of listings, revocation, future-only
  expiry validation.
- Guard behaviour (accept valid, reject missing/invalid/revoked/expired, `X-Api-Key`
  header) is covered end-to-end by the Licenses module's `ProductLicenseTest`.
