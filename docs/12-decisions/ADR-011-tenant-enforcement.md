# ADR-011: Tenant enforcement — host routing, fail-closed scope, tenant-safe RBAC keys

- **Status:** Accepted
- **Date:** 2026-09-27

## Context

ADR-002 chose shared tables with `tenant_id`; ADR-007 chose host-based resolution. Phase 1 had to
decide how those are enforced so that a missing check fails safely rather than leaking data.

## Decision

1. **Host routing.** `bootstrap/app.php` binds route groups to the admin, app and marketing hosts, redirects
   `www`, and registers the domain-less tenant-site group **last**. Any other host goes through
   `ResolveTenantFromDomain`, which 404s unless the host maps to an *active* domain of an *active* tenant.
2. **Business app tenant = membership.** `ResolveTenantFromMembership` re-validates the session's
   preferred tenant against an active membership in an active tenant on every request. The session value
   is only a preference; tampering falls back to the user's own tenant.
3. **`TenantContext` is a scoped singleton**, reset per request/job, and pushes `tenant_id` into Laravel's
   `Context` (logs, queued jobs).
4. **Fail-closed global scope.** `BelongsToTenant` adds `TenantScope`; without a tenant it applies
   `1 = 0`, so forgotten context returns nothing. On create it fills `tenant_id` from context, throws
   `MissingTenantContext` if none, and throws `CrossTenantWrite` for a foreign `tenant_id`; changing
   `tenant_id` later always throws.
5. **Tenancy infrastructure tables** (`tenant_users`, `tenant_modules`, `tenant_engines`, `domains`) are
   not globally scoped because platform code must query across tenants (membership lookup, domain
   resolution). They are always filtered by an explicit `tenant_id`.
6. **RBAC integrity in the database.** `tenant_users` and `roles` have `UNIQUE (id, tenant_id)`;
   `user_roles` references them with composite foreign keys `(tenant_user_id, tenant_id)` and
   `(role_id, tenant_id)`, so a role from tenant B can never be attached to a membership in tenant A,
   even by buggy code.
7. **PostgreSQL 11 compatibility** (current shared dev server): partial unique indexes instead of
   `NULLS NOT DISTINCT` (template role slugs; one primary domain per tenant).

## Alternatives

- **Open scope when no tenant** (common in packages) — a forgotten context leaks every tenant's rows.
- **stancl/tenancy** — powerful but opinionated (bootstrappers, events) and heavier than we need for
  single-database tenancy; our rules are small and fully tested.
- **PostgreSQL row-level security** — strongest guarantee, but complicates pooling, migrations and
  platform queries. Reconsider if the team grows or compliance requires it.

## Consequences

- Platform code that must see all tenants calls `Model::withoutTenantScope()`; every use is reviewed.
- Every new tenant-owned model uses `BelongsToTenant` and adds cases to `TenantIsolationTest`.
- Upgrading to PostgreSQL 15+ allows `NULLS NOT DISTINCT`, but the partial indexes remain valid.
