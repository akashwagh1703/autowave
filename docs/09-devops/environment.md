# Environment Variables

Template: `.env.example`. Never commit `.env`. Production secrets live only on the server (`/var/www/autowave/shared/.env`).

| Variable | Local default | Production | Description |
|---|---|---|---|
| `APP_NAME` | `AutoWave` | `AutoWave` | Display name; shared to the frontend |
| `APP_ENV` | `local` | `production` | Environment name |
| `APP_KEY` | generated | generated once, secret | Encryption key (`php artisan key:generate`) — rotating invalidates sessions/encrypted data |
| `APP_DEBUG` | `true` | **`false`** | Detailed errors — must be false in production |
| `APP_URL` | `http://localhost:8000` | `https://app.autowave.in` | Base URL for generated links |
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
| `QUEUE_CONNECTION` | `redis` | `redis` | Queue backend (ADR-004) |
| `CACHE_STORE` | `redis` | `redis` | Cache backend |
| `CACHE_PREFIX` | `autowave_cache_` | same | Cache key prefix |
| `REDIS_CLIENT` | `predis` | `predis` or `phpredis` | Redis client library (ADR-009) |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` | `127.0.0.1` / `6379` / `null` | server values, password set | Redis connection |
| `REDIS_PREFIX` | `autowave_database_` | same | Redis key prefix |
| `MAIL_*` | `log` mailer | SMTP provider | Outgoing mail |
| `AWS_*` | empty | DigitalOcean Spaces (future) | S3-compatible storage |
| `VITE_APP_NAME` | `${APP_NAME}` | same | App name available to frontend at build time |

When adding a variable: add it to `.env.example` **and** this table in the same commit.
