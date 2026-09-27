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
