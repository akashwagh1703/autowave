# Database Schema

Engine: PostgreSQL 17. Timestamps in UTC. Update this file with every migration.

## Current tables (Phase 0 — Laravel defaults)

| Table | Migration | Purpose |
|---|---|---|
| `users` | `0001_01_01_000000_create_users_table` | Platform user accounts (id, name, email unique, email_verified_at, password, remember_token, timestamps) |
| `password_reset_tokens` | same | Password reset tokens (email PK) |
| `sessions` | same | Database-backed sessions (`SESSION_DRIVER=database`) |
| `cache`, `cache_locks` | `0001_01_01_000001_create_cache_table` | Database cache store (unused while `CACHE_STORE=redis`; kept for fallback) |
| `jobs`, `job_batches` | `0001_01_01_000002_create_jobs_table` | Database queue (unused while `QUEUE_CONNECTION=redis`); `job_batches` is used by Bus batching |
| `failed_jobs` | same | Failed queued jobs (used with Redis queues too) |

No tenant-owned tables exist yet.

## Planned platform tables (Phase 1)

`tenants`, `tenant_users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `business_types`, `engines`,
`modules`, `module_dependencies`, `business_type_engines`, `business_type_modules`, `tenant_engines`,
`tenant_modules`, `domains`, `tenant_settings`, `feature_flags`, `custom_fields`, `audit_logs`.

## Planned domain tables (Phases 3–8)

See `docs/01-product/master-prompt.md` §57. Implement only what the current phase needs.
