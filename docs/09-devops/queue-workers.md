# Queue workers and scheduler

- **Last updated:** 2026-10-03 (Phase 9: `ai` queue)
- **Related:** [ADR-015](../12-decisions/ADR-015-automation-engine.md), [redis.md](redis.md), [supervisor.md](supervisor.md)

Automations and messages only run when **a queue worker and the scheduler are both running**. Without the
worker nothing executes. Without the scheduler, waits never end and lost work is never recovered.

## Queues

| Queue | Env override | Jobs | Tries / backoff (set on the job) |
|---|---|---|---|
| `automation` | `AUTOMATION_QUEUE` | `RunAutomationStep` (one step of a run) | 3 / 30 s, 120 s |
| `messaging` | `MESSAGING_QUEUE` | `SendOutboundMessage` (one message) | 3 / 60 s, 300 s |
| `ai` | `AI_QUEUE` | `ExtractLeadFromConversation` (automatic lead details, delayed 120 s, unique per conversation) | 2 / 60 s |
| `default` | — | Everything else | CLI defaults |

If no worker listens on `ai`, automatic lead extraction never runs; everything else keeps working.

The connection is `QUEUE_CONNECTION=redis`. Jobs carry only a row id; all state is in PostgreSQL. So
flushing Redis loses no work: the scheduler finds the rows and dispatches them again.

## Scheduled commands (`routes/console.php`)

All run `withoutOverlapping()` and `onOneServer()` (the lock is in the Redis cache); the first two every
minute.

| Command | Does |
|---|---|
| `automation:dispatch-due` | Dispatches `pending` steps whose `run_at` has passed (waits). Re-queues steps stuck in `queued` for over 10 minutes (lost from Redis) or in `running` for over 15 minutes (crashed worker); a stuck step that has used all its tries fails the run. |
| `messaging:dispatch-pending` | Re-dispatches messages stuck in `queued` (10 min) or `sending` (15 min). |
| `education:fee-reminders` (**hourly**, Phase 10) | For every tenant with the education engine, fires `fee.due_soon` and `fee.overdue` once per unpaid instalment of an active student (claimed timestamps on `fee_instalments`, so re-runs and overlaps are safe). |

The thresholds are `stuck_queued_minutes` / `stuck_running_minutes` in `config/automation.php` and
`config/messaging.php`. A due wait starts at most about a minute late (AW-028).

## Local

`composer dev` starts the server, a queue listener on `automation,messaging,ai,default`, `schedule:work`,
logs and Vite. To run them separately:

```bash
php artisan queue:work redis --queue=automation,messaging,ai,default
php artisan schedule:work
```

`queue:listen` reloads code on every job, so use it while changing jobs. Otherwise restart `queue:work`
after code changes.

To check a flow by hand:

```bash
php artisan automation:dispatch-due      # run the scheduler step once
php artisan queue:work redis --queue=automation,messaging --stop-when-empty
```

Then open **Automations → Run history** in the business app.

## Tests

`phpunit.xml` sets `QUEUE_CONNECTION=sync`. A dispatched job runs at once (after the transaction commits
for `afterCommit` jobs), and waits stay as `pending` rows. Tests travel in time and call
`automation:dispatch-due` to end them. Sync-queue behaviour differs from Redis in one way: a job that
exhausts its tries fails immediately (AW-032).

## Production

Run the Supervisor programs in [supervisor.md](supervisor.md): the high-priority worker includes
`automation,messaging`. Add the cron entry for `schedule:run`. After every deploy:

```bash
php artisan queue:restart
```

Monitor:

- Redis queue length: `LLEN queues:automation`, `LLEN queues:messaging`.
- `failed_jobs`.
- Runs and messages with status `failed` (Run history, filter "Failed").
- Run-log warnings and errors, which are copied to the application log as `automation.<event>` with the
  tenant, run and step (e.g. `automation.step.failed`, `automation.run.failed`,
  `automation.loop_prevented`).

Horizon (AW-001) will replace the plain workers.
