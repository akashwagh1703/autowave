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
Connections in `config/database.php`: `default` (queues, `REDIS_DB`, default 0) and `cache`
(`REDIS_CACHE_DB`, default 1).

## Queues

`default`, `automation`, `messaging`, `ai`, `notifications`, `reports`, `media` (ADR-004).

## Production

- The server's Redis 7.0 (`redis-server.service`), shared with the owner's other projects, bound to
  `127.0.0.1` / `::1`. It was empty when AutoWave was deployed.
- AutoWave keeps to its own space: `REDIS_DB=2` (queues), `REDIS_CACHE_DB=3` (cache), `REDIS_PREFIX=awp_`,
  `CACHE_PREFIX=awp_cache_`, client `phpredis` (the extension is installed).
- No `requirepass` yet (AW-067). When one is set, add `REDIS_PASSWORD` to `shared/.env` and check the
  other projects first.
- Target settings: `appendonly yes`, `maxmemory-policy noeviction` (queues must never be evicted).
- `redis-cli -n 2 LLEN awp_queues:default` shows a queue's length.
- Failure procedure: [redis-failure.md](../11-runbooks/redis-failure.md).
