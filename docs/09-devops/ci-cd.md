# CI/CD

## Current: CI (`.github/workflows/ci.yml`)

Runs on pushes to `main`, `master`, `develop` and on all pull requests.

| Job | Steps |
|---|---|
| Backend | PHP 8.4 → `composer install` → copy `.env.example` → `key:generate` → `pint --test` → `php artisan test` with PostgreSQL 17 + Redis 7 service containers |
| Frontend | Node 22 → `npm ci` → `npm run build` |

## Target pipeline (not implemented)

```text
Push → Lint → Tests → Build → Deploy (SSH, atomic release) → Migrations → Worker restart
```

- `develop` → staging automatically; `master` (later `main`, AW-002) → production (manual approval via
  GitHub Environments).
- Secrets (SSH key, host) stored as GitHub Environment secrets — never in the repo.
- The deploy step only needs to SSH in and run
  `sudo -iu autowave /var/www/autowave-platform/deploy.sh` ([deployment.md](deployment.md)); until then,
  deploys are run by hand the same way.
