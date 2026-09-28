# Automation

- **Status:** ✅ Phase 5
- **Issue(s):** AW-025 – AW-032 (limitations)
- **Last updated:** 2026-10-03 (Phase 9: message received trigger, AI actions)
- **Decision:** [ADR-015](../12-decisions/ADR-015-automation-engine.md)

## Purpose

Let a business follow up, remind and notify automatically: **when** something happens (trigger), **if**
conditions hold, **wait** if needed, then **do** something (action).

## Problem

Small businesses lose leads and appointments because nobody follows up on time. Each business type needs
different workflows, and they must be configurable without code.

## Actors

- **Owner / Manager** (`automation.*`): create, edit, pause, delete automations; inspect, retry and cancel runs.
- **Other roles:** no access by default (`automation.view` can be granted).
- **System:** domain events start runs; queue workers and the scheduler execute them.

## User Flow

1. **Automations** (nav, needs the `automation` module) lists the automations. Each card shows its steps,
   its run counts and an on/off switch. Four figures sit at the top: runs, completed, in progress and
   failed in the last 7 days.
2. **New automation** opens the builder:
   1. Name, description, "On", "Run only once per record".
   2. **When:** a trigger, grouped by Leads, Customers and Appointments. Only triggers the business can use
      are listed.
   3. **Steps**, added in any order and moved up or down:
      - **Condition:** all/any of up to 10 rules (field, check, value). Only fields for the trigger's
        records are listed.
      - **Wait:** "Wait for" N minutes, hours or days. For appointment triggers there are also "Until N
        before the appointment" and "Until N after it starts".
      - **Action:** send WhatsApp, send email, notify the team, create a follow-up task, assign the lead,
        move the lead to a stage, tag the customer. Message fields have clickable variables such as
        `{{customer.first_name}}` or `{{appointment.time}}`.
   4. Errors appear on the field that caused them (e.g. `steps.2.config.message`).
3. **Automation page:** the steps in plain language ("Wait 4 hours", "If lead stage is New") and the
   automation's recent runs.
4. **Run history** (`/automations/runs`), filterable by automation and status.
5. **Run page:**
   - each step with its status, due time, attempts and error;
   - the log ("Waiting until Thu 8 Oct, 11:00 AM", "Condition not met: lead stage is New (it is
     Contacted). The run stopped here.");
   - messages, with a "Send again" button for failed ones;
   - **Retry** for a failed run, **Cancel run** for one in progress.
6. **Timelines:**
   - Automation tasks appear as "Automation added a follow-up task due …".
   - Messages appear as "Automation sent a WhatsApp message (Welcome new leads) — simulated, not delivered".

## Rules

- **Triggers:**
  - Leads: `lead.created`, `lead.updated`, `lead.status_changed`, `lead.assigned`, `lead.converted`.
  - Customers: `customer.created`.
  - Appointments: `appointment.created`, `confirmed`, `rescheduled`, `completed`, `cancelled`, `no_show`.
  - Orders (Phase 7): `order.created`, `confirmed`, `ready`, `completed`, `cancelled`, `paid`.
  - Messages (Phase 9): `message.received` — every inbound WhatsApp/Instagram message except opt-out and
    opt-in keywords; one run per message.
  - Lead triggers need the `leads` module, customer triggers the `customers` module, appointment triggers
    the `booking` engine, order triggers the `commerce` engine, message triggers the `messaging` module.
- **Subjects:** a run is for a lead, a customer, an appointment, an order or a conversation. A step can read
  the subject and its related records: a lead's customer (once converted), an appointment's or order's
  customer, a conversation's lead and customer.
- **Conversation fields and variables:** conditions on channel, assigned and message text; variables
  `{{conversation.channel}}` and `{{message.text}}` (the latest message from the contact).
- **AI actions** (Phase 9, need the `ai` module; see [ai.md](ai.md)): fill lead details with AI, draft a
  reply with AI (a draft in the inbox, never sent), add an AI summary. They are skipped when AI is
  unavailable.
- **Order fields and variables:** conditions on order status, source, type (fulfilment), payment status and
  total; variables `{{order.number}}`, `{{order.total}}`, `{{order.items}}`, `{{order.fulfilment}}`.
- **Limits** (`config/automation.php` `limits`): 100 automations per business, 20 steps, 10 rules per
  condition, messages up to 1000 characters, waits up to 90 days.
- **At least one action** is required, and a wait cannot be the last step.
- **Conditions are checked when the step runs,** not when the trigger fires. So "wait 4 hours → if stage is
  New → create task" only acts on leads nobody has moved.
- **A run stops (cancelled) at its next step** if the business is inactive, the automation module is off,
  the automation is paused or deleted, or the lead, customer or appointment was deleted.
- **Editing an automation** affects new runs only; runs in progress keep the steps they started with.
- **Duplicates:** the same event never starts two runs of one automation. "Once per record" automations run
  at most once per lead, customer or appointment ever.
- **Loops:** automations started by other automations are allowed up to 3 levels deep.
- **Messages:** an action with no phone or email to send to is *skipped* (logged), not failed.
- **Default automations** (created for every new business with the module, and backfilled once):

  | Template | Starts | Steps |
  |---|---|---|
  | Welcome new leads on WhatsApp | Paused | lead has phone → WhatsApp |
  | Follow up on new leads | **On** | wait 4 h → stage is New → task |
  | Confirm appointments on WhatsApp | Paused | WhatsApp with date and time |
  | Appointment reminder | Paused | until 24 h before → still confirmed → WhatsApp |
  | Rebook no-shows | **On** | task "Call … to rebook" |
  | Thank customers after a visit | Paused | wait 2 h → WhatsApp |
  | Tell the team about website orders | **On** | source is website → email the owners |
  | Tell customers their order is ready | Paused | WhatsApp with order number and total |

  Templates that message customers start paused. A business type can choose its own list
  (`configuration.automation_templates`). A template the business cannot use is skipped: coaching has no
  booking engine, so it gets only the lead templates. A deleted default is never recreated.

## Database

`automations`, `automation_nodes`, `automation_runs`, `automation_jobs`, `automation_logs`,
`outbound_messages`. See [schema.md](../03-database/schema.md) and
[indexes.md](../03-database/indexes.md).

## API

Business app routes (session auth, `module:automation`):

| Method | Path | Permission |
|---|---|---|
| GET | `/automations` | automation.view |
| GET/POST | `/automations/create`, `/automations` | automation.create |
| GET | `/automations/{automation}` | automation.view |
| GET/PUT | `/automations/{automation}/edit`, `/automations/{automation}` | automation.update |
| PATCH | `/automations/{automation}/toggle` | automation.update |
| DELETE | `/automations/{automation}` | automation.delete |
| GET | `/automations/runs`, `/automations/runs/{run}` | automation.view |
| POST | `/automations/runs/{run}/retry`, `/automations/runs/{run}/cancel` | automation.update |
| POST | `/automations/messages/{message}/retry` | automation.update |

## Permissions

`automation.view`, `automation.create`, `automation.update`, `automation.delete`. Owner has all; the
Manager template role has `automation.*`.

## Events

- **Consumed:** the lead, customer, appointment and order events above (`StartAutomations`), plus
  `AppointmentRescheduled` (`RetimeAppointmentWaits` moves pending "before/after the appointment" waits).
- **Payload recorded on the run:**
  - `lead.updated`: changed fields;
  - `lead.status_changed`: from/to stage codes;
  - `lead.assigned`: assignee and whether it was automatic;
  - `lead.converted`: customer id and whether the customer was new;
  - `appointment.rescheduled`: the previous start.

## Jobs

| Job / command | Queue / schedule | Does |
|---|---|---|
| `RunAutomationStep` | `automation` (3 tries, backoff 30 s, 120 s) | Runs one step of a run |
| `SendOutboundMessage` | `messaging` (3 tries, backoff 60 s, 300 s) | Delivers one message |
| `automation:dispatch-due` | every minute | Dispatches due steps; recovers stuck queued/running steps |
| `messaging:dispatch-pending` | every minute | Recovers stuck queued/sending messages |

## UI

`resources/js/pages/business/automations/` (Index, Create, Edit, Show, Runs, RunShow) and
`resources/js/modules/automations/` (builder, condition, wait and action editors, runs table, status chip).

## Automation

This is the automation feature. Actions change records through the same domain actions as the UI
(`AssignLead`, `ChangeLeadStage`, `RecordActivity`, `MessagingService`). They raise the same events, which
is why the loop guard exists.

## Security

- Every table carries `tenant_id` with composite foreign keys. Route model binding is tenant-scoped, so
  another business's automation, run or message returns 404.
- Steps run inside the run's tenant context. A run whose subject id points at another tenant finds no
  subject and is cancelled.
- Condition values are checked against the tenant's own lists (stages, sources, services, resources,
  members).
- Message recipients are masked in logs and on the run page (`+91******3210`). Message bodies are visible to
  users with `automation.view`.
- Variables are plain text substitution; there is no code or expression evaluation.

## Testing

`tests/Feature/Automation/`:

- `AutomationEngineTest`: trigger; condition success and failure; delay plus scheduler; the §114 milestone
  (lead → queue → worker → message → log); sync chain; retry; failure logging and manual retry; duplicate
  events and queue messages; once per record; loop guard; reschedule re-timing; cancellation;
  module off; stuck recovery; no-show default.
- `AutomationHttpTest`: list, builder catalogue per business type, CRUD, toggle and delete with audit,
  validation errors, runs list/detail/cancel/retry, message retry, permissions, module gate.
- `AutomationIsolationTest`: cross-tenant events, pages, writes and forged subjects.
- `AutomationProvisioningTest`: defaults, skipped templates, business-type choice, idempotency, backfill,
  module off.

## Known Limitations

- WhatsApp is simulated (AW-025).
- Missing actions and triggers (AW-026).
- No branches (AW-027).
- Waits need the scheduler (AW-028).
- Platform email sender (AW-029).
- No retention (AW-030).
- At-least-once delivery (AW-031).
- Sync queue behaviour (AW-032).

## Future Extensions

Branching, per-automation statistics, webhook and AI actions, triggers for forms,
quiet hours, per-tenant message templates, and a test run against a sample record.
