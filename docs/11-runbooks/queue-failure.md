# Runbook: Queue Failure

## Symptoms

Automations/messages not sent, `failed_jobs` growing, queue lengths increasing.

## Diagnose

```bash
php artisan autowave:health                       # Redis reachable?
sudo supervisorctl status                         # workers RUNNING?
php artisan queue:failed                          # list failed jobs
redis-cli -a "$REDIS_PASSWORD" llen autowave_database_queues:automation
tail -n 100 /var/www/autowave/shared/storage/logs/worker-high.log
```

## Fix

| Cause | Action |
|---|---|
| Workers stopped | `sudo supervisorctl restart all` |
| Workers running old code | `php artisan queue:restart` |
| Redis down | [redis-failure.md](redis-failure.md) |
| Bug in a job | Fix + deploy, then `php artisan queue:retry <uuid>` or `queue:retry all` |
| Provider outage (WhatsApp/AI) | Wait for recovery; jobs retry with backoff; retry failed ones after |
| Poison job repeatedly failing | Inspect payload in `failed_jobs`, fix data, `queue:forget <uuid>` if unrecoverable |

Jobs must be idempotent, so retrying is safe; double-check messaging jobs for duplicate-send guards before `retry all`.
