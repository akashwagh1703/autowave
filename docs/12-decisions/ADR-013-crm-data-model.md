# ADR-013: CRM data model — configurable stages with outcomes, one activity timeline

- **Status:** Accepted
- **Date:** 2026-09-28

## Context

Phase 3 adds customers and leads (master prompt §24–§26, §35, §59). Requirements that shape the schema:

- The lifecycle New → Contacted → Qualified → Follow-up → Converted / Lost → Reactivated must be the default,
  but every business names and orders stages differently (a coaching institute says "Demo scheduled" and
  "Admitted"). "Configure, don't hard-code."
- Automations (Phase 5) need stable triggers (`lead.created`, `lead.updated`, `lead.status_changed`,
  `customer.created`) regardless of what the tenant calls its stages.
- A customer's timeline must show everything that happened, including the history of the lead(s) it came
  from. Later phases (bookings, orders, messages) will add more entry types.
- Converting a lead must be transactional and must not create duplicate customers.
- Tenant isolation must hold even if application code has a bug (ADR-011).

## Decision

1. **Tenant-owned `lead_stages` with a fixed `outcome`.** Each stage has a free `name`, `color`,
   `sort_order`, `is_active` and an `outcome` of `open`, `won` or `lost` (`StageOutcome` enum). Code only
   ever reasons about outcomes: "converted" means *moved to a won stage*, "lost" means *moved to a lost
   stage*. Tenants may rename, reorder, add and deactivate stages freely.
   - Invariant: at least one active stage per outcome (enforced by `UpdatePipeline`).
   - A stage's outcome cannot change while any lead (including soft-deleted ones) uses it, so history keeps
     its meaning.
   - Defaults come from `config/crm.php`; a business type may replace them with
     `configuration.lead_stages` / `lead_sources` in `config/catalog.php` (coaching does).
   - `ProvisionCrm` copies them into the tenant inside `CreateTenant`'s transaction. `ensureFor()`
     backfills older tenants.
2. **Reactivation is a transition, not a stage.** Moving a closed lead (won/lost) back to an open stage
   clears `converted_at` / `lost_at` / `lost_reason` and records a `reactivated` activity. There is no
   "Reactivated" stage to configure.
3. **Tenant-owned `lead_sources`**, seeded from config (§24 list), editable and deactivatable. A code is kept
   for integrations (e.g. the website form will post `source=website`).
4. **One generic `activities` table** (`type`, `body`, `metadata` jsonb, `occurred_at`, nullable `lead_id`
   and `customer_id`, nullable `user_id`). It stores both manual entries (note, call, WhatsApp, email,
   meeting) and system entries (created, updated, stage_changed, assigned, converted, lost, reactivated).
   - When a lead converts, its activities get the customer's id, so the customer timeline is a single
     indexed query on `(tenant_id, customer_id, occurred_at)`.
   - Future modules add new `type` values without a migration.
5. **Composite foreign keys everywhere.** Every cross-row reference is `(x_id, tenant_id) → x(id, tenant_id)`:
   - leads → stage, source, customer and assignee (`tenant_users`);
   - activities → lead and customer.

   A lead can never point at another tenant's row, even through a bug.
6. **Phone matching key.** `phone_normalized` (E.164-style `+<digits>`, `App\Support\Phone`) is stored next
   to the raw phone on leads and customers.
   - A partial unique index allows one live customer per phone per tenant.
   - Creating a lead links an existing customer by phone, then by email.
   - A second **open** lead with the same phone is rejected.
7. **Conversion is one action.** `ChangeLeadStage` runs in a transaction and locks the lead with
   `lockForUpdate`. On conversion it:
   - reuses the linked customer, or finds one by phone/email, or creates one (`ResolveLeadCustomer`);
   - sets `customer_id` and `converted_at`;
   - clears the follow-up and backfills activities;
   - records the activity.

   `ConvertLead` is a thin wrapper. Domain events implement `ShouldDispatchAfterCommit`, so listeners never
   see a rolled-back conversion.
8. **Business-rule violations in actions throw `ValidationException`.** Examples: duplicate open lead,
   inactive stage, assignee without permission, pipeline invariants. Controllers stay thin, and the same
   rules apply to future API and automation callers.
9. **Module gating by middleware.** `module:leads` / `module:customers` return 404 when the tenant has the
   module disabled. Tenant resolution middleware now runs before `SubstituteBindings`, so route-model
   binding for tenant-scoped models (`{lead}`, `{customer}`) happens inside the tenant context and a
   foreign id is a 404.

## Alternatives

- **Hard-coded status enum on `leads`** — simplest, but violates "configure, don't hard-code" and makes
  renaming impossible.
- **Stages with `is_won` / `is_lost` flags** — equivalent to outcomes but allows contradictory rows.
- **Separate `lead_notes`, `lead_calls`, `customer_notes` tables** — more joins for every timeline and a
  migration for every new entry type.
- **Polymorphic `subject_type/subject_id` on activities** — cannot have composite FKs, so tenant integrity
  would rely on code only.
- **Unique phone per lead** — would block legitimate repeat enquiries after a lead is closed.

## Consequences

- Automations (Phase 5) subscribe to `LeadStatusChanged` / `LeadConverted` and read the stage outcome, not
  the name.
- Reports can count "converted" across tenants with differently named pipelines.
- Deleting a membership that still has assigned leads fails on the FK; unassign first (AW-018).
- Timeline pages load at most 100 entries until pagination is added (AW-019).
- The unique phone index turns a concurrent double-conversion of two leads with the same phone into a
  database error for the second request instead of a duplicate customer (AW-020).
