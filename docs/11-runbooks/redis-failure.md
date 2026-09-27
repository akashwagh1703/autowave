# Runbook: Redis Failure

Impact: cache misses (app slower) and **queues stop** (automations/messages delayed). Sessions are in PostgreSQL,
so users stay logged in.

## Diagnose

```bash
sudo systemctl status redis-server
redis-cli -a "$REDIS_PASSWORD" ping
redis-cli -a "$REDIS_PASSWORD" info memory | grep -E 'used_memory_human|maxmemory'
journalctl -u redis-server -n 100
```

## Fix

| Cause | Action |
|---|---|
| Service stopped/crashed | `sudo systemctl restart redis-server`; then `php artisan queue:restart` |
| Out of memory | Find big keys (`redis-cli --bigkeys`); clear cache only: `php artisan cache:clear` (never `FLUSHALL` — deletes queued jobs) |
| Disk full (AOF) | Free disk; `redis-cli BGREWRITEAOF` |
| Corrupt AOF | `redis-check-aof --fix /var/lib/redis/appendonly.aof` (backup first) |

After recovery: `php artisan autowave:health`, check `php artisan queue:failed`, retry as needed.
