# Module: Users

Owns the **platform user** identity and profile. Authentication behaviour lives in the [Auth](auth.md) module; this module owns the `User` model and how a user is represented.

## Domain

| Class | Purpose |
|---|---|
| `Modules\Users\Domain\Models\User` | The user Eloquent model. Uses `HasApiTokens` (Sanctum), `HasUuid` (Core), `Notifiable`. Hybrid identifier: bigint `id` internally, `uuid` (UUIDv7) publicly. |
| `Modules\Users\Http\Resources\UserResource` | API representation. Exposes `uuid` as `id` — the numeric PK is never sent over the wire. |
| `Modules\Users\Database\Factories\UserFactory` | Test/demo data. Default password is `password`. |

## Notes

- `config/auth.php` points the `users` provider at this model (`Modules\Users\Domain\Models\User`).
- The `uuid` column is added to the framework's `users` table by `Database/Migrations/2026_07_05_000001_add_uuid_to_users_table.php` and auto-populated on create by Core's `HasUuid`.
- Profile/preferences endpoints will be added here as the platform grows; for now the module provides the identity the Auth module authenticates.
