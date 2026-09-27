# Testing Strategy

## Tooling

- PHPUnit 12 via `php artisan test`.
- Database: PostgreSQL `autowave_testing` (created by `docker/postgres/init/`; CI service container). Never SQLite.
- `tests/TestCase.php` calls `withoutVite()` so backend tests don't need built assets.
- Style: `vendor/bin/pint --test`. Frontend: `npm run build` must succeed.
- CI: `.github/workflows/ci.yml` runs all three on every push/PR.

## Layout

```text
tests/
├── Feature/
│   ├── Foundation/      WelcomePageTest, InfrastructureTest (Phase 0)
│   └── <Domain>/        feature, authorization and tenant-isolation tests
└── Unit/
    └── <Domain>/        pure domain logic
```

## Test types

| Type | Scope | Required for |
|---|---|---|
| Unit | Domain logic without framework I/O | Calculations, resolvers, state machines |
| Feature | HTTP/Inertia workflows | Every route |
| Authorization | Allowed + forbidden per permission | Every guarded action |
| Tenant isolation | Tenant A vs Tenant B | Every tenant-owned resource (release-blocking) |
| Integration | Provider adapters with `Http::fake()` | AI, messaging, payments |
| Queue | Jobs dispatched, idempotent, retry/failure | Every job |
| Automation | Trigger, condition pass/fail, delay, execution, retry, failure log, duplicate prevention | Automation engine |
| Booking | Create, cancel, reschedule, unavailable slot, duplicate, concurrent attempt, timezone, resource availability | Booking engine |
| E2E | Full customer workflows | Milestones (tooling TBD) |

## Current coverage (Phase 0)

- Home page renders the `Welcome` Inertia component with shared props
- Security headers present
- `/up` health endpoint
- Test suite runs on PostgreSQL
- `autowave:health` passes (DB, Redis, cache)
