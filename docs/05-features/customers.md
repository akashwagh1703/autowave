# Customers

- **Status:** ✅ Phase 3
- **Last updated:** 2026-09-28

## Purpose

One record per person the business serves, with their full history in one timeline (master prompt §26).

## User flow

- Customers are created by hand (`/customers/create`) or automatically when a lead is converted.
- The customer page shows contact details, tags, notes, their leads and the timeline.
- Team members can log notes, calls, WhatsApp messages, emails and meetings directly on a customer.

## Rules

- Phone or email is required.
- **One live customer per phone number per tenant.** Enforced by the action and a partial unique index on
  `phone_normalized`. A duplicate is rejected with a validation error on the phone field.
- Tags:
  - up to 10 per customer, 30 characters each;
  - trimmed and de-duplicated case-insensitively;
  - filterable on the list.
- Deleting a customer is a soft delete (audited as `customer.deleted`). Leads keep their link, and converted
  leads still show the (deleted) customer.

## Timeline

A customer's timeline contains:

- every activity recorded on the customer: created, updated, and manual notes, calls, WhatsApp, email and
  meetings;
- every activity of the leads converted into or linked to them (copied at conversion);
- later phases add bookings, orders and messages.

Entries show who did what, and when, in the tenant's timezone. Lead entries link back to the lead.

## List (`/customers`)

- Search: name, email, city, phone (digits match the normalised phone).
- Filter by tag.
- Sort: newest, oldest, name.
- Lead count per customer.
- Pagination: 25 per page.
- Loading, empty and error states.

## Database

`customers`, `activities` — see `docs/03-database/schema.md`.

## Routes and permissions

| Route | Permission |
|---|---|
| `GET /customers`, `GET /customers/{customer}` | `customers.view` |
| `GET /customers/create`, `POST /customers` | `customers.create` |
| `GET /customers/{customer}/edit`, `PUT /customers/{customer}`, `POST /customers/{customer}/activities` | `customers.update` |
| `DELETE /customers/{customer}` | `customers.delete` |

All customer routes return 404 when the tenant's `customers` module is disabled. Every current business type
enables it (the `leads` and `crm` modules depend on it).

## Events

`CustomerCreated` is dispatched after commit, both for manual creation and conversion. It is the future
automation trigger `customer.created`.

## Security

Same guarantees as leads: `BelongsToTenant`, composite FKs on `activities` and `leads`, and tenant-scoped
route binding. Cross-tenant read and update are covered by `CrmIsolationTest`.

## Testing

`tests/Feature/Crm/CustomerTest.php`, `CrmIsolationTest.php`, `LeadLifecycleTest.php` (conversion reuse and
creation).

## Known limitations

- No merge of duplicate customers.
- No import/export.
- No custom fields (AW-009).
- Timeline shows the latest 100 entries (AW-019).
