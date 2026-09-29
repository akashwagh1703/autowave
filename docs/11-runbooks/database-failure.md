# Runbook: Database Failure

Impact: the application is down (all requests need PostgreSQL).

## Diagnose

```bash
sudo systemctl status postgresql@16-main
pg_isready -h 127.0.0.1 -U autowave_platform_user -d autowave_platform
df -h                                    # disk full is the most common cause
journalctl -u postgresql@16-main -n 100
sudo -u postgres psql -c "select count(*), state from pg_stat_activity group by state;"
```

## Fix

| Cause | Action |
|---|---|
| Service stopped | `sudo systemctl restart postgresql@16-main` (shared with other projects on the server) |
| Disk full | Free space (old releases, logs, local dumps); never delete files under the data directory |
| Too many connections | Find idle-in-transaction sessions; `select pg_terminate_backend(pid) ...`; consider PgBouncer |
| Long-running lock | `select * from pg_locks l join pg_stat_activity a using (pid) where not granted;` then terminate blocker |
| Corruption / data loss | [restore-database.md](restore-database.md) |

While down: `php artisan down` shows a maintenance page. After recovery: `php artisan up`, `autowave:health`,
check failed jobs.
