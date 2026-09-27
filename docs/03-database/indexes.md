# Indexes

## Current

Laravel defaults: `users.email` unique, `sessions.user_id`, `sessions.last_activity`, `jobs.queue`,
`failed_jobs.uuid` unique, `cache.expiration`, `cache_locks.expiration`.

Phase 1:

| Index | Serves |
|---|---|
| `users.status` | Admin filtering; active-user checks |
| `domains.domain` unique | Host → tenant resolution (`DomainResolver`) |
| `domains_one_primary_per_tenant` partial unique `(tenant_id) WHERE is_primary` | One primary domain per tenant |
| `domains.tenant_id` | Tenant's domains; cache flush on suspend |
| `tenants.slug` unique, `tenants.status` | Slug availability; admin filter |
| `tenant_users (tenant_id, user_id)` unique | Membership lookup per tenant |
| `tenant_users (user_id, status)` | "My businesses" / active membership per request |
| `tenant_users (id, tenant_id)` unique, `roles (id, tenant_id)` unique | Targets of the `user_roles` composite FKs |
| `roles (tenant_id, slug)` unique + `roles_template_slug_unique (slug) WHERE tenant_id IS NULL` | Role lookup; unique template slugs on PG11 |
| `user_roles (tenant_user_id, role_id)` unique | Membership → roles |
| `tenant_settings (tenant_id, key)` unique | `TenantContext::setting()` |
| `tenant_modules / tenant_engines (tenant_id, …)` unique + FK-side indexes | Enabled modules/engines per tenant |
| `business_types (code, version)` unique | Versioned presets |
| `audit_logs (tenant_id, created_at)`, `audit_logs.action`, morph index | Audit timelines and filters |

## Guidelines

Review indexes for:

- `tenant_id` (leading column on tenant-owned tables)
- foreign keys (PostgreSQL does **not** index FKs automatically)
- common filters: `status`, `source`, `assigned_to`
- date ranges: `created_at`, `next_followup_at`, `starts_at`
- domain lookup: `domains.domain` unique
- booking availability: `(tenant_id, resource_id, starts_at)` plus an exclusion constraint on time ranges
  (`btree_gist`) to prevent overlaps

Record every non-trivial index here with the query it serves.
