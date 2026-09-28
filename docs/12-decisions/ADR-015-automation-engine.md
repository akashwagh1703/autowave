# ADR-015: Automation engine — one queued job per step, database-backed waits, idempotent actions

- **Status:** Accepted
- **Date:** 2026-09-29

## Context

Phase 5 adds automations (master prompt §29, §42, §88, §114): trigger → condition → wait → action, run on
Redis queues with retries, logs and duplicate-execution prevention. Requirements that shape the design:

- Every business type must be able to build its own workflows from catalogues, not code ("configure, don't
  hard-code"). A salon's appointment reminder and a coaching institute's lead follow-up use the same engine.
- Waits can be hours or days long (a reminder 24 hours before an appointment). They must survive deploys,
  worker restarts and Redis restarts, and must move when an appointment is rescheduled.
- A step must never run twice, even when the queue delivers a job twice, a worker crashes mid-step or the
  same domain event fires twice. A customer must never receive the same WhatsApp message twice.
- Automations can trigger each other (an action moves a lead's stage, which is itself a trigger). Loops
  must stop.
- Real WhatsApp and email providers come later (Phase 7). The engine must be testable end to end now.
- The queue can be down; work must be delayed, not lost.

## Decision

1. **Definitions are catalogues in `config/automation.php`.** Triggers, condition fields and operators,
   actions, message variables, wait modes, limits and default templates live in config. `AutomationCatalog`
   filters them by the tenant's enabled modules and engines, so a business without the booking engine never
   sees appointment triggers. Every definition is validated by `DefinitionValidator` against that filtered
   catalogue, both in the builder and when provisioning templates.
2. **Tables.**
   - `automations` + `automation_nodes` hold the definition (ordered steps, jsonb config).
   - `automation_runs`: one per automation per event. It **snapshots the steps** (jsonb), so editing an
     automation never changes a run in flight.
   - `automation_jobs`: one row per executed step, with `run_at`, status and attempts. This row, not the
     Redis message, is the source of truth.
   - `automation_logs`: an append-only, human-readable log per run.
   - `outbound_messages`: every message the platform sends.
   - All tables carry `tenant_id` with composite foreign keys (ADR-011).
3. **One queued job per step.** `StepDispatcher` creates the step row (unique `(run, step_index)`), marks it
   `queued` and dispatches `RunAutomationStep(id)` on the `automation` queue **after commit**. `StepRunner`:
   1. claims the row with a conditional update (`pending|queued → running`); a duplicate delivery finds
      nothing to claim and stops;
   2. cancels the run if the business is inactive, the module is off, the automation was paused or deleted,
      or the subject no longer exists;
   3. runs the step. A condition either continues or skips the run. A wait schedules the next step. An
      action runs;
   4. completes the row and schedules the next step (or completes the run) in one transaction.
4. **Waits are rows, not delayed queue messages.** A wait creates the next step row with a future `run_at`.
   The scheduler command `automation:dispatch-due` (every minute, `withoutOverlapping`, `onOneServer`)
   dispatches due rows using a partial index on `run_at WHERE status = 'pending'`. It also recovers steps
   left `queued` or `running` longer than a threshold (lost queue message, crashed worker), failing them once
   their attempts are used up. Relative waits (`before_start` / `after_start`) store the anchor
   (`appointment_start`) and offset. `RetimeAppointmentWaits` moves pending rows when an
   `AppointmentRescheduled` event arrives.
5. **Duplicate prevention at every layer.**
   - Event: `automation_runs` is unique on `(tenant_id, automation_id, dedupe_key)`. Events that happen once
     per record use natural keys (`lead:12`, `appointment:40`, `appointment:40:<new start>` for
     reschedules). Repeatable events get a unique key per occurrence. `once_per_subject` automations key on
     the subject alone.
   - Step: unique `(automation_run_id, step_index)` plus the claim.
   - Action: every action receives the idempotency key `automation:{run}:step:{i}`. Messages are unique on
     `(tenant_id, idempotency_key)`, so a retried step returns the existing message. Tasks check the key in
     their metadata.
   - Inserts that may lose a race run in a savepoint (`DB::transaction`), so a unique violation never aborts
     the caller's transaction.
6. **Retries.**
   - A failing action puts its row back to `queued`, logs `step.failed` (warning) and rethrows. Laravel
     retries with `automation.tries` (3) and `automation.backoff` ([30, 120] seconds).
   - After the last attempt `failed()` marks the step and the run `failed` and logs `run.failed` (error, also
     written to the application log).
   - A user can retry a failed run from the step that failed, or cancel a run in progress.
   - Actions return `skipped` (not an exception) for outcomes a retry cannot fix, such as "no phone number".
7. **Loop guard.** While a step runs, Laravel `Context` holds `automation_depth = run.depth + 1`. Runs
   started by events raised inside the step inherit that depth. The resolver starts nothing at
   `automation.max_depth` (3) or deeper and logs `automation.loop_prevented`.
8. **Messaging behind a provider interface.**
   - `MessagingService::queue()` is the only way to send. It is idempotent and delivers on the `messaging`
     queue.
   - `deliver()` claims `queued → sending`, calls the channel's provider, then records the message on the
     lead or customer timeline and in the run log.
   - Providers today: `log` for WhatsApp (simulated: recorded, marked "simulated", not delivered) and `mail`
     for email. Phase 7 adds a real WhatsApp provider by configuration.
   - `messaging:dispatch-pending` recovers messages left behind.
9. **Default automations per business type.**
   - `ProvisionAutomations` creates `automation.default_templates`, or the business type's
     `configuration.automation_templates`, at tenant creation and in `TenantBackfillSeeder`.
   - Templates the tenant cannot use (missing engine, stage or module) are skipped.
   - A template is created once per tenant and never recreated after the owner deletes it.
   - **Templates that message customers start paused**, so nothing is sent until the owner turns them on.
10. **Events start automations synchronously after commit.** `StartAutomations` maps 12 domain events to
    triggers. It runs in the request after the domain transaction commits, only creates rows and dispatches
    jobs, and reports but swallows its own errors, so an automation problem never breaks a user action.

## Alternatives

- **Delayed queue jobs for waits (`->delay()`).** Waits would live only in Redis. They would be lost on a
  flush, invisible to the run page, impossible to re-time on reschedule, and Redis would hold days of
  delayed jobs.
- **One job for the whole run.** A multi-day wait would hold a worker. A retry would re-run earlier actions
  (double messages).
- **A workflow engine or package (Temporal, n8n, workflow packages).** It adds an operational dependency and
  a second tenancy model for a linear trigger/condition/wait/action shape that fits in four tables.
- **Graph-shaped automations (branches).** They are not required by §29. `automation_nodes.position` keeps
  the door open, since branches can later be added as node references without migrating runs (steps are
  snapshots).
- **Unique jobs (`ShouldBeUnique`) for duplicate prevention.** These are Redis locks with a TTL, not a
  guarantee. The database claim and unique keys are.

## Consequences

- Production needs a worker for `automation` and `messaging` and the scheduler cron (`schedule:run` every
  minute). Without the scheduler, waits never finish. See `docs/09-devops/queue-workers.md`.
- Steps due at the same minute run within about a minute of `run_at`, not to the second.
- Editing an automation affects only new runs. Pausing or deleting it cancels its runs at their next step.
- A step interrupted after its side effect but before it was marked complete is retried. Idempotency keys
  prevent a second message or task. A provider that accepted a message but timed out is retried, which the
  real provider integration must de-duplicate with the message id (Phase 7).
- In tests (`QUEUE_CONNECTION=sync`) the whole chain runs inline, so the default active automations fire in
  every test that creates leads or no-shows.
- The run log grows with activity; retention is not implemented yet (AW-030).
