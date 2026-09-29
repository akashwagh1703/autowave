# PostgreSQL

## Local

`docker compose up -d postgres` — PostgreSQL 17, databases `autowave` (app) and `autowave_testing` (tests),
user `autowave` / password `secret`.

```bash
docker compose exec postgres psql -U autowave -d autowave
php artisan db:show
php artisan db:table users
```

## Production

- The server's PostgreSQL **16** (`postgresql@16-main`, Ubuntu package), shared with the owner's other
  projects and listening on localhost only. The app needs no extensions and supports PostgreSQL 11+.
- Database `autowave_platform`, owned by role `autowave_platform_user` (random 48-hex password, only in
  `shared/.env`). The older `autowave` database on the same server belongs to the previous micro-SaaS
  product — do not touch it.

  ```bash
  DBPASS=$(openssl rand -hex 24)
  sudo -u postgres psql -c "CREATE ROLE autowave_platform_user LOGIN PASSWORD '$DBPASS';" \
                        -c "CREATE DATABASE autowave_platform OWNER autowave_platform_user;"
  # write DBPASS into shared/.env in the same shell, never by copy-paste
  ```

- `psql -lqt` / `\l` open a pager; press `q`, or use `-P pager=off`.
- Later: tune `shared_buffers` (~25% RAM), `work_mem`, `effective_cache_size`; enable
  `log_min_duration_statement = 500`; move to DigitalOcean Managed PostgreSQL when load or HA needs it.
- Backups: [backup.md](../11-runbooks/backup.md) (not automated yet, AW-066); restore:
  [restore-database.md](../11-runbooks/restore-database.md).
