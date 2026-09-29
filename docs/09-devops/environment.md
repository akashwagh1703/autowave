# Environment Variables

Template: `.env.example`. Never commit `.env`. Production secrets live only on the server
(`/var/www/autowave-platform/shared/.env`, mode 600, owner `autowave`). The full production file is in
[production.md](production.md#production-env). After editing it, run
`php8.4 artisan optimize && php8.4 artisan queue:restart` as `autowave` in `current/`.

| Variable | Local default | Production | Description |
|---|---|---|---|
| `APP_NAME` | `AutoWave` | `AutoWave` | Display name; shared to the frontend |
| `APP_ENV` | `local` | `production` | Environment name |
| `APP_KEY` | generated | generated once, secret | Encryption key (`php artisan key:generate`) — rotating invalidates sessions/encrypted data |
| `APP_DEBUG` | `true` | **`false`** | Detailed errors — must be false in production |
| `APP_URL` | `http://app.autowave.localhost:8000` | `https://app.autowave.co.in` | Base URL for generated links (the business app host) |
| `AUTOWAVE_ROOT_DOMAIN` | `autowave.localhost` | `autowave.co.in` | Tenant subdomains are `{slug}.{root}`; `www.{root}` redirects to marketing |
| `AUTOWAVE_MARKETING_HOST` | `autowave.localhost` | `autowave.co.in` | Marketing site host |
| `AUTOWAVE_APP_HOST` | `app.autowave.localhost` | `app.autowave.co.in` | Business app + Fortify auth host |
| `AUTOWAVE_ADMIN_HOST` | `admin.autowave.localhost` | `admin.autowave.co.in` | Super Admin host |
| `AUTOWAVE_DOMAIN_CACHE_TTL` | `600` | `600` | Seconds a host → tenant lookup is cached |
| `AUTOWAVE_ADMIN_NAME` / `AUTOWAVE_ADMIN_EMAIL` | `AutoWave Admin` / `admin@autowave.in` | real values | First platform admin (`PlatformAdminSeeder`) |
| `AUTOWAVE_ADMIN_PASSWORD` | empty (random, printed once) | set once, then remove | First platform admin password; never changes an existing admin |
| `AUTOWAVE_MAX_BUSINESSES_PER_USER` | `3` | `3` | Businesses one user may create through onboarding (counted by `tenants.created_by_user_id`) |
| `AUTOWAVE_DEFAULT_COUNTRY_CODE` | `91` | `91` | Calling code (digits only) added to local phone numbers when normalising them for lead/customer matching (`App\Support\Phone`) |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE` | `en` | `en` | Locale |
| `APP_FAKER_LOCALE` | `en_IN` | — | Faker locale for factories |
| `APP_MAINTENANCE_DRIVER` | `file` | `file` | Maintenance mode storage |
| `BCRYPT_ROUNDS` | `12` | `12` | Password hashing cost |
| `LOG_CHANNEL` / `LOG_STACK` | `stack` / `daily` | `stack` / `daily` | Daily rotated logs in `storage/logs` |
| `LOG_LEVEL` | `debug` | `warning` or `info` | Minimum log level |
| `DB_CONNECTION` | `pgsql` | `pgsql` | Database driver |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `5432` | server values | PostgreSQL location |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `autowave` / `autowave` / `secret` | secret | Credentials |
| `FORWARD_DB_PORT` / `FORWARD_REDIS_PORT` | `5432` / `6379` | — | Host ports for `docker-compose.yml` (local only) |
| `SESSION_DRIVER` | `database` | `database` | Session storage |
| `SESSION_LIFETIME` | `120` | `120` | Minutes |
| `SESSION_ENCRYPT` | `false` | `false` | Encrypt session payload |
| `SESSION_DOMAIN` | `null` | `null` (per-host) | Cookie domain; keep host-only so tenant sites don't share app cookies |
| `SESSION_SECURE_COOKIE` | `false` | **`true`** | Cookies only over HTTPS |
| `BROADCAST_CONNECTION` | `log` | `log` | Broadcasting (unused) |
| `FILESYSTEM_DISK` | `local` | `local` (later S3-compatible Spaces) | Default disk |
| `WEBSITE_MEDIA_DISK` | `public` | `public` | Disk for website images; needs `storage:link` (the deploy script runs it) |
| `QUEUE_CONNECTION` | `redis` | `redis` | Queue backend (ADR-004) |
| `CACHE_STORE` | `redis` | `redis` | Cache backend |
| `CACHE_PREFIX` | `autowave_cache_` | `awp_cache_` | Cache key prefix |
| `REDIS_CLIENT` | `predis` | `phpredis` | Redis client library (ADR-009) |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` | `127.0.0.1` / `6379` / `null` | `127.0.0.1` / `6379` / `null` (AW-067) | Redis connection |
| `REDIS_DB` / `REDIS_CACHE_DB` | `0` / `1` | `2` / `3` | Redis databases for queues and cache; production shares Redis with other projects |
| `REDIS_PREFIX` | `autowave_database_` | `awp_` | Redis key prefix |
| `MAIL_*` | `log` mailer | SMTP provider ([enable-integrations](../11-runbooks/enable-integrations.md)) | Outgoing mail |
| `MESSAGING_WHATSAPP_PROVIDER` / `MESSAGING_INSTAGRAM_PROVIDER` | `log` | `log` | Fallback for businesses that have not connected the channel; connected channels always use Meta |
| `MESSAGING_EMAIL_PROVIDER` | `mail` | `mail` | Email channel provider |
| `META_GRAPH_VERSION` | `v21.0` | `v21.0` | Meta Graph API version |
| `AI_PROVIDER` | `openrouter` | `openrouter` (or `fake` until a key is set) | AI provider (ADR-019) |
| `OPENROUTER_API_KEY` / `OPENROUTER_MODEL` | empty / `openai/gpt-4o-mini` | secret / same | OpenRouter key (server only) and default model |
| `OPENROUTER_TIMEOUT` / `OPENROUTER_REASONING` | `30` / empty | same | Seconds to wait; `off`/`low`/`medium`/`high`. Above 30 s see [openrouter.md](../06-integrations/openrouter.md#slow-and-free-models) |
| `REDIS_QUEUE_RETRY_AFTER` | `90` | `90`, or more than `OPENROUTER_TIMEOUT` + 30 | Must exceed the AI job timeout (`autowave:health` checks) |
| `AI_MONTHLY_TOKENS` / `AI_AUTO_EXTRACT` / `AI_QUEUE` | `300000` / `true` / `ai` | same | AI cap per business, automatic lead extraction, queue |
| `AWS_*` | empty | DigitalOcean Spaces (future) | S3-compatible storage |
| `VITE_APP_NAME` | `${APP_NAME}` | same | App name available to frontend at build time |

When adding a variable: add it to `.env.example` **and** this table in the same commit.
