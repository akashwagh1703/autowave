# ADR-009: Local Infrastructure — Docker Services, predis, PostgreSQL Tests

- **Status:** Accepted
- **Date:** 2026-09-27

## Context

The primary development machine is Windows with XAMPP (PHP 8.2), no local PostgreSQL or Redis, and Docker
Desktop available. Laravel 13 needs PHP 8.3+. Production is a native Ubuntu VPS (no containers required).

## Decision

1. **PostgreSQL 17 and Redis 7 run in Docker locally** via `docker-compose.yml`. PHP runs natively on the host
   (fast file access on Windows, simple debugging). A first-run init script creates `autowave_testing`.
2. **predis** is the Redis client (`REDIS_CLIENT=predis`) because the phpredis extension is hard to obtain on
   Windows. Production may switch to phpredis for performance with no code changes.
3. **Tests run against PostgreSQL** (`autowave_testing`), not SQLite, so constraints, locking and JSON behaviour
   match production. CI uses PostgreSQL and Redis service containers.
4. Host ports are configurable (`FORWARD_DB_PORT`, `FORWARD_REDIS_PORT`) to avoid clashes with other local projects.

## Alternatives

- **Laravel Sail (everything in Docker)** — consistent, but slow bind mounts on Windows and heavier setup.
- **Native Windows PostgreSQL/Redis installs** — Redis has no official Windows build.
- **SQLite for tests** — faster, but hides PostgreSQL-specific behaviour (exclusion constraints, locking).

## Consequences

- Developers need Docker Desktop running for the app and tests.
- The Docker setup is dev-only; production setup is documented in `docs/09-devops/`.
