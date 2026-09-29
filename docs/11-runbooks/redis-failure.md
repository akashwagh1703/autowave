# Runbook: Redis Failure

Impact: cache misses (app slower) and **queues stop** (automations/messages delayed). Sessions are in PostgreSQL,
so users stay logged in.

## Diagnose

Production Redis has no password yet (AW-067); add `-a "$REDIS_PASSWORD"` once it does. It is shared with
other projects: AutoWave uses DB 2 (queues) and DB 3 (cache), prefix `awp_`.

```bash
sudo systemctl status redis-server
redis-cli ping
redis-cli info memory | grep -E 'used_memory_human|maxmemory'
redis-cli info keyspace
journalctl -u redis-server -n 100
```

## Fix

| Cause | Action |
|---|---|
| Service stopped/crashed | `sudo systemctl restart redis-server`; then `php8.4 artisan queue:restart` |
| Out of memory | Find big keys (`redis-cli --bigkeys`); clear AutoWave's cache only: `php8.4 artisan cache:clear` (never `FLUSHALL` or `FLUSHDB` — deletes queued jobs and the other projects' data) |
| Disk full (AOF) | Free disk; `redis-cli BGREWRITEAOF` |
| Corrupt AOF | `redis-check-aof --fix /var/lib/redis/appendonly.aof` (backup first) |

After recovery: `php artisan autowave:health`, check `php artisan queue:failed`, retry as needed.
