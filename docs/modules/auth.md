# Module: Auth

Authentication for the platform API. Issues **Laravel Sanctum** personal access tokens for API clients (customer portal, mobile, desktop, products). Depends on the [Users](users.md) module for the identity.

## Endpoints (all under `/api/v1/auth`)

| Method | Path | Auth | Throttle | Description |
|---|---|---|---|---|
| POST | `/register` | public | `auth` (5/min/account, 20/min/IP) | Create a user, return `{ user, token }` (201). |
| POST | `/login` | public | `auth` | Verify credentials, return `{ user, token }` (200). |
| POST | `/logout` | `auth:sanctum` | `api` | Revoke the current token (204). |
| GET | `/me` | `auth:sanctum` | `api` | Return the authenticated user. |

Success responses use the `{ data: ... }` envelope; failures use `{ error: { code, message, details, trace_id } }` (e.g. `VALIDATION_FAILED` 422, `UNAUTHENTICATED` 401).

## Internals

| Layer | Class |
|---|---|
| Application | `AuthService` (register / login / logout use-cases; the token issuer) |
| Application | `RegisterData`, `LoginData` (readonly DTOs crossing the boundary) |
| Http | `RegisterRequest`, `LoginRequest` (edge validation; **password ≥ 12 chars, letters + numbers**, §6.4) |
| Http | `RegisterController`, `LoginController`, `LogoutController`, `MeController` (single-action, thin) |

## Security notes

- Passwords are hashed via the model's `hashed` cast (bcrypt by default). Login uses a constant-time `Hash::check`.
- Register/login are throttled **per-account and per-IP** to blunt brute force (§6.13). Failed logins return a generic `VALIDATION_FAILED` (no user enumeration).
- **Phase 3:** add Sanctum **SPA cookie/session** auth for the Next.js dashboard (stateful, CSRF-protected) alongside the token auth used here. OAuth2 (Passport) client-credentials for product-to-platform comes in Phase 4.
