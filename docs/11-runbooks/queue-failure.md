# Runbook: Queue Failure

## Symptoms

Automations/messages not sent, `failed_jobs` growing, queue lengths increasing.

## Diagnose

Run artisan as `autowave` from `/var/www/autowave-platform/current` (`sudo -u autowave php8.4 artisan …`).

```bash
php8.4 artisan autowave:health                    # Redis reachable?
systemctl status autowave-platform-worker         # worker active?
journalctl -u autowave-platform-worker -n 100     # worker output and crashes
php8.4 artisan queue:failed                       # list failed jobs
redis-cli -n 2 llen awp_queues:automation         # queue length (DB 2, prefix awp_)
journalctl -u cron --since "10 min ago" | grep autowave   # scheduler firing every minute?
```

## Fix

| Cause | Action |
|---|---|
| Worker stopped | `systemctl restart autowave-platform-worker` |
| Worker running old code | `php8.4 artisan queue:restart` (systemd restarts it) |
| Waits never end / stuck work not recovered | Scheduler not running: check `/etc/cron.d/autowave-platform` (mode 644, user `autowave`) |
| Redis down | [redis-failure.md](redis-failure.md) |
| Bug in a job | Fix + deploy, then `php artisan queue:retry <uuid>` or `queue:retry all` |
| Provider outage (WhatsApp/AI) | Wait for recovery; jobs retry with backoff; retry failed ones after |
| Poison job repeatedly failing | Inspect payload in `failed_jobs`, fix data, `queue:forget <uuid>` if unrecoverable |

Jobs must be idempotent, so retrying is safe; double-check messaging jobs for duplicate-send guards before `retry all`.
