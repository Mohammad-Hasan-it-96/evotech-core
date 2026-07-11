# ADR 0004 — Product-to-platform authentication: hashed API keys (supersedes the Passport OAuth2 choice)

- **Status:** Accepted
- **Date:** 2026-07-08
- **Deciders:** Founder/CTO, Chief Software Architect
- **Related:** `docs/ARCHITECTURE.md` §6.1 (Authentication), §6.13 (Rate limiting), §7 (API), §4 (`Gateway` module); `docs/ROADMAP.md` Phase 4.

## Context

Phase 4 connects the real EVOTECH products (Restaurant POS, IoT gateways, other
backends) to the platform so they can **self-activate** and **validate** their
licenses without a staff member operating the dashboard. That requires a
machine-to-machine (M2M) authentication mechanism for the product-to-platform
edge.

The constitution (§6.1) originally specified **OAuth2 Client Credentials via
Laravel Passport** for this audience — "each product is a registered OAuth client
with scopes." Weighing it against the actual need:

- Our M2M interaction is the simplest OAuth shape: no user-delegated
  authorization, no authorization-code/PKCE flow, no third parties — a product's
  backend or device presents a static credential and calls a handful of endpoints.
  The only OAuth grant we'd use is `client_credentials`.
- Passport stands up a **full OAuth2 authorization server** (token/authorize
  endpoints, client & token tables, encryption keys to generate and rotate, a
  token-issuance round-trip on top of every call, its own migrations and upgrade
  surface across Laravel releases). That is a large, long-lived dependency and
  operational surface for a capability we can express as "hash a secret, look it
  up, check it's live."
- The roadmap and product teams already speak in terms of **"per-product API
  keys."** A hashed API key issued per product is the boring, proven, native
  solution the constitution's §0 prefers over cleverness, and it keeps the
  approved-package surface smaller (Passport is on the approved list but not yet
  installed).
- Offline/IoT entitlement is handled separately by **signed license tokens**
  (§6.1, next Phase-4 step) — not by the online auth mechanism — so we do not
  need OAuth's token machinery to serve disconnected devices either.

Passport's advantages (standardized scopes, short-lived rotating access tokens,
delegated grants) are real but not on our near-term critical path; none of the
current product-integration use cases need them.

## Decision

**Adopt per-product hashed API keys as the product-to-platform authentication
mechanism**, replacing Passport OAuth2 client-credentials in §6.1 for the
**product-to-platform / M2M** audience only. All other §6.1 audiences are
unchanged: first-party web/apps stay on **Sanctum**, and offline/IoT devices are
served by **signed license tokens** (a separate, later Phase-4 step).

Concretely:

- A new **`Gateway`** module (already named in §4 as the owner of
  "product-to-platform auth") owns the credential and the guard.
- **`ProductApiKey`** belongs to a `Product`. A key's plaintext has the shape
  `evo_<public-prefix>_<secret>`; only a **SHA-256 hash** of the full token is
  stored (`key_hash`, unique), alongside a non-secret `prefix` for display.
  The plaintext is returned **once** at creation and never again — lost keys are
  rotated, not recovered.
- A key may carry an optional `expires_at`, and is revocable (`revoked_at`).
  Authentication requires a key that is neither expired nor revoked.
- Authentication is a **request guard** (`Auth::viaRequest('product-api-key')`,
  guard name `product`) reading the token from the `Authorization: Bearer` header
  or `X-Api-Key`. On success it records `last_used_at`. Product-facing routes use
  `auth:product`.
- A **`ProductContext`** contract (published by `Gateway`) exposes the
  authenticated product's identity (id/slug) to other modules, so consumers never
  touch `Gateway`'s Eloquent models directly (§2.1).
- **Authorization boundary:** a product may only act on licenses that belong to
  **its own** product (resolved via `license → subscription → plan → product`).
  A mismatch returns `404` (existence is not leaked across products).
- A **`product`** named rate limiter (§6.13) throttles the product edge per API
  key.
- Product API keys are minted/revoked by staff via admin endpoints
  (`auth:sanctum`); minting is an audited, security-relevant action (§6.14).

## Consequences

**Positive**
- Minimal, explicit, native implementation; no OAuth2 authorization-server to
  operate, key-manage, or track across Laravel upgrades.
- One fewer heavyweight dependency; smaller attack and maintenance surface for a
  Commandment-#1 (security) edge.
- Credentials are opaque, hashed at rest, revocable, expirable, and rate-limited;
  the product↔license ownership check is enforced and tested.
- Fits the roadmap's language and the product teams' mental model directly.

**Negative / Risks**
- We forgo OAuth2's standardized **scopes** and **short-lived rotating tokens**.
  Mitigation: keys are per-product and revocable; if fine-grained scopes or token
  rotation become necessary, this ADR is superseded by re-introducing Passport
  (still on the approved list) behind the same `ProductContext` seam — a swap, not
  a rewrite.
- Static credentials must be handled carefully by product teams (store as a
  secret, never ship in client-visible code). Documented in the module guide.
- We own the crypto choices (SHA-256 over a high-entropy random secret,
  constant-time comparison via hash lookup). Low risk given the token is a
  256-bit random secret, not a password.

## Amendment to the constitution

`ARCHITECTURE.md` §6.1's product-to-platform / M2M bullet ("OAuth2 Client
Credentials via Laravel Passport") is updated to reference this ADR: the
mechanism for that audience is **per-product hashed API keys**, owned by the
`Gateway` module. The layered-by-audience principle of §6.1 is otherwise
unchanged (Sanctum for first-party, signed tokens for offline/IoT). Passport
remains an approved package and the pre-approved fallback should standardized
OAuth2 scopes later be required.
