# Current State

_Last updated: 2026-09-27 — end of Phase 1 (Platform Foundation)._

This document describes what **actually exists** in the repository today. Planned work is in
[roadmap.md](roadmap.md).

## Implemented

### Phase 0 — foundation
- Laravel 13 (PHP 8.4), Inertia v3 + React 19 (JSX), Vite 7, Tailwind v4, MUI v9 (CSS layers), PostgreSQL,
  Redis (cache + queue), `autowave:health`, `/up`, security headers, CI, docs/ADRs.

### Phase 1 — platform foundation
- **Host-based routing** (ADR-007/011): marketing, app, admin hosts; `www` redirect; every other host is a
  tenant website resolved via `domains`.
- **Authentication** (ADR-010): Fortify on the app host — login/logout, remember me, registration, email
  verification, password reset, suspended-account blocking, `last_login_at`. Separate Super Admin login.
- **Tenancy**: `tenants`, `TenantContext` (scoped), fail-closed `BelongsToTenant` scope with cross-tenant
  write guards, `ResolveTenantFromDomain`, `ResolveTenantFromMembership`, workspace switching.
- **Membership**: `tenant_users` with status; users can belong to several businesses.
- **RBAC**: permission catalogue + template roles from `config/rbac.php`, per-tenant role copies, composite
  FKs, `PermissionResolver`, Gates for every permission, `permissions` shared to React.
- **Catalogue**: business types (versioned presets: beauty_salon, turf, coaching, cafe, clinic, local_store,
  autowave_internal), engines, modules with dependencies; `ModuleManager` / `EngineManager`.
- **Domains**: `domains` table, `DomainResolver` with cache, default `{slug}.{root}` subdomain.
- **CreateTenant** action provisioning roles, modules, engines, settings and domain atomically.
- **Audit log** (`audit_logs`) for admin login and tenant suspend/activate.
- **Super Admin**: dashboard (stats, business types) and tenant list with search, filter, suspend/activate.
- **UI**: auth pages, business dashboard (business-type widgets as placeholders), workspaces, read-only
  settings, admin pages, tenant website placeholder, error page.
- **Seeders**: catalogue, RBAC, platform admin, AutoWave Internal tenant, local demo tenants.
- **Tests**: 76 feature tests (auth, isolation, domains, membership, RBAC, modules, tenant creation, admin).
- **Shared remote dev DB** (PostgreSQL 11.20; host in the team's private `.env`) migrated and seeded.

## In progress

- Nothing. Phase 1 is complete and awaiting approval before Phase 2 (one-click onboarding).

## Not implemented

Onboarding UI, invitations/member management, role editor, editable settings, custom domain UI, feature
flags, custom fields, CRM/leads, services, booking, commerce, website engine, automation, messaging, AI,
analytics, billing.

## Known technical debt

See [known-issues.md](known-issues.md) (AW-001 → AW-010).
