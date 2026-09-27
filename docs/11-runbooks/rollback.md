# Runbook: Rollback

## Code rollback (no destructive migration)

```bash
cd /var/www/autowave
ls -dt releases/*                      # newest first
PREV=$(ls -dt releases/* | sed -n 2p)
ln -sfn "$PWD/$PREV" current
sudo systemctl reload php8.4-fpm
cd current && php artisan config:cache && php artisan route:cache && php artisan queue:restart
php artisan autowave:health
```

## Migration rollback

Only if the release's migrations are reversible and no new data depends on them:

```bash
php artisan migrate:rollback --step=<n> --force    # run from the NEW release before switching back
```

If a migration was destructive, restore from backup instead (`restore-database.md`) — this loses data written
since the backup; get owner approval.

## After

Open an issue (`AW-XXX`, category Bug), add to `docs/00-overview/known-issues.md`, and write a short incident note.
