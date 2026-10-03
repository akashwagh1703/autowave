# Local Development

Verified on Windows 11 (PowerShell) on 2026-09-27. Works the same on macOS/Linux with shell syntax adjusted.

## Prerequisites

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.3+ (8.4 recommended) | Extensions: `pdo_pgsql`, `pgsql`, `mbstring`, `intl`, `openssl`, `curl`, `fileinfo`, `zip`, `sodium`, `gd` |
| Composer | 2.x | |
| Node.js / npm | 22+ / 10+ | |
| Docker Desktop | recent | Runs PostgreSQL 17 + Redis 7 (ADR-009) |
| Git | 2.x | |

### Windows: PHP 8.4 without replacing XAMPP

XAMPP ships PHP 8.2, which cannot run Laravel 13. Use a portable PHP 8.4:

```powershell
$dest = "$env:USERPROFILE\tools\php84"
New-Item -ItemType Directory -Force $dest | Out-Null
Invoke-WebRequest "https://windows.php.net/downloads/releases/latest/php-8.4-nts-Win32-vs17-x64-latest.zip" -OutFile "$env:TEMP\php84.zip"
Expand-Archive "$env:TEMP\php84.zip" -DestinationPath $dest -Force
Copy-Item "$dest\php.ini-development" "$dest\php.ini"
# In php.ini: set extension_dir = "ext" and enable:
#   curl fileinfo gd intl mbstring openssl pdo_pgsql pgsql pdo_sqlite sqlite3 sodium zip exif
#   memory_limit = 512M
```

Put it first on PATH for the current terminal (or permanently in the user PATH, ahead of XAMPP):

```powershell
$env:Path = "$env:USERPROFILE\tools\php84;" + $env:Path
php -v   # must print 8.4.x
```

## Setup

```bash
git clone https://github.com/akashwagh1703/autowave.git
cd autowave
cp .env.example .env                  # PowerShell: Copy-Item .env.example .env
docker compose up -d                  # PostgreSQL + Redis (+ creates autowave_testing on first run)
composer install
php artisan key:generate
php artisan migrate
php artisan storage:link              # serves uploaded website images from /storage
npm install
npm run build
php artisan autowave:health
```

Website images go to the disk named by `WEBSITE_MEDIA_DISK` (default `public`, i.e.
`storage/app/public/tenant/{id}/…`). Without `storage:link` uploads succeed but the images don't load.

Expected health output:

```text
Database ........ pgsql (autowave)
Redis ........... ping: PONG
Cache ........... store: redis
```

### Databases: development vs tests

- **Development** (`.env`) may point at the local Docker PostgreSQL or at the shared dev server
  (database `autowave`; host and credentials are shared privately and live only in your `.env`, never committed).
- **Tests always use a local `*_testing` database.** `.env.testing` (gitignored, copy the DB block from
  `.env.example`) points at Docker `autowave_testing`; `tests/TestCase.php` refuses to run against any
  database whose name does not end in `_testing`, because `RefreshDatabase` wipes it.

### Seeding

```bash
php artisan migrate --seed   # catalogue, RBAC templates, platform admin, AutoWave Internal (+ demo tenants when APP_ENV=local)
```

- `AUTOWAVE_ADMIN_PASSWORD` empty → a random admin password is printed once. Save it.
- Demo data (local only, password `password`):

  | Tenant | Business type | Users |
  |---|---|---|
  | `abc-salon` | Beauty & Salon | `owner@abc-salon.test`, `staff@abc-salon.test` |
  | `abc-turf` | Turf (rates and an advance) | `owner@abc-turf.test` |
  | `abc-coaching` | Coaching Centre | `owner@abc-coaching.test`, `teacher@abc-coaching.test` (Staff) |
  | `abc-cafe` | Cafe & Restaurant | `owner@abc-cafe.test` |
  | `abc-store` | Local Commerce | `owner@abc-store.test` |

  `manager@autowave.test` is a manager in `abc-salon` and `abc-turf`. Coupon codes: `WELCOME10` (cafe,
  online), `FLAT50` (cafe, in store), `SAVE50` and `DIWALI15` (store).
- Seeders are idempotent; re-running is safe.

### Hosts

`*.localhost` resolves to 127.0.0.1 in Chrome/Edge/Firefox, so no hosts-file changes are needed:

| URL | What |
|---|---|
| http://autowave.localhost:8000 | Marketing site |
| http://app.autowave.localhost:8000 | Business app (login, register, dashboard) |
| http://admin.autowave.localhost:8000 | Super Admin |
| http://abc-salon.autowave.localhost:8000 | Demo tenant website (also `abc-turf`, `abc-coaching`, `abc-cafe`, `abc-store`) |

Tools that do their own DNS (PowerShell `Invoke-WebRequest`, some HTTP clients) may not resolve `*.localhost`;
send a `Host` header to `127.0.0.1:8000` instead.

### Port conflicts

If 5432 or 6379 is already used (e.g. another project's PostgreSQL container), change **both** values in `.env`:

```dotenv
FORWARD_DB_PORT=5433
DB_PORT=5433
```

then `docker compose up -d`. (The primary dev machine uses 5433 for this reason.)

## Running

| What | Command |
|---|---|
| Everything (server, queue, scheduler, logs, Vite) | `composer dev` |
| Web server only | `php artisan serve` → http://app.autowave.localhost:8000 |
| Vite dev server (HMR) | `npm run dev` |
| Queue worker | `php artisan queue:work redis --queue=automation,messaging,notifications,ai,media,default` |
| Scheduler (automation waits, recovery, hourly fee reminders) | `php artisan schedule:work` |
| Logs | `php artisan pail` or `storage/logs/laravel-YYYY-MM-DD.log` |

Automations need both the queue worker and the scheduler; see [queue-workers.md](queue-workers.md).
`php artisan db:seed` (local) also turns on the demo automations for `abc-salon` (`DemoAutomationSeeder`)
and fills the website content of every demo tenant (`DemoWebsiteSeeder`). `DemoEducationSeeder` and
`DemoFoodSeeder` add the coaching and cafe data.

## Tests and checks

```bash
php artisan test          # PostgreSQL database autowave_testing
vendor/bin/pint --test    # code style (vendor/bin/pint to fix)
npm run build             # frontend compiles
```

## Resetting

```bash
php artisan migrate:fresh           # wipes local DB
docker compose down -v              # removes containers AND volumes (all local data, re-creates autowave_testing next up)
```

## Troubleshooting

| Symptom | Fix |
|---|---|
| `Composer detected issues in your platform: ... PHP >= 8.3` | Wrong PHP on PATH (see Windows section) |
| `could not find driver (pgsql)` | Enable `pdo_pgsql` + `pgsql` in `php.ini` |
| `Connection refused` on 5432/6379 | Start Docker Desktop, `docker compose up -d`, check `docker compose ps` |
| `database "autowave_testing" does not exist` | Volume predates the init script: `docker compose exec postgres createdb -U autowave autowave_testing` |
| `Vite manifest not found` in browser | Run `npm run build` or `npm run dev` |
| Composer "could not delete ... tmp zip" on Windows | Antivirus/indexer lock — re-run `composer install` |
