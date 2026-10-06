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
| 2026-10-01 | `2026_10_01_100000` product categories, products, orders, order items, order payments, stock movements, `activities.order_id` | 7 | Check constraints for stock and totals; partial unique SKU index (PG11-safe, ADR-017). `products.image_media_id` is a single-column FK (null on delete). Re-seeding (`TenantBackfillSeeder`) adds the two order automation templates; `DemoCommerceSeeder` adds demo products and orders locally |
| 2026-10-02 | `2026_10_02_100000` messaging channels, conversations, conversation messages, message templates, webhook calls; `outbound_messages` conversation, template, schedule and receipt columns | 8 | Partial unique `conversation_messages_provider_unique` and partial index `conversations_unread_index` (PG11-safe, ADR-018). Additive to `outbound_messages` (nullable columns). Re-seeding (`TenantBackfillSeeder`) grants the new `conversations` permission group; `DemoMessagingSeeder` adds a demo inbox locally |
| 2026-10-03 | `2026_10_03_100000` AI usage and AI results | 9 | New tables only. Re-seeding `PlatformSeeder` adds the `ai` module and permission group; `TenantBackfillSeeder` grants `ai.*` to template-based roles and switches the `ai` module on once per existing tenant (tenant setting `modules_backfilled`) |
| 2026-10-04 | `2026_10_04_100000` resource rates, `appointments.amount_paid`, appointment payments; `100100` education tables; `100200` dining tables, reservations, food columns on products/orders/order items; `100300` coupons and `orders.coupon_id`/`coupon_code` | 10 | Exclusion constraint `reservations_no_overlap` (PG11-safe, same trick as appointments). `orders.customer_id` becomes nullable with a CHECK (required unless dine-in); the down migration deletes customer-less orders before restoring NOT NULL. Re-seeding `PlatformSeeder` adds the `education`/`food` engines, updates business types in place (Local Commerce rename, cafe and coaching engines) and the new permission groups; `TenantBackfillSeeder` grants `courses`, `students`, `fees`, `reservations` to template-based roles and switches `offers` on once. `DemoEducationSeeder`, `DemoFoodSeeder` and the extended demo seeders add ABC Coaching, ABC Cafe and ABC Store locally |
| 2026-10-07 | `2026_10_07_100000` plans, subscriptions, billing payments, billing invoices | Billing A | New tables only. Partial unique indexes for one pending payment per tenant and a reference used once (PG11-safe). Run `RbacSeeder` (adds `billing.*`) and `TenantBackfillSeeder` (seeds the plans and gives every business without a subscription a fresh 14-day trial) |
| 2026-10-08 | `2026_10_08_100000` billing coupons; online payment and coupon columns on billing payments | Billing B/C | New table and nullable / defaulted columns only; safe while the previous release runs. No seeder needed |
| 2026-10-10 | `2026_10_10_100000` chatbot sessions; `outbound_messages.interactive` and `assistant` | WhatsApp assistant | New table and nullable / defaulted columns only; safe while the previous release runs. No seeder needed: the assistant is off until an owner turns it on |

## Compatibility

The shared dev server runs PostgreSQL 11 (AW-006). Until it is upgraded, do not use `NULLS NOT DISTINCT`,
generated columns, or other PG12+ features. Use partial unique indexes via `DB::statement()` instead.
