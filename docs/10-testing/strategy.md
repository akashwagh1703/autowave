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
| Automation | Trigger, condition pass/fail, delay, execution, retry, failure log, duplicate prevention | Automation engine (`tests/Feature/Automation`; helpers in `tests/Concerns/CreatesAutomations.php`) |
| Booking | Create, cancel, reschedule, unavailable slot, duplicate, concurrent attempt, timezone, resource availability | Booking engine (`tests/Feature/Booking`; concurrency is simulated, AW-021) |
| Public website | Render, publish/preview, SEO meta, forms (validation, honeypot, throttle), online booking, cross-tenant hosts | Website engine (`tests/Feature/Website`; requests go to `$this->siteUrl('abc-salon.autowave.test', '/path')`) |
| Commerce | Server pricing (tampered prices ignored), stock never below zero, cancel restocks once, lifecycle, payments up to the balance, numbering, website checkout, cross-tenant products and customers | Commerce engine (`tests/Feature/Commerce`, `tests/Feature/Website/OnlineShopTest`; helpers in `tests/Concerns/CreatesCommerceRecords.php`) |
| Messaging | Webhook verification and signatures, normalisation, idempotent inbound, forward-only receipts, provider payloads (`Http::fake`), window, templates, opt-out, quiet hours, write-only secrets, inbox permissions, cross-tenant webhooks and conversations | Messaging (`tests/Feature/Messaging`; helpers in `tests/Concerns/CreatesMessaging.php`) |
| E2E | Full customer workflows | Milestones (tooling TBD) |

## Testing queued work

`phpunit.xml` uses `QUEUE_CONNECTION=sync`:

- Jobs run immediately; `afterCommit` jobs run when the transaction commits.
- Automation waits stay as `pending` rows. Tests call `$this->travel(...)` and then
  `artisan('automation:dispatch-due')`.
- To look at a step before it runs, `Queue::fake()` the dispatch and call `StepRunner::run($jobId)`
  directly.
- `tests/Fixtures/FlakyStep.php` is an action that fails a set number of times, for retry and failure
  tests.
- A job that exhausts its tries fails immediately on the sync queue (AW-032). Assert on the run, job and
  log rows, not on exceptions.
- Default automations fire in every test tenant. Tests that count activities or runs call
  `pauseDefaultAutomations($tenant)` first.

## Several requests in one test

Laravel keeps controller instances between requests in the same application, which is what happens in a
test (and under Octane). A service injected into a controller's constructor keeps the state of the first
request: tenant, memoised settings. Inject request-dependent services (`OnlineBooking`, `BookingSettings`,
`TenantContext`) into the controller **method** instead. A test that changes a setting and then makes
another request catches this.

Other helpers:

- `disableModule($tenant, $code)` (`CreatesTenants`) turns a module off for gate tests.
- Rate-limit tests set a low limit with `config([...])` before the first request. Honeypot requests count
  towards the limit too.
- Postgres `jsonb` does not keep object key order; compare stored arrays with `assertEquals`, not
  `assertSame`.
- Session flash (for example the website order confirmation) is consumed by the first GET after the POST.
  Assert it on that GET, and assert it is gone on the next one.
- Commerce helpers (`CreatesCommerceRecords`): `makeProduct($tenant, [...], $stock)` (tracks stock when
  `$stock` is given), `placeOrder($tenant, [[$product, $qty]], [...])`, `setOnlineOrdering($tenant, [...])`
  and `stockOf($tenant, $product)`.
- Audit rows are in `audit_logs` with the column `action`.
- Messaging helpers (`CreatesMessaging`):
  - `connectWhatsApp($tenant)` / `connectInstagram($tenant)` save a connected channel without calling Meta
    (token `wa-token` / `ig-token`, app secret `CreatesMessaging::APP_SECRET`);
  - `receive($tenant, $from, $text, [...])` runs the inbound pipeline and returns the conversation (options
    `channel`, `name`, `at`, `id`);
  - `postWebhook($channel, $payload, $secret)` signs and posts a body; `whatsappText()`,
    `whatsappStatus()` and `whatsappPayload()` build Meta payloads;
  - `makeTemplate($tenant, [...])` creates an approved two-variable template.
- Meta calls are faked with `Http::fake(['graph.facebook.com/*' => ...])`; assert the request body with
  `Http::assertSent`. With the sync queue a queued message is delivered at once, so assert its final status.
- Quiet-hours tests set the tenant timezone and use `travelTo()`.

## Current coverage (Phase 0)

- Home page renders the `Welcome` Inertia component with shared props
- Security headers present
- `/up` health endpoint
- Test suite runs on PostgreSQL
- `autowave:health` passes (DB, Redis, cache)
