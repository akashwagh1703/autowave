# Database Schema

Engine: PostgreSQL (local Docker 17; shared dev server currently **11.20** — migrations stay PG11-compatible,
see AW-006). Timestamps in UTC. Update this file with every migration.

## Laravel defaults (Phase 0)

| Table | Migration | Purpose |
|---|---|---|
| `users` | `0001_01_01_000000_create_users_table` (+ `2026_09_27_150000`) | Accounts: name, email unique, password, `status` (`active\|suspended`, indexed), `is_platform_admin`, `last_login_at` |
| `password_reset_tokens` | same | Password reset tokens (email PK) |
| `sessions` | same | Database-backed sessions |
| `cache`, `cache_locks` | `0001_01_01_000001` | Database cache fallback (unused while `CACHE_STORE=redis`) |
| `jobs`, `job_batches`, `failed_jobs` | `0001_01_01_000002` | Queue tables (Redis queues; batches and failures still use DB) |

## Platform catalogue (Phase 1, `2026_09_27_150100`) — not tenant-owned

| Table | Key columns |
|---|---|
| `business_types` | `code`, `version` (unique together), `name`, `status`, `is_public`, `configuration` jsonb, `sort_order` |
| `engines` | `code` unique, `status`, `configuration` jsonb (`requires_modules`) |
| `modules` | `code` unique, `type`, `version`, `status`, `configuration` jsonb |
| `module_dependencies` | PK (`module_id`, `depends_on_module_id`) |
| `business_type_engines` | PK (`business_type_id`, `engine_id`) |
| `business_type_modules` | PK (`business_type_id`, `module_id`), `enabled`, `configuration` |

## Tenancy (Phase 1, `2026_09_27_150200`)

| Table | Key columns |
|---|---|
| `tenants` | `name`, `slug` (63, unique), `business_type_id` (restrict), `business_type_version`, `status`, `is_internal`, `timezone`, `locale`, `currency` |
| `tenant_users` | `tenant_id`, `user_id` (unique together), `status`, `joined_at`; unique (`id`, `tenant_id`) for composite FKs |
| `tenant_settings` | `tenant_id`, `key` (unique together), `value` jsonb — tenant-scoped model |
| `tenant_engines` | `tenant_id`, `engine_id` (unique together), `enabled` |
| `tenant_modules` | `tenant_id`, `module_id` (unique together), `enabled`, `version` (pinned), `configuration` |

## RBAC (Phase 1, `2026_09_27_150300`)

| Table | Key columns |
|---|---|
| `permissions` | `key` unique, `group`, `description` |
| `roles` | `tenant_id` nullable (null = template), `slug`, `name`, `grants_all`, `is_locked`; unique (`tenant_id`, `slug`), unique (`id`, `tenant_id`), partial unique `roles_template_slug_unique (slug) WHERE tenant_id IS NULL` |
| `role_permissions` | PK (`role_id`, `permission_id`) |
| `user_roles` | `tenant_id`, `tenant_user_id`, `role_id`; composite FKs `(tenant_user_id, tenant_id) → tenant_users(id, tenant_id)` and `(role_id, tenant_id) → roles(id, tenant_id)`; unique (`tenant_user_id`, `role_id`) |

## Domains (Phase 1, `2026_09_27_150400`)

`domains`: `tenant_id`, `domain` (253, unique, normalised lowercase), `type` (`subdomain|custom`), `is_primary`,
`status` (`pending|active|disabled`), `verified_at`, `ssl_status` (`pending|active|failed`).
Partial unique `domains_one_primary_per_tenant (tenant_id) WHERE is_primary`.

## Audit (Phase 1, `2026_09_27_150500`)

`audit_logs`: `tenant_id` (nullable, null on delete), `user_id` (nullable), `action`, `subject_type/subject_id`,
`metadata` jsonb, `ip_address`, `user_agent`, `created_at`. Append-only (no `updated_at`).
Current actions: `admin.login`, `admin.login_failed`, `admin.logout`, `tenant.suspended`, `tenant.activated`.

## Deferred platform tables

`feature_flags`, `custom_fields` — added with the first feature that needs them (AW-009).

## Planned domain tables (Phases 3–8)

See `docs/01-product/master-prompt.md` §57. Implement only what the current phase needs.
