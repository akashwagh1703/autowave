# Deployment

> Draft — to be validated on first deploy (AW-004). Operational steps: `docs/11-runbooks/deployment.md`.

## Strategy

Atomic release directories with a `current` symlink (zero-downtime switch), run by a deploy script (and later
GitHub Actions over SSH).

## Steps

```bash
set -euo pipefail
APP=/var/www/autowave
REL=$APP/releases/$(date +%Y%m%d%H%M%S)

git clone --depth 1 --branch main git@github.com:akashwagh1703/autowave.git "$REL"
cd "$REL"
ln -s $APP/shared/.env .env
rm -rf storage && ln -s $APP/shared/storage storage

composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci && npm run build && rm -rf node_modules

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

ln -sfn "$REL" $APP/current
sudo systemctl reload php8.4-fpm
php artisan queue:restart          # workers finish current job, then restart on new code

ls -dt $APP/releases/* | tail -n +6 | xargs -r rm -rf   # keep last 5 releases
```

## Rules

- Migrations must be backwards compatible with the previous release (expand → migrate → contract).
- Take a database backup before risky migrations.
- Rollback procedure: `docs/11-runbooks/rollback.md`.
