# Production Architecture

> Draft — not yet validated on a real server (AW-004).

## Topology (single DigitalOcean VPS to start)

```text
Internet
   ↓  (DNS: autowave.in, *.autowave.in, custom domains → VPS IP)
Nginx (TLS termination, wildcard vhost)
   ↓
PHP-FPM 8.4 → Laravel (/var/www/autowave/current)
                ├── PostgreSQL 17 (local, later Managed DB)
                ├── Redis 7 (local, AOF enabled, password)
                ├── Queue workers (Supervisor)
                └── Scheduler (cron: * * * * * php artisan schedule:run)
```

## Server baseline

- Ubuntu 24.04 LTS, non-root deploy user `autowave`, SSH keys only, UFW (22, 80, 443), fail2ban, unattended security upgrades.
- Packages: nginx, php8.4-fpm (+ pgsql, mbstring, intl, xml, curl, zip, gd, bcmath, redis), postgresql-17, redis-server, supervisor, certbot, git, Node 22 (build only).
- Directory layout (atomic releases):

```text
/var/www/autowave/
├── releases/<timestamp>/
├── shared/.env
├── shared/storage/
└── current -> releases/<timestamp>
```

## Components

- [nginx.md](nginx.md) · [supervisor.md](supervisor.md) · [redis.md](redis.md) · [postgres.md](postgres.md) · [ssl.md](ssl.md)
- Deployment: [deployment.md](deployment.md) · CI/CD: [ci-cd.md](ci-cd.md) · Staging: [staging.md](staging.md)

## Production `.env` essentials

`APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, strong `DB_PASSWORD` and `REDIS_PASSWORD`,
`LOG_LEVEL=warning`. See [environment.md](environment.md).

## Backups

Daily off-server PostgreSQL backups with retention and tested restores — see `docs/11-runbooks/backup.md`.
Never keep the only backup on the same VPS.
