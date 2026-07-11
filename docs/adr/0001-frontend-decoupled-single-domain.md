# ADR 0001 — Decoupled Frontend on a Single Domain

- **Status:** Accepted
- **Date:** 2026-07-03
- **Deciders:** Founder/CTO, Chief Software Architect
- **Related:** `docs/ARCHITECTURE.md` §8 (Frontend), §2 (Architecture), `docs/ROADMAP.md`

## Context

The constitution (`ARCHITECTURE.md` §8) mandates a **decoupled Next.js frontend** consuming the Laravel REST API, ideally in a separate repository (`evotech-web`). The founder raised a practical constraint: he does **not** want to purchase or pay for more than one domain, and all hosting must run on his existing **Contabo VPS** (4 vCPU / 8GB RAM / 150GB SSD) with **no additional cloud cost** (no Vercel). The VPS already runs a sold restaurant system that must not be disrupted.

We needed to confirm the decoupled architecture is compatible with these constraints, and decide the repo/domain/hosting topology.

## Decision

1. **Keep the decoupled architecture.** `evotech-core` stays a Laravel **API-only** backend; the frontend is a separate **Next.js 15** application (`evotech-web`).
2. **One domain, multiple subdomains.** A single purchased domain yields free subdomains:
   - `evotech.<domain>` → marketing website (Next.js)
   - `api.evotech.<domain>` → Laravel API
   - `app.evotech.<domain>` → subscriptions dashboard (Next.js)
3. **Website + Dashboard share one Next.js repo** (`evotech-web`) via App Router **route groups** (`(marketing)`, `(dashboard)`) — one design system and one deployment, separate routes. This trims the constitution's "separate repo per app" to the minimum repo count sensible at this stage while preserving decoupling.
4. **Self-host on the Contabo VPS.** Next.js runs via `next build` + **PM2**, behind **Nginx** reverse-proxy server blocks per subdomain, with **Let's Encrypt/certbot** TLS. EVOTECH services bind to distinct internal ports (e.g. 3001) and separate Nginx server blocks so the existing restaurant system is untouched.

## Consequences

**Positive**
- Zero extra domain/hosting cost; full ownership of infrastructure.
- Decoupling preserved → the API contract stays the single integration point for all future products (mobile, desktop, IoT).
- One frontend repo/deploy for web + dashboard reduces operational overhead now; can still split later if needed.

**Negative / Risks**
- Self-hosting means we own ops (process supervision, TLS renewal, deploys) instead of a managed platform — mitigated with PM2 + certbot auto-renew and, from Phase 2, Docker Compose.
- Co-tenancy with the live restaurant system on one VPS requires careful port/Nginx isolation and resource monitoring (8GB RAM is ample for the current scope but must be watched as the API + PostgreSQL + Redis arrive in Phase 2).

## Alternatives Considered

- **Next.js full-stack (drop Laravel):** simpler single stack, but abandons the constitution's API-first, multi-product backend and the PHP/Laravel investment. Rejected.
- **Single Laravel app with Blade/Inertia:** one repo/deploy, but violates the decoupled, multi-client mandate (§8). Rejected.
- **Two separate frontend repos (web + dashboard):** maximal separation but unnecessary repo/deploy overhead at this stage. Deferred — may revisit if teams diverge.
- **Managed hosting (Vercel):** best DX, but adds recurring cost the founder explicitly declined. Rejected in favor of the owned VPS.
