# Redis

Used for: cache (`CACHE_STORE=redis`) and queues (`QUEUE_CONNECTION=redis`). Sessions stay in PostgreSQL.

## Local

`docker compose up -d redis` — Redis 7 with AOF persistence (`--appendonly yes`), port `FORWARD_REDIS_PORT`.

```bash
docker compose exec redis redis-cli ping         # PONG
docker compose exec redis redis-cli llen autowave_database_queues:default
```

## Client

`REDIS_CLIENT=predis` (pure PHP). Production may use `phpredis` for performance (ADR-009).
Connections in `config/database.php`: `default` (queues, DB 0) and `cache` (DB 1).

## Queues

`default`, `automation`, `messaging`, `ai`, `notifications`, `reports`, `media` (ADR-004).

## Production (draft, AW-004)

- Bind to `127.0.0.1`, set `requirepass`, enable `appendonly yes`, `maxmemory-policy noeviction`
  (queues must never be evicted).
- Monitor memory and queue lengths. Failure procedure: `docs/11-runbooks/redis-failure.md`.
