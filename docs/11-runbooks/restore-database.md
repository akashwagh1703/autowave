# Runbook: Restore Database

Production: database `autowave_platform`, role `autowave_platform_user`, PostgreSQL 16 on the server
(run `psql`/`pg_restore` as `sudo -u postgres`). Artisan runs as `autowave` from
`/var/www/autowave-platform/current`.

## Restore test (monthly, non-destructive)

```bash
sudo -u postgres createdb -O autowave_platform_user autowave_restore_test
sudo -u postgres pg_restore -d autowave_restore_test --no-owner --role=autowave_platform_user /path/to/autowave-YYYYMMDD.dump
sudo -u postgres psql -d autowave_restore_test -c "select count(*) from users;"
sudo -u postgres dropdb autowave_restore_test
```

Record the date and result.

## Production restore (destructive — owner approval required)

1. Put the app in maintenance: `php8.4 artisan down --retry=60`.
2. Stop the worker: `systemctl stop autowave-platform-worker`.
3. Download the chosen dump from off-server storage.
4. Keep the current (broken) DB for forensics:
   `sudo -u postgres psql -c "ALTER DATABASE autowave_platform RENAME TO autowave_platform_broken_$(date +%Y%m%d);"`
5. `sudo -u postgres createdb -O autowave_platform_user autowave_platform`
6. `sudo -u postgres pg_restore -d autowave_platform --no-owner --role=autowave_platform_user autowave-YYYYMMDD.dump`
7. `php8.4 artisan migrate --force` (applies migrations newer than the backup).
8. `php8.4 artisan autowave:health`, smoke test, then `systemctl start autowave-platform-worker` and
   `php8.4 artisan up`.
9. Communicate the data-loss window to affected tenants; write an incident note.
