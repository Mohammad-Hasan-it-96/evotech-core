# ADR 0002 — Module Layout & Autoloading

- **Status:** Accepted
- **Date:** 2026-07-05
- **Deciders:** Chief Software Architect
- **Related:** `docs/ARCHITECTURE.md` §4 (Modular Design), §18 (Amendment Process)

## Context

The constitution (§4) specifies each module under `modules/<Module>/` with a `src/` wrapper holding the 4 layers, and PSR-4 namespace `Modules\<Module>\...`. Implementing this literally means either:

- a **per-module** composer PSR-4 entry (`"Modules\\Core\\": "modules/Core/src/"`) that must be added and `composer dump-autoload`-ed for **every new module**, or
- a custom autoloader.

Both add friction to the "a new engineer adds a module in minutes" goal, and the `src/` level adds nesting with no functional benefit in a PSR-4 world.

## Decision

Refine the physical layout (namespaces and the 4-layer separation are unchanged):

1. **Drop the `src/` wrapper.** Layers live directly under the module root.
2. **Single PSR-4 mapping:** `"Modules\\": "modules/"` in `composer.json`. So `Modules\Core\Domain\Foo` → `modules/Core/Domain/Foo.php` with **zero composer changes per new module**.
3. **Auto-discovery:** a thin `App\Providers\ModuleServiceProvider` (registered in `bootstrap/providers.php`) scans `modules/*` and registers each module's `Modules\<Name>\Providers\<Name>ServiceProvider`. Modules self-wire their routes/migrations/translations via a shared `BaseModuleServiceProvider` in the `Core` module.

### Canonical module layout (supersedes §4's `src/` block)

```
modules/<Module>/
├── Providers/          <Module>ServiceProvider (extends Core BaseModuleServiceProvider)
├── Http/               Controllers, Requests, Resources, Middleware
├── Application/        Services (use-cases), DTOs, Jobs, Listeners
├── Domain/             Models, Enums, ValueObjects, Events, Contracts (interfaces)
├── Infrastructure/     Repository implementations, external clients
├── Console/            Artisan commands
├── Database/           Migrations, Factories, Seeders
├── Routes/             api.php, web.php, console.php
├── Lang/               translations
└── Tests/              Feature, Unit
```

Namespace root `Modules\<Module>\` maps to `modules/<Module>/`.

## Consequences

**Positive**
- Adding a module = create the folder + a 5-line service provider. No composer edits, no manual provider registration.
- Less nesting; import paths match folders 1:1.
- Auto-discovery keeps `bootstrap/providers.php` to a single line.

**Negative / Risks**
- `glob` over `modules/*` on boot has a negligible cost; if it ever matters, the discovered provider list can be cached (mirroring Laravel's package manifest).

## Note

`ARCHITECTURE.md` §4 is updated to reference this ADR; the 4-layer separation, contract-only cross-module communication, and event-driven boundaries from §2/§4 remain fully in force. Only the on-disk folder shape and autoloading mechanism changed.
