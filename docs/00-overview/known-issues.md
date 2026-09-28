# Known Issues

Every significant unresolved issue is listed here. Close an issue by changing its status to `Resolved`
with the date and commit/PR reference; do not delete it.

---

### AW-001 — Laravel Horizon not installed

- **Category:** DevOps / Technical Debt
- **Description:** Horizon requires the `pcntl`/`posix` PHP extensions, which do not exist on Windows. The
  primary development machine is Windows, so Horizon was not added in Phase 0.
- **Impact:** No queue dashboard or per-queue supervisor balancing yet. Queues work via `queue:work`.
- **Status:** Open
- **Workaround:** Run `php artisan queue:work redis --queue=default,automation,messaging,ai,notifications,reports,media`.
  In production use Supervisor with `queue:work` until Horizon is added.
- **Affected:** `composer.json`, `docs/09-devops/supervisor.md`
- **Created:** 2026-09-27

### AW-002 — Default Git branch is `master`; convention requires `main` + `develop`

- **Category:** DevOps
- **Description:** The GitHub repository was created with `master`. `AGENTS.md` specifies `main` and `develop`.
- **Impact:** CI triggers include `master` as a stopgap. Branch naming is inconsistent with docs.
- **Status:** Open — needs the repository owner to rename the default branch on GitHub.
- **Workaround:** Rename on GitHub (Settings → Branches), then `git branch -m master main && git fetch origin && git branch -u origin/main main`; create `develop` from `main`.
- **Affected:** Repository settings, `.github/workflows/ci.yml`
- **Created:** 2026-09-27

### AW-003 — No frontend linter/formatter

- **Category:** Technical Debt
- **Description:** ESLint and Prettier are not configured. Only Pint (PHP) runs in CI.
- **Impact:** JS/JSX style may drift; some bugs (unused vars, hook rules) are not caught automatically.
- **Status:** Open — add when the first real frontend module is built (Phase 1/2).
- **Workaround:** Follow `.cursor/rules/react.mdc`.
- **Affected:** `resources/js/`, `package.json`
- **Created:** 2026-09-27

### AW-004 — Production/staging/runbook docs are unvalidated drafts

- **Category:** Documentation / DevOps
- **Description:** DevOps and runbook documents were written from the target architecture before any
  server exists. Commands have not been executed against a real DigitalOcean VPS.
- **Impact:** Procedures may need corrections on first real deployment.
- **Status:** Open — validate and update during first staging deployment.
- **Workaround:** Treat as a checklist; verify each step.
- **Affected:** `docs/09-devops/*`, `docs/11-runbooks/*`
- **Created:** 2026-09-27

### AW-005 — Local PHP 8.4 is a portable install outside PATH on the primary dev machine

- **Category:** DevOps
- **Description:** The machine's default `php` is XAMPP PHP 8.2, which cannot run Laravel 13. A portable
  PHP 8.4 was installed at `C:\Users\Akash.Wagh\tools\php84` and must be put first on `PATH` per terminal.
- **Impact:** Running `php artisan` in a fresh terminal uses PHP 8.2 and fails.
- **Status:** Open — machine-specific.
- **Workaround:** `$env:Path = "C:\Users\Akash.Wagh\tools\php84;" + $env:Path` (PowerShell), or add it to the
  user PATH ahead of XAMPP. See `docs/09-devops/local-development.md`.
- **Affected:** Local development only
- **Created:** 2026-09-27

### AW-006 — Shared dev database runs PostgreSQL 11 (end-of-life)

- **Category:** DevOps / Security
- **Description:** The shared dev database server runs PostgreSQL 11.20 (EOL since Nov 2023). The target is 17.
- **Impact:** Migrations must avoid PG12+ features (`NULLS NOT DISTINCT`, generated columns, etc.). No security patches.
- **Status:** Open
- **Workaround:** Partial unique indexes are used; tests run on local PostgreSQL 17. Plan an upgrade before staging.
- **Affected:** `database/migrations/*`
- **Created:** 2026-09-27

### AW-007 — App connects to the shared dev server as the `postgres` superuser; credentials shared in chat

- **Category:** Security
- **Description:** The dev server hosts ~75 databases; AutoWave uses the superuser account whose password was
  shared in plain text during setup.
- **Impact:** A leak of the AutoWave `.env` exposes every database on that server.
- **Status:** Open — needs the server owner.
- **Workaround:** Create a dedicated role owning only `autowave` (`CREATE ROLE autowave_app LOGIN PASSWORD ...;
  ALTER DATABASE autowave OWNER TO autowave_app;`), switch `.env`, and rotate the `postgres` password.
- **Affected:** `.env` (not committed)
- **Created:** 2026-09-27

### AW-008 — Remote dev DB latency (~1.3 s per page)

- **Category:** Performance (dev only)
- **Description:** Each query round-trips to the remote server; sessions are also stored there.
- **Impact:** Slow local page loads and seeding (~1–2 minutes). Not representative of production.
- **Status:** Open
- **Workaround:** Use the local Docker database for day-to-day work when latency matters.
- **Affected:** Local development
- **Created:** 2026-09-27

### AW-009 — Feature flags and custom fields tables deferred

- **Category:** Technical Debt
- **Description:** The master prompt lists `feature_flags` and `custom_fields` as platform tables. No Phase 1
  feature needs them, so they were not created.
- **Impact:** None yet.
- **Status:** Open — add with the first feature that uses them.
- **Affected:** Database
- **Created:** 2026-09-27

### AW-010 — Tenant website page title includes the platform name

- **Category:** UI
- **Description:** The Inertia title template appends "· AutoWave" on every page, including public tenant sites.
- **Impact:** Cosmetic; tenant sites show e.g. "ABC Salon · AutoWave".
- **Status:** Open — fix with the website engine (Phase 6).
- **Affected:** `resources/js/app.jsx`
- **Created:** 2026-09-27

### AW-011 — Onboarding does not create default automations or a dashboard layout

- **Category:** Product / Technical Debt
- **Description:** Master prompt §20 lists default automations and a default dashboard among onboarding
  outputs. The automation engine does not exist yet, and the dashboard is driven by the business type's
  `dashboard_widgets` setting rather than a stored layout.
- **Impact:** New tenants have no automations until Phase 5.
- **Status:** Open — add an onboarding step to `CreateTenant` (or a `TenantCreated` listener) in Phase 5.
- **Affected:** `app/Domain/Tenant/Actions/CreateTenant.php`
- **Created:** 2026-09-27

### AW-012 — No logo upload during onboarding

- **Category:** Product
- **Description:** Branding captures colour and tagline only; `branding.logo_path` stays null.
- **Impact:** Sites show the business name as text.
- **Status:** Open — add with the media/file-security pipeline (`docs/04-security/file-security.md`).
- **Affected:** Onboarding wizard, website header
- **Created:** 2026-09-27

### AW-013 — Legacy `website_sections` tenant setting left on pre-Phase-2 tenants

- **Category:** Technical Debt (dev data only)
- **Description:** Tenants created in Phase 1 (internal + demo tenants on the dev DB) still have a
  `website_sections` row in `tenant_settings`. Nothing reads it any more; sections now live in
  `website_sections`.
- **Impact:** None functionally.
- **Status:** Open — harmless; delete the rows or `migrate:fresh --seed` the dev DB when convenient.
- **Affected:** Shared dev DB data
- **Created:** 2026-09-27

### AW-014 — Leads have no `campaign_id` yet

- **Category:** Product
- **Description:** Master prompt §25 lists a campaign on leads. Campaigns do not exist yet, so the column was
  not added; the "Campaign" lead source covers the need for now.
- **Impact:** Leads cannot be attributed to a specific campaign.
- **Status:** Open — add the column (composite FK) with the campaigns feature.
- **Affected:** `leads` table
- **Created:** 2026-09-28

### AW-015 — No kanban pipeline board

- **Category:** Product / UI
- **Description:** The pipeline is shown as stage chips with counts plus a filterable table. There is no
  drag-and-drop board.
- **Impact:** Moving stages takes a click on the lead page or a bulk action.
- **Status:** Open
- **Affected:** `resources/js/pages/business/leads/Index.jsx`
- **Created:** 2026-09-28

### AW-016 — No "own leads only" visibility

- **Category:** Product / Security
- **Description:** Anyone with `leads.view` sees every lead in the business. There is no permission such as
  `leads.view_own` to restrict sales staff to their assigned leads.
- **Impact:** Larger teams cannot hide leads between sales executives.
- **Status:** Open — add a scoped permission and a query constraint when requested.
- **Affected:** `LeadController`, `config/rbac.php`
- **Created:** 2026-09-28

### AW-017 — CRM events have no listeners yet

- **Category:** Technical Debt
- **Description:** `LeadCreated`, `LeadUpdated`, `LeadStatusChanged`, `LeadAssigned`, `LeadConverted` and
  `CustomerCreated` are dispatched (after commit) but nothing consumes them until the automation engine.
- **Impact:** No automatic follow-up messages or notifications yet.
- **Status:** Open — Phase 5 (together with AW-011).
- **Affected:** `app/Domain/Lead/Events`, `app/Domain/Customer/Events`
- **Created:** 2026-09-28

### AW-018 — Removing a membership with assigned leads fails

- **Category:** Technical Debt
- **Description:** `leads.assigned_tenant_user_id` is a composite FK without `ON DELETE SET NULL`
  (PostgreSQL 11 cannot null only one column of a composite FK). Deleting a `tenant_users` row that still has
  leads raises an FK error.
- **Impact:** None today (no member removal UI). Member management must unassign or reassign leads first.
- **Status:** Open — handle in the member-management feature.
- **Affected:** `leads` table, future member removal action
- **Created:** 2026-09-28

### AW-019 — Timelines show the latest 100 entries

- **Category:** Product
- **Description:** Lead and customer pages load the 100 most recent activities without pagination.
- **Impact:** Very active records do not show older history in the UI (data is kept).
- **Status:** Open — add "load more" when needed.
- **Affected:** `LeadController::show`, `CustomerController::show`
- **Created:** 2026-09-28

### AW-020 — Concurrent conversion of two leads with the same phone

- **Category:** Technical Debt
- **Description:** Two different leads with the same phone (one open, one reactivated or linked by email)
  converted at the same instant could both try to create a customer. The partial unique index rejects the
  second one with a database error instead of reusing the first customer.
- **Impact:** Rare; the second user sees an error and can retry, which then reuses the customer. No
  duplicate data is created.
- **Status:** Open — catch the unique violation in `ResolveLeadCustomer` and re-query if it happens in
  practice.
- **Affected:** `app/Domain/Lead/Actions/ResolveLeadCustomer.php`
- **Created:** 2026-09-28

### AW-021 — Concurrency test simulates, rather than runs, parallel bookings

- **Category:** Testing
- **Description:** Feature tests run inside one database transaction (`RefreshDatabase`), so two real
  parallel requests cannot be run. The concurrency test inserts a conflicting row between
  `BookAppointment`'s availability check and its insert. It proves the exclusion constraint and the error
  handling; the row lock (`SELECT … FOR UPDATE`) is not exercised under real parallelism.
- **Impact:** Low. The constraint is the guarantee (ADR-014); the lock only makes the friendly check race-free.
- **Status:** Open. Add a non-transactional test with two database connections (or a load test on staging)
  if booking volume grows.
- **Affected:** `tests/Feature/Booking/AppointmentBookingTest.php`
- **Created:** 2026-09-28

### AW-022 — Staff see every resource's appointments

- **Category:** Product / Security
- **Description:** `appointments.view` shows the whole calendar. "My schedule" (the `resource=mine` filter)
  only narrows the view; there is no permission such as `appointments.view_own`.
- **Impact:** Staff can see colleagues' appointments and customer names. This is normal for small salons but
  not suitable for every business.
- **Status:** Open. Add a scoped permission and query constraint when requested (same approach as AW-016).
- **Affected:** `AppointmentController`, `config/rbac.php`
- **Created:** 2026-09-28

### AW-023 — Default service categories return if a tenant deletes them all

- **Category:** Technical Debt
- **Description:** `ProvisionServiceCatalog::ensureFor()` (run by `TenantBackfillSeeder` on each deploy)
  creates the default categories whenever a service-engine tenant has none. A tenant that deliberately
  deletes every category gets the defaults back.
- **Impact:** Cosmetic; the tenant can delete them again, and services are unaffected.
- **Status:** Open. Record "catalogue provisioned" in a tenant setting (as `rbac_backfilled_groups` does)
  if it bothers users.
- **Affected:** `app/Domain/Service/Actions/ProvisionServiceCatalog.php`
- **Created:** 2026-09-28

### AW-024 — Booking events have no listeners yet; no reminders

- **Category:** Technical Debt / Product
- **Description:** `AppointmentCreated`, `AppointmentConfirmed`, `AppointmentCompleted`,
  `AppointmentCancelled`, `AppointmentNoShow` and `AppointmentRescheduled` are dispatched after commit, but
  nothing consumes them. Customers receive no confirmations or reminders.
- **Impact:** Staff must contact customers themselves (the appointment page has call and WhatsApp buttons).
- **Status:** Open. Phase 5 (automation) and messaging, together with AW-017.
- **Affected:** `app/Domain/Booking/Events`
- **Created:** 2026-09-28
