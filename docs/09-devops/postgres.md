# PostgreSQL

## Local

`docker compose up -d postgres` — PostgreSQL 17, databases `autowave` (app) and `autowave_testing` (tests),
user `autowave` / password `secret`.

```bash
docker compose exec postgres psql -U autowave -d autowave
php artisan db:show
php artisan db:table users
```

## Production (draft, AW-004)

- PostgreSQL 17 on the VPS (move to DigitalOcean Managed PostgreSQL when load or HA needs justify it).
- Listen on localhost only; dedicated role `autowave` owning database `autowave`; strong password.
- Tune `shared_buffers` (~25% RAM), `work_mem`, `effective_cache_size`; enable `log_min_duration_statement = 500` for slow queries.
- Extensions likely needed: `btree_gist` (booking overlap constraints), `pg_trgm` (search) — add via migrations.
- Backups: `docs/11-runbooks/backup.md`; restore: `docs/11-runbooks/restore-database.md`.
