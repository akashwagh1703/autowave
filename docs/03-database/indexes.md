# Indexes

## Current

Laravel defaults only: `users.email` unique, `sessions.user_id`, `sessions.last_activity`, `jobs.queue`,
`failed_jobs.uuid` unique, `cache.expiration`, `cache_locks.expiration`.

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
