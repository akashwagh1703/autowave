# Lead Management

- **Status:** ✅ Phase 3
- **Last updated:** 2026-09-28

## Purpose

Capture every enquiry, follow up on time and convert enquiries into customers (master prompt §24, §25, §59).

## Actors

Owner and Manager (full access), Sales Executive (view, create, update, assign), Receptionist (view, create,
update). Permissions come from `config/rbac.php`.

## User flow

1. **Add lead** (`/leads/create`): name, phone and/or email, source, interest, estimated value, next
   follow-up, optional assignee and first note.
2. **Work the lead** (`/leads/{id}`):
   - log a note, call, WhatsApp, email or meeting (optionally setting the next follow-up);
   - move it through the pipeline;
   - reassign it;
   - use the one-tap Call / WhatsApp links.
3. **Convert** — the lead moves to the won stage and becomes a customer (existing or new).
4. **Mark lost** — with an optional reason.
5. **Reactivate** — move a closed lead back to an open stage.

## Lifecycle and stages (ADR-013)

The default pipeline is New → Contacted → Qualified → Follow-up → Converted (won) / Lost (lost).
Reactivation is the transition from a closed stage back to an open one, not a separate stage.

Each tenant edits its own copy at **Settings → CRM** (`/settings/crm`):

- Stages can be renamed, recoloured, reordered, added and deactivated.
- Each stage has an outcome: *In progress*, *Converted* or *Lost*.
- At least one active stage of each outcome must remain.
- A stage's outcome is fixed once leads use it.
- Stages and sources cannot be removed, only deactivated, so existing leads keep their history.

Business types can ship their own defaults (coaching: New enquiry → Contacted → Demo scheduled → Follow-up →
Admitted / Not interested).

## Rules

- **Phone or email is required.** Phones are normalised to `+<digits>` for matching (`App\Support\Phone`):
  - local numbers get the default country code (`AUTOWAVE_DEFAULT_COUNTRY_CODE`, default 91);
  - fewer than 7 digits do not match anything.
- **Duplicates.** A second *open* lead with the same phone is rejected with a validation error. Closed
  leads do not block a new enquiry.
- **Customer linking.** New leads link an existing customer by phone, then email.
- **Conversion** (transactional, row-locked):
  - reuses the linked or matching customer, or creates one;
  - sets `converted_at` and clears the follow-up;
  - copies the lead's timeline onto the customer.
- **Lost** sets `lost_at` and the reason and clears the follow-up. Reactivating clears closure fields.
- **Stages and sources.** New leads start in the first active open stage. Only active stages can be
  chosen. Inactive sources remain visible on existing leads.
- **Assignment.**
  - Only active members whose role can work leads (`leads.update` or Owner) can be assigned.
  - When **Auto-assign new leads** is on (tenant setting `crm.auto_assign`), unassigned new leads go to the
    eligible member with the fewest open leads; ties go to the longest-standing member.
- **Contact tracking.** Logging a call, WhatsApp, email or meeting updates `last_contacted_at`.
- **Follow-ups due** means an open lead whose `next_followup_at` is before the end of today in the tenant's
  timezone. All times are stored in UTC and shown in the tenant timezone.

## List (`/leads`)

- Pipeline chips with counts per stage (click to filter).
- Tabs: Open / Follow-ups due / Closed / All.
- Search: name, email, phone (digits match the normalised phone).
- Filters: source and assignee (me, unassigned, member).
- Sort: newest, oldest, name, follow-up, value.
- Pagination: 25 per page.
- Bulk actions on up to 100 selected leads: assign, move to stage, delete. Each action checks its own
  permission and runs in one transaction.
- The list handles loading, empty (with and without filters), error, permission and validation states.

## Database

`leads`, `lead_stages`, `lead_sources`, `activities` — see `docs/03-database/schema.md`.

## Routes and permissions

| Route | Permission |
|---|---|
| `GET /leads`, `GET /leads/{lead}` | `leads.view` |
| `GET /leads/create`, `POST /leads` | `leads.create` (setting an assignee also needs `leads.assign`) |
| `GET /leads/{lead}/edit`, `PUT /leads/{lead}`, `PATCH /leads/{lead}/stage` (incl. convert/lost), `POST /leads/{lead}/activities` | `leads.update` |
| `PATCH /leads/{lead}/assign` | `leads.assign` |
| `DELETE /leads/{lead}` | `leads.delete` |
| `POST /leads/bulk` | `leads.view` + per action: assign → `leads.assign`, stage → `leads.update`, delete → `leads.delete` |
| `GET /settings/crm` | `settings.view` |
| `PUT /settings/crm/{stages,sources,assignment}` | `settings.update` |

All lead routes return 404 when the tenant's `leads` module is disabled.

## Events

All events are dispatched after commit:

- `LeadCreated`
- `LeadUpdated` (with the changed fields)
- `LeadStatusChanged` (from and to stage)
- `LeadAssigned`
- `LeadConverted` (with the customer and whether it was created)

These are the future automation triggers `lead.created`, `lead.updated` and `lead.status_changed`
(Phase 5, AW-017).

## Audit

`lead.deleted`, `leads.bulk_assign|bulk_stage|bulk_delete`, `crm.stages_updated`, `crm.sources_updated`,
`crm.assignment_updated`.

## UI

- Pages: `resources/js/pages/business/leads/*`, `pages/business/settings/Crm.jsx`.
- Components: `modules/leads/{LeadForm,StageChip}.jsx`, `modules/crm/{Timeline,ActivityComposer}.jsx`.
- The dashboard shows *New leads / New enquiries* (7 days), *Pending follow-ups* and *Potential revenue*
  (sum of open leads' estimated values), each linking to the filtered list.

## Security

- All CRM models use `BelongsToTenant` (fail-closed).
- Composite FKs make cross-tenant references impossible in the database.
- Route model binding runs inside the tenant context, so another tenant's id returns 404.
- Bulk actions re-select ids through the tenant scope.

## Testing

`tests/Feature/Crm/*`:

- isolation (including "Tenant A cannot update Tenant B lead");
- lifecycle and conversion;
- assignment;
- HTTP, filters and bulk actions;
- settings, provisioning, dashboard metrics and phone normalisation.

## Known limitations

- No kanban drag-and-drop (AW-015).
- No "own leads only" visibility (AW-016).
- `campaign_id` arrives with campaigns (AW-014).
- Timeline shows the latest 100 entries (AW-019).
- No import/export yet.
