# Production

- **Last updated:** 2026-09-30 (first production deployment, AW-004)
- **Live:** <https://autowave.co.in> (marketing) · <https://app.autowave.co.in> (business app) ·
  <https://admin.autowave.co.in> (Super Admin) · `https://{slug}.autowave.co.in` (tenant websites)

## Topology

One DigitalOcean droplet (Bangalore), **shared with other projects** of the owner (the older AutoWave
micro-SaaS on PM2, playltp on PHP 8.2, a MinIO container). AutoWave uses its own folder, Linux user,
PHP-FPM pool, database, Redis databases and Nginx site, so it does not touch them.

```text
Internet
   ↓  DNS (DigitalOcean): autowave.co.in and *.autowave.co.in → 168.144.121.155
Nginx 1.24 (TLS: Let's Encrypt wildcard, sites-available/autowave-platform)
   ↓  unix:/run/php/php8.4-fpm-autowave-platform.sock
PHP-FPM 8.4, pool "autowave-platform" (user autowave) → /var/www/autowave-platform/current
                ├── PostgreSQL 16 (local, shared server) — database autowave_platform
                ├── Redis 7 (local, shared server) — DB 2 (queues), DB 3 (cache), prefix awp_
                ├── Queue worker — systemd autowave-platform-worker.service
                └── Scheduler — /etc/cron.d/autowave-platform (schedule:run every minute)
```

| Item | Value |
|---|---|
| Server | Ubuntu 24.04 LTS, 1 vCPU, 1.9 GB RAM + 2 GB swap (`/swapfile`, swappiness 10), 47 GB disk |
| Domain | `autowave.co.in` registered at GoDaddy; nameservers `ns1-3.digitalocean.com` |
| Code | GitHub `akashwagh1703/autowave`, branch `master`, read-only deploy key for user `autowave` |
| App folder | `/var/www/autowave-platform` (layout in [deployment.md](deployment.md)) |
| Secrets | `/var/www/autowave-platform/shared/.env` (600, `autowave`); `/root/.secrets/digitalocean.ini` (600, root) |

The server is small for a shared host (AW-065): FPM starts processes on demand (max 4), there is one queue
worker, and the frontend build uses swap. Resize to 2 vCPU / 4 GB before real traffic.

## Components

| Component | Config | Doc |
|---|---|---|
| Nginx | `/etc/nginx/sites-available/autowave-platform` (enabled by symlink) | [nginx.md](nginx.md) |
| TLS | `/etc/letsencrypt/live/autowave.co.in/` (DNS-01, auto-renew) | [ssl.md](ssl.md) |
| PHP-FPM | `/etc/php/8.4/fpm/pool.d/autowave-platform.conf` | below |
| Worker + scheduler | `/etc/systemd/system/autowave-platform-worker.service`, `/etc/cron.d/autowave-platform` | [queue-workers.md](queue-workers.md) |
| PostgreSQL | database `autowave_platform`, role `autowave_platform_user` | [postgres.md](postgres.md) |
| Redis | DB 2 / DB 3, prefix `awp_` | [redis.md](redis.md) |
| Deploys | `/var/www/autowave-platform/deploy.sh` | [deployment.md](deployment.md) |

### PHP-FPM pool

```ini
[autowave-platform]
user = autowave
group = autowave
listen = /run/php/php8.4-fpm-autowave-platform.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 4
pm.process_idle_timeout = 30s
pm.max_requests = 500
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size] = 20M
```

The default `www` pool of PHP 8.4 was switched to `pm = ondemand` (unused, saves memory).

## Production `.env`

Differences from `.env.example` (full list: [environment.md](environment.md)):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.autowave.co.in
AUTOWAVE_ROOT_DOMAIN=autowave.co.in
AUTOWAVE_MARKETING_HOST=autowave.co.in
AUTOWAVE_APP_HOST=app.autowave.co.in
AUTOWAVE_ADMIN_HOST=admin.autowave.co.in
AUTOWAVE_ADMIN_PASSWORD=            # empty: the seeder generated one and printed it once
LOG_LEVEL=warning
DB_DATABASE=autowave_platform
DB_USERNAME=autowave_platform_user
DB_PASSWORD=<random, 48 hex>
SESSION_SECURE_COOKIE=true
CACHE_PREFIX=awp_cache_
REDIS_CLIENT=phpredis
REDIS_DB=2
REDIS_CACHE_DB=3
REDIS_PREFIX=awp_
MAIL_MAILER=log                     # until SMTP is configured
AI_PROVIDER=fake                    # until the OpenRouter key is added
```

Turning on email, AI and WhatsApp/Instagram: [enable-integrations.md](../11-runbooks/enable-integrations.md).

## Data

- Seeded with `db:seed --force` in production: plans, business types, modules, roles, the internal tenant
  and one Super Admin. No demo businesses (those seeders run only when `APP_ENV=local`).
- Backups: not yet automated (AW-066) — see [backup.md](../11-runbooks/backup.md).

## Setting up a server from scratch

The order used on 2026-09-29. Commands are in the linked docs.

1. Swap file (2 GB) if RAM is under 4 GB.
2. Packages: nginx, php8.4-fpm with `pgsql redis intl mbstring xml curl zip gd bcmath`, composer,
   Node 22, PostgreSQL, Redis, certbot + `python3-certbot-dns-digitalocean`.
3. User: `adduser --system --group --shell /bin/bash --home /home/autowave autowave`.
4. Folders: `/var/www/autowave-platform/{releases,shared/storage/{app/public,app/private,framework/cache/data,framework/sessions,framework/views,logs}}`, owned by `autowave`.
5. PHP-FPM pool (above), `php-fpm8.4 -t && systemctl reload php8.4-fpm`.
6. Database role and database ([postgres.md](postgres.md)).
7. Deploy key: `ssh-keygen -t ed25519` as `autowave`, added read-only on GitHub (Settings → Deploy keys).
8. `shared/.env` (above), `chmod 600`.
9. First release ([deployment.md](deployment.md)), including `key:generate --force` and `db:seed --force`
   the first time only. Save the printed Super Admin password.
10. Worker service and scheduler cron ([queue-workers.md](queue-workers.md)).
11. DNS: A records `@` and `*` → server IP in DigitalOcean; nameservers at the registrar.
12. Wildcard certificate and the HTTPS Nginx site ([ssl.md](ssl.md), [nginx.md](nginx.md)).
13. Check: the four hosts over HTTPS, Super Admin login, register a business, open its website, and the
    other sites on the server.

## Security notes

- UFW is inactive on the shared server and the MinIO container publishes ports 9000–9001 (AW-067).
  PostgreSQL and Redis listen on localhost only.
- Redis has no password (localhost only, shared with the other projects; AW-067).
- Bots probe for `.env`, `.git` and `info.php` from the first hour; Nginx denies dotfiles and returns 404
  for PHP files that do not exist. The real `.env` is outside the web root.
