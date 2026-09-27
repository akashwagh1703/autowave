# ADR-004: Redis Queues and a Single Scheduler

- **Status:** Accepted
- **Date:** 2026-09-27

## Context

Automation, messaging, AI calls, notifications, reports and media processing must run asynchronously,
retry safely and be observable. Delayed automation steps must fire on time for all tenants.

## Decision

- Use Redis as the queue backend (Laravel Queue) and cache store.
- Separate queues: `default`, `automation`, `messaging`, `ai`, `notifications`, `reports`, `media`.
- Workers run under Supervisor in production. Horizon will be added for production monitoring (AW-001).
- One Laravel Scheduler (`schedule:run` via a single cron entry) dispatches due work every minute. No per-tenant crons.
- Jobs are idempotent where retries are possible, have explicit tries/backoff, and failures go to `failed_jobs`
  and are logged with tenant/job context.

## Alternatives

- **Database queue** — no extra service, but poorer throughput and locking behaviour under load.
- **SQS / managed queues** — conflicts with the self-hosted DigitalOcean VPS approach.

## Consequences

- Redis becomes a critical dependency (see `docs/11-runbooks/redis-failure.md`); enable AOF persistence.
- Queue priorities can be tuned per worker (`--queue=automation,messaging,default`).
