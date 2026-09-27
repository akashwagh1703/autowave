# Tenant Isolation (Database)

> Status: design. Enforcement code arrives in Phase 1.

## Rules

1. Every tenant-owned table has `tenant_id bigint not null references tenants(id)`.
2. Composite indexes start with `tenant_id` (e.g. `(tenant_id, status)`, `(tenant_id, created_at)`).
3. Business-unique values are unique **per tenant**: `unique (tenant_id, slug)`, `unique (tenant_id, phone)` where relevant.
4. Child tables either carry their own `tenant_id` or are only reachable through a tenant-scoped parent;
   prefer carrying `tenant_id` for query simplicity and defence in depth.
5. Models use the `BelongsToTenant` trait (global scope + auto-fill on create).
6. Platform-level tables (`business_types`, `engines`, `modules`, `permissions`) have no `tenant_id`.
   `roles.tenant_id` is nullable: `null` = platform template role.
7. Raw queries (`DB::table`, `DB::select`) bypass Eloquent scopes — they must include `tenant_id` explicitly
   and be reviewed.

## Future defence in depth

PostgreSQL Row-Level Security with `SET app.tenant_id` per connection may be added (would need an ADR).

See also [`../02-architecture/multi-tenancy.md`](../02-architecture/multi-tenancy.md) and
[`../04-security/tenant-isolation.md`](../04-security/tenant-isolation.md).
