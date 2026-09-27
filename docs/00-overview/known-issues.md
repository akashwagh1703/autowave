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
