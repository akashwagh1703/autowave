# Current State

_Last updated: 2026-09-27 — end of Phase 0 (Project Initialization)._

This document describes what **actually exists** in the repository today. Planned work is in
[roadmap.md](roadmap.md).

## Implemented

- **Laravel 13 application** (framework v13.33) on PHP 8.4, stock skeleton with:
  - `SecurityHeaders` middleware (global)
  - `HandleInertiaRequests` middleware (shares `app.name`, `auth.user`, `flash`)
  - `php artisan autowave:health` — checks PostgreSQL, Redis and cache
  - Health endpoint `GET /up`
- **Frontend**: Inertia.js v3 + React 19 (JSX) + Vite 7 + Tailwind CSS v4 + MUI v9
  - Design tokens (`resources/js/theme/tokens.js`) mirrored in Tailwind `@theme`
  - MUI theme with CSS layers so Tailwind utilities override MUI
  - One page: `Welcome` (placeholder marketing page at `/`)
- **Database**: PostgreSQL 17 (local via Docker). Only Laravel's default tables exist:
  `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`.
- **Redis 7**: cache store and queue connection.
- **Tests**: PHPUnit on PostgreSQL (`autowave_testing`) — 5 foundation tests.
- **CI**: GitHub Actions workflow — Pint, tests (PostgreSQL + Redis services), frontend build.
- **Documentation**: `AGENTS.md`, `.cursor/rules/`, `docs/` structure, ADR-001 → ADR-009.

## In progress

- Nothing. Phase 0 is complete and awaiting approval before Phase 1.

## Not implemented

Everything product-specific, including: authentication screens, tenants, tenant context/resolution,
tenant membership, RBAC, business types, engines, modules, domains, onboarding, CRM, leads, services,
booking, commerce, website engine, automation, messaging, AI, analytics, Super Admin, billing.

The `app/Domain/` directory does not exist yet; it is created with the first domain in Phase 1.

## Known technical debt

See [known-issues.md](known-issues.md) (AW-001 → AW-005).
