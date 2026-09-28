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

Phase 2:

| Index | Serves |
|---|---|
| `tenants.created_by_user_id` | Per-user business limit in onboarding |
| `website_templates.code` unique | Template lookup by code |
| `website_configs.tenant_id` unique | One config per tenant; site render lookup |
| `website_configs.website_template_id` | FK side (template deletion is restricted) |
| `website_sections (tenant_id, sort_order)` | Ordered section list for rendering |

Phase 3 (CRM):

| Index | Serves |
|---|---|
| `(id, tenant_id)` unique on `customers`, `lead_stages`, `lead_sources`, `leads` | Targets of the CRM composite FKs |
| `customers_tenant_phone_unique` partial unique `(tenant_id, phone_normalized)` live rows | One customer per phone; conversion/lead matching |
| `customers (tenant_id, created_at)`, `(tenant_id, name)`, `(tenant_id, email)` | Customer list sort; email matching |
| `lead_stages (tenant_id, code)` unique, `(tenant_id, sort_order)` | Stage lookup by code; ordered pipeline |
| `lead_sources (tenant_id, code)` unique | Source lookup by code (integrations) |
| `leads (tenant_id, lead_stage_id)` | Pipeline counts; stage filter; open/closed views |
| `leads (tenant_id, assigned_tenant_user_id)` | "Mine"/assignee filter; auto-assign load count |
| `leads (tenant_id, next_followup_at)` | Follow-ups due; follow-up sort; dashboard metric |
| `leads (tenant_id, created_at)` | Newest/oldest sort; "new leads (7 days)" metric |
| `leads (tenant_id, phone_normalized)` | Duplicate open-lead check; customer matching |
| `leads.lead_source_id`, `leads.customer_id`, `leads.assigned_tenant_user_id`, `leads.created_by_user_id` | FK side |
| `activities (tenant_id, lead_id, occurred_at)` | Lead timeline |
| `activities (tenant_id, customer_id, occurred_at)` | Customer timeline; backfill on conversion |
| `activities.user_id`, `customers.created_by_user_id` | FK side |

Text search uses `ILIKE '%…%'` on name/email and `LIKE '%digits%'` on `phone_normalized`; these scans
are filtered by `tenant_id` first and are fine at current volumes. Add `pg_trgm` GIN indexes
when a tenant's lead count makes the list slow.

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
