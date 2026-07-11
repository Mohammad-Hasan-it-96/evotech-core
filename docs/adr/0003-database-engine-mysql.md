# ADR 0003 — Database Engine: MySQL (supersedes the PostgreSQL decision)

- **Status:** Accepted
- **Date:** 2026-07-05
- **Deciders:** Founder/CTO, Chief Software Architect
- **Related:** `docs/ARCHITECTURE.md` §5 (Database)

## Context

The constitution (§5) selected **PostgreSQL 16** for its JSONB, strict typing, concurrency and partitioning. That was the architect's recommendation in the abstract. In practice the founder's environment and operations point the other way:

- The **Contabo VPS already runs MySQL** (the live restaurant system uses it). Adding PostgreSQL means a second engine to install, secure, back up and monitor on the same box.
- The **local dev machine already has MySQL** (XAMPP / MariaDB 10.4) running — zero setup.
- The team is **fluent in MySQL**.

MySQL 8 (and MariaDB 10.4+) are fully capable for this platform: JSON column type, functional & composite indexes, CHECK constraints (MySQL 8.0.16+), transactional DDL is absent but not required by our workflow, and Laravel's query/schema builder supports it first-class. The JSONB/partitioning advantages of Postgres are not on our near-term critical path.

## Decision

**Adopt MySQL as the platform database engine**, replacing PostgreSQL in §5.

- **Production (Contabo):** MySQL 8.x (or the MariaDB already provisioned), `utf8mb4` / `utf8mb4_unicode_ci`.
- **Local development:** the developer's local MySQL/MariaDB (XAMPP), database `evotech_core`.
- **Automated tests:** remain on in-memory SQLite for speed; **CI additionally runs against MySQL** (Phase 2 · Step 5) to catch engine-specific issues.

Everything else in §5 stands: hybrid `bigint` PK + `uuid` (UUIDv7) public identifier, FK constraints with explicit `ON DELETE`, indexed FKs, soft deletes where history matters, immutable audit/ledger tables, forward-only migrations, split reference/demo seeders, a factory per model, and single-database shared-schema multi-tenancy via `company_id`.

## Consequences

**Positive**
- One database engine across dev, the existing restaurant system, and production — less to install, secure and operate.
- Zero local setup; the team works in familiar territory.

**Negative / Risks**
- No `JSONB` — MySQL `JSON` is functionally sufficient but indexing flexible data needs generated columns. Acceptable; revisit only if a module leans heavily on document-style data.
- **Local MariaDB 10.4 vs production MySQL 8 divergence** (JSON functions, `uuid`/default expressions, CHECK enforcement). Mitigation: keep migrations to portable Laravel schema-builder constructs, avoid engine-specific SQL, and run CI against the **production engine** so drift is caught before deploy. Standardize the production engine/version explicitly at deployment.
- Test suite runs on SQLite locally, MySQL in CI — minor divergence, covered by the CI MySQL run.

## Amendment to the constitution

`ARCHITECTURE.md` §5's "Engine: PostgreSQL 16" is updated to reference this ADR. The naming, indexing, identifier, soft-delete, audit, migration, seeder, factory and multi-tenancy rules of §5 are unchanged.
