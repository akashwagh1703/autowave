# ADR-020: Additional verticals — coaching, cafe, turf pricing, Local Commerce (Phase 10)

- **Status:** Accepted
- **Date:** 2026-10-04
- **Builds on:** ADR-005 (business types and engines), ADR-013 (CRM), ADR-014 (booking), ADR-015
  (automation), ADR-016 (website builder), ADR-017 (commerce)

## Context

Phase 10 (master prompt §8, §9, §11–13) turns the remaining business types into complete products.
Decisions taken with the product owner:

- **All four verticals in one phase.**
- **Coaching (full):** courses and batches; students are customers; admission from an enquiry (lead);
  attendance; fees with instalments, manual payments and due reminders; demo classes.
- **Cafe (reuse commerce):** the menu is the commerce catalogue (food type, "available now"); tables and
  reservations; dine-in orders by table; a kitchen screen on the commerce order pipeline.
- **Turf (rates):** hourly, peak and weekend rates per turf; the price shows on the website and is stored
  on the booking; an optional advance is recorded as a payment.
- **Local store (polish):** renamed "Local Commerce"; coupon codes; repeat-customer and top-product
  widgets; demo data.

## Decision

### Engines and catalogue

- Two new engines: `education` (coaching) and `food` (cafe). The cafe preset is `food` + `commerce`;
  coaching is `education` only; turf stays `booking` only.
- Catalogue changes are made **in place on version 1.0** of each business type (the catalogue sync is
  keyed by code and version), so existing tenants get the rename and the new modules. New modules for
  existing tenants are switched on once by `TenantBackfillSeeder::NEW_MODULES` (`ai`, `offers`).
- The coaching preset ships its own lead stages (New enquiry, Contacted, Demo scheduled, Follow-up,
  Admitted, Not interested). Admission converts a lead to the tenant's won stage ("Admitted").
- New website sections: `courses` (coaching) and `reservation` (cafe). New dashboard widgets: `students`,
  `admissions`, `fees_due`, `demo_classes`, `reservations_today`, `kitchen_queue`, `repeat_customers`,
  `top_products`.

### Education

- **Students are customers.** An `enrolment` links a customer to a batch, optionally keeping the lead it
  came from. One active enrolment per student and batch (partial unique index).
- **Admission** (`AdmitStudent`) runs in one transaction: lock the batch and check capacity; resolve the
  student (convert the lead, reuse a customer by phone, or create one); create the enrolment and its fee
  plan; record an optional first payment.
- **Fees:** `fee_instalments` (due date, amount, amount paid) and `fee_payments`. Payments are allocated
  to instalments oldest first by `FeeLedger`, recomputed from the payment rows under a row lock. The fee
  plan can be edited later; sums must equal fee minus discount. Money uses bcmath end to end.
- **Reminders:** `education:fee-reminders` (hourly, one server) fires `fee.due_soon` once per instalment
  inside the tenant's reminder window and `fee.overdue` once the day after the due date. The timestamps
  are claimed with a conditional update, so overlapping runs never fire twice. Default templates: a
  paused WhatsApp reminder, and an active "collect overdue fee" task.
- **Attendance:** one `class_session` per batch and date (created on first save), one
  `attendance_record` per active student (present, absent, late, excused). Corrections overwrite; future
  dates are refused.
- **Demo classes** belong to a lead. Scheduling moves the lead to the `demo_scheduled` stage when the
  tenant has one; a demo can be marked attended only after it starts.

### Food

- **Menu = products.** `products.food_type` (veg, non-veg, egg) and `is_available` ("sold out today",
  separate from `is_active`). Unavailable items stay on the menu, marked, and cannot be ordered.
- **Tables and reservations.** `reservations_no_overlap` excludes two live reservations
  (pending, confirmed, seated) overlapping on one table, with the same `int8range` + `tsrange` GiST trick
  as appointments (no `btree_gist` on PostgreSQL 11).
- **Website reservations** use the tenant's hours, slot interval, notice and horizon
  (`food` tenant setting, Settings → Reservations). They never pick a table and start pending unless
  auto-confirm is on. Table availability is not promised online; the team assigns a table on
  confirmation. Rate-limited per IP and business.
- **Dine-in** is a commerce fulfilment (`dine_in`) with an optional `dining_table_id` and no customer
  required (walk-ins). Tables with an open order cannot be deleted. Items can be added to an open
  dine-in order (`AddOrderItems`); prices are server-side, stock is taken as for a new order.
- **Kitchen screen (KOT):** `order_items.kitchen_status` (`queued` → `ready`). Confirmed and ready
  orders with queued items show oldest first, refreshed on a timer. Marking a ticket ready moves a
  confirmed order to Ready, which fires `order.ready`.

### Turf pricing

- `booking_resources.hourly_rate` and `rates` (JSON list of `{label, weekdays, from, to, hourly_rate}`,
  tenant-local times, `to` may be 24:00). Each minute of a booking is priced by the first matching rate,
  otherwise the base rate; if any minute has no rate, the quote is null (the team enters a price).
  17:30–18:30 with peak from 18:00 costs half an hour at each rate.
- `BookAppointment` stores the quote as the price unless a price is given. Website slots show the
  lowest price among free turfs, with `price_varies` when they differ.
- **Advances:** `appointment_payments` and `appointments.amount_paid` (recomputed from rows under a lock).
  Payments need a price, cannot exceed the balance, and are refused on cancelled appointments. The price
  cannot drop below the amount already paid.

### Coupons (offers module)

- `coupons`: percent (optionally capped) or fixed; minimum subtotal; start/end; usage limit;
  online or team-only; active switch; soft delete. Codes are upper-cased and unique per tenant.
- A coupon fills the order's existing `discount` (added to any manual discount, never more than the
  subtotal). The order keeps `coupon_id` and the code as typed. A use is claimed under a row lock when
  the order is placed, so a limit can never be exceeded; cancelling the order releases it.
- The website cart checks the code through the server quote; the order is priced again on submit.

### Automations, permissions, AI

- Triggers: `enrolment.created`, `fee.due_soon`, `fee.overdue`, `demo.scheduled`,
  `reservation.created`, `reservation.confirmed`, `reservation.cancelled` — each needs its engine.
  Templates are provisioned only when the business can use them (engine and module).
- Permission groups: `courses`, `students` (view, admit, update, attendance), `fees` (view, collect,
  manage), `reservations` (view, manage). Backfilled once for existing roles.
- Assistant tools `students` and `reservations`, offered with the engine and permission; names only,
  no contact details.

## Alternatives

- **Separate menu table for cafes** — rejected; the commerce catalogue, stock and orders already do the
  job, and one order pipeline keeps reports and automations uniform.
- **Students as their own entity** — rejected; customers already carry contact, consent, timeline and
  messaging.
- **Online table allocation** — deferred; guests request a time, the team seats them.
- **Online advance payments (gateway)** — out of scope; payments stay manual (ADR-017).
- **A new business-type version for the rename** — rejected; existing tenants would stay on the old
  version without the new modules.

## Consequences

- Two engines and four groups of tables are added; every new table is tenant-scoped with composite
  foreign keys to its parents.
- Existing tenants keep their website sections; the new `courses` and `reservation` sections are added
  only for new tenants (AW-059).
- Known gaps are tracked as AW-059 onwards (`docs/00-overview/known-issues.md`).
