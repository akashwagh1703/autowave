# Runbook: Restore Database

## Restore test (monthly, non-destructive)

```bash
createdb -U autowave autowave_restore_test
pg_restore -U autowave -d autowave_restore_test --no-owner /path/to/autowave-YYYYMMDD.dump
psql -U autowave -d autowave_restore_test -c "select count(*) from users;"
dropdb -U autowave autowave_restore_test
```

Record the date and result.

## Production restore (destructive — owner approval required)

1. Put the app in maintenance: `php artisan down --retry=60`.
2. Stop workers: `sudo supervisorctl stop all`.
3. Download the chosen dump from off-server storage.
4. Keep the current (broken) DB for forensics:
   `psql -U postgres -c "ALTER DATABASE autowave RENAME TO autowave_broken_$(date +%Y%m%d);"`
5. `createdb -U postgres -O autowave autowave`
6. `pg_restore -U autowave -d autowave --no-owner autowave-YYYYMMDD.dump`
7. `php artisan migrate --force` (applies migrations newer than the backup).
8. `php artisan autowave:health`, smoke test, then `sudo supervisorctl start all` and `php artisan up`.
9. Communicate the data-loss window to affected tenants; write an incident note.
