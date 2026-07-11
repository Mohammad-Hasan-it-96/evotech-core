# API Contract

The platform is API-first (constitution §7). This directory holds the machine-readable contract.

## The spec

- **[`openapi.json`](openapi.json)** — OpenAPI 3.1, **generated from the code** by [Scramble](https://scramble.dedoc.co) (reads routes, Form Requests, and API Resources — no annotations). It is committed to the repo and **CI fails if it drifts** from the implementation (regenerate below and commit).

Regenerate after changing any endpoint:

```bash
php artisan scramble:export --path=docs/api/openapi.json
```

## Interactive docs

Running locally, browse the UI at **`/docs/api`** and the live spec at **`/docs/api.json`** (restricted to non-production by Scramble's default gate).

## Conventions (enforced by the code)

- **Versioning:** URI prefix `/api/v1`. Breaking changes → `/api/v2`.
- **Success envelope:** `{ "data": ..., "meta"?: {...}, "links"?: {...} }`. Collections carry pagination in `meta`/`links`.
- **Error envelope:** `{ "error": { "code", "message", "details", "trace_id" } }` — machine-readable `code` (e.g. `VALIDATION_FAILED`, `UNAUTHENTICATED`, `NOT_FOUND`), built by `Modules\Core\Http\Exceptions\ApiExceptionRenderer`.
- **Auth:** Bearer tokens via Laravel Sanctum (`Authorization: Bearer <token>`); obtain one from `/api/v1/auth/login` or `/register`.
- **Rate limits:** `X-RateLimit-*` headers on every response; auth endpoints are additionally throttled per-account and per-IP.
- **Identifiers:** resources are addressed by their `uuid` (never the internal numeric id).

## Current endpoints

| Area | Endpoints |
|---|---|
| Health | `GET /api/v1/health` |
| Auth | `POST /api/v1/auth/{register,login}`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me` |
| Companies | `apiResource /api/v1/companies` |
| Customers | `apiResource /api/v1/customers` (tenant-scoped) |
