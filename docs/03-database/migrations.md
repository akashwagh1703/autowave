# Migrations

## Rules

- Every schema change is a migration in `database/migrations/`. Never edit production schema manually.
- Never modify a migration that has run in staging/production — add a new one.
- Name migrations descriptively: `create_leads_table`, `add_next_followup_at_to_leads_table`.
- Large data backfills go in queued jobs or separate commands, not in schema migrations.
- Destructive changes (drop column/table) require a two-step release: stop using → deploy → drop later.
- Production runs `php artisan migrate --force` during deployment (see `docs/09-devops/deployment.md`).

## Commands

```bash
php artisan make:migration create_leads_table
php artisan migrate
php artisan migrate:status
php artisan migrate:rollback --step=1      # local only
php artisan migrate:fresh --seed           # local only — destroys data
```

## History

| Date | Migration(s) | Phase | Notes |
|---|---|---|---|
| 2026-09-27 | Laravel default `users`, `cache`, `jobs` migrations | 0 | Skeleton |
| 2026-09-27 | `2026_09_27_150000` → `150500`: users status/admin, catalogue, tenants, RBAC, domains, audit logs | 1 | PG11-compatible (partial unique indexes); applied to the shared dev DB |
| 2026-09-27 | `2026_09_27_160000` `tenants.created_by_user_id`; `160100` website templates/configs/sections | 2 | Applied to the shared dev DB; re-seeding backfills websites for existing tenants (`ProvisionWebsite::ensureFor`) |
| 2026-09-28 | `2026_09_28_100000` customers, lead stages/sources, leads, activities | 3 | Composite FKs, partial unique phone index (PG11-safe); applied to the shared dev DB; re-seeding backfills stages/sources (`ProvisionCrm::ensureFor`) and local demo CRM data |
| 2026-09-28 | `2026_09_28_200000` service categories, services, booking resources, working hours, time off, appointments, `activities.appointment_id` | 4 | Exclusion constraint `appointments_no_overlap` without `btree_gist` (PG11-safe, ADR-014). Re-seeding (`TenantBackfillSeeder`) backfills booking settings, service categories and the `services`/`resources` permission groups, plus local demo booking data |
| 2026-09-29 | `2026_09_29_100000` automations, automation nodes, runs, jobs, logs, outbound messages | 5 | Partial index `automation_jobs_due_index` (PG11-safe, ADR-015). Re-seeding (`TenantBackfillSeeder`) backfills the default automations once per template (`ProvisionAutomations::ensureFor`); `DemoAutomationSeeder` turns on demo automations locally |
| 2026-09-30 | `2026_09_30_100000` media table; unique (`tenant_id`, `type`) on website sections | 6 | Existing tenants already have one section per type (`ProvisionWebsite`), so the unique index applies cleanly. Re-seeding (`TenantBackfillSeeder`) grants the new `website` permission group to existing template-based roles; `DemoWebsiteSeeder` adds demo content locally. Needs `php artisan storage:link` for uploaded images |

## Compatibility

The shared dev server runs PostgreSQL 11 (AW-006). Until it is upgraded, do not use `NULLS NOT DISTINCT`,
generated columns, or other PG12+ features. Use partial unique indexes via `DB::statement()` instead.
