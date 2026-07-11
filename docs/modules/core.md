# Module: Core

The **shared kernel**. Core holds the base classes and cross-cutting primitives every other module builds on. Per the constitution (§4), Core is the *only* module other modules may depend on directly.

## Responsibility

- Base API controller and the standard response **envelope** (§7).
- The `BaseModuleServiceProvider` that every module extends (auto-wires routes, migrations, translations by convention).
- Future home for shared value objects, base DTOs, common exceptions and contracts.

## How modules are loaded (ADR 0002)

`App\Providers\ModuleServiceProvider` (registered in `bootstrap/providers.php`) scans `modules/*` and registers each `Modules\<Name>\Providers\<Name>ServiceProvider`. Core is registered first so others can depend on its bindings.

## Public API surface

| Class | Purpose |
|---|---|
| `Modules\Core\Http\Responses\ApiResponse` | Build success `{data,meta,links}` and error `{error:{code,message,details,trace_id}}` envelopes. |
| `Modules\Core\Http\Controllers\ApiController` | Base for API controllers: `ok()`, `created()`, `noContent()`. |
| `Modules\Core\Providers\BaseModuleServiceProvider` | Base module provider; implement `moduleName()`, optionally `bootModule()`. |

## Endpoints

| Method | Path | Name | Description |
|---|---|---|---|
| GET | `/api/v1/health` | `api.v1.health` | Liveness + build info in the standard envelope. |

## Adding a new module (the recipe)

1. Create `modules/<Name>/` with the canonical folders (see ADR 0002).
2. Add `modules/<Name>/Providers/<Name>ServiceProvider.php` extending `BaseModuleServiceProvider` and returning the module name from `moduleName()`.
3. Add routes in `modules/<Name>/Routes/api.php` (wrap in `Route::prefix('api/v1')`), migrations in `Database/Migrations`, etc.
4. That's it — no `composer.json` or `bootstrap` edits (single `Modules\ → modules/` PSR-4 mapping + auto-discovery). Run `composer dump-autoload` only when adding brand-new top-level namespaces.

## Rate limiting

All `/api/*` routes run through the `api` middleware group with `throttle:api` (the `api` limiter is defined in `App\Providers\AppServiceProvider`, default 60/min per user or IP). Responses carry `X-RateLimit-*` headers.
