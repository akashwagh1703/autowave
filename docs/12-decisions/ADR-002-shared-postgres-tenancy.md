# ADR-002: Shared PostgreSQL Database with `tenant_id` Isolation

- **Status:** Accepted
- **Date:** 2026-09-27

## Context

Many small businesses share one application. Each tenant's data must be strictly isolated. Operating cost
per tenant must be low, and schema migrations must apply to all tenants at once.

## Decision

Use a single PostgreSQL database and schema. Every tenant-owned table has a non-null `tenant_id` foreign key.
Isolation is enforced server-side by a central `TenantContext`, a `BelongsToTenant` global scope, policies,
tenant-prefixed storage paths and cache keys, and mandatory cross-tenant tests.

Users relate to tenants through `tenant_users` so one user may belong to several tenants.

## Alternatives

- **Database per tenant** — strongest isolation, but expensive to operate, migrate and back up at scale.
- **Schema per tenant** — middle ground, but complicates migrations, connection handling and reporting.
- **PostgreSQL Row-Level Security** — defence in depth; may be added later on top of app-level scoping.

## Consequences

- Cheap onboarding (a tenant is a row), single migration path, easy platform analytics.
- A missing scope is a data leak ⇒ isolation tests are release-blocking.
- Indexes on tenant-owned tables must start with `tenant_id`.
