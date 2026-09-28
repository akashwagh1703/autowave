# Booking

- **Status:** ✅ Phase 4 (staff-side booking)
- **Last updated:** 2026-10-04 (turf rates and advances, Phase 10)

## Purpose

Staff book, move and close appointments for customers on bookable resources, and double-booking is
impossible (master prompt §28, §33, §87). The decisions behind the design are in
`docs/12-decisions/ADR-014-booking-model.md`.

Booking belongs to the **booking engine**: beauty salon, turf, clinic and AutoWave internal.

## Concepts

- **Resource:** whatever is booked, such as a stylist, doctor, pitch or sales executive.
  - The tenant chooses the word (setting `booking_resource_label`: Staff, Turf, Doctor…). Navigation, forms
    and the calendar use it.
  - A resource can be linked to one team member, which gives that member a "My schedule" filter.
- **Working hours:** weekly windows per resource in the tenant's local time, up to 4 per day.
- **Time off:** dated periods when a resource is unavailable (leave, maintenance).
- **Appointment:** customer + resource + optional service + start/end, with a status.

## User flow

1. **Calendar (`/appointments`):**
   - a day view with one column per resource;
   - working hours are shaded and time off is hatched;
   - a red line shows the current time;
   - clicking an empty spot opens the booking form at that time;
   - the "Mine" filter shows only the staff member's own column;
   - summary counts (booked, pending, completed, cancelled);
   - an optional "show cancelled" toggle.
2. **List (`/appointments/list`):**
   - ranges: upcoming, today, past, all;
   - filters: status, resource, service; search by customer name or phone;
   - bulk confirm, complete, no-show and cancel. Ineligible rows are skipped and counted.
3. **Book (`/appointments/create`):**
   - customer: search existing customers, or enter name and phone (an existing customer with the same phone
     is reused);
   - resource and service (only resources that offer the service are listed);
   - date and a slot picker grouped into morning, afternoon and evening. Staff can also type a time and tick
     "allow outside working hours";
   - price (defaults to the service price), notes and status (pending or confirmed);
   - opened from a customer page, the calendar or a resource page, it arrives pre-filled.
4. **Appointment page:**
   - status actions (confirm, mark completed, mark no-show; the last two only after the start time);
   - reschedule (slot picker; can switch resource);
   - cancel with a reason;
   - edit price and notes;
   - quick call and WhatsApp buttons for the customer;
   - the appointment's history.
5. **Resources (`/resources`, labelled with the tenant's plural label):**
   - list with this week's appointment counts;
   - create or edit with services offered, working hours editor, colour and active flag;
   - detail page with upcoming appointments and time off (add or remove).
6. **Settings (`/settings/booking`):** slot interval, auto-confirm, resource label, default working hours
   for new resources.
7. **Customer page:** an appointments card and a "Book" button. Appointment entries appear on the customer
   timeline.

## Rules

- **No overlaps per resource.** Pending, confirmed and completed appointments hold their time; cancelled
  and no-show release it. Back-to-back appointments are allowed.
- A booking must be:
  - in the future (seconds are dropped);
  - inside the resource's working hours, unless staff explicitly allow outside hours;
  - outside the resource's time off;
  - on an active resource.
- Duration: 5–720 minutes. It comes from the service unless one is entered.
- A service must be active and offered by the chosen resource.
- New appointments are **confirmed**, or **pending** when auto-confirm is off. Staff may choose either.
- Lifecycle: pending → confirmed → completed / cancelled / no-show. Pending may also go directly to
  completed, cancelled or no-show. Final statuses cannot change. Completed and no-show need the start time
  to have passed.
- Only pending or confirmed appointments can be rescheduled. Moving to another resource requires that it
  offers the service.
- Slot interval: one of 5, 10, 15, 20, 30, 45, 60, 90 or 120 minutes.
  - Default 15. A turf defaults to 60 with 06:00–23:00 hours.
  - Default hours for new resources: Monday–Saturday 10:00–20:00.
- A resource with upcoming pending or confirmed appointments cannot be deleted. Time off cannot overlap
  pending or confirmed appointments.
- Changing working hours never touches existing appointments.

## Time zones

- Working hours and every date or time typed in the app are in the tenant's timezone (`tenants.timezone`).
- Appointments and time off are stored in UTC.
- Slots are built from each local date, so daylight-saving changes are handled. Tested with
  America/New_York across the November change.

## Double-booking protection

See ADR-014. In short, each booking runs in one transaction that:

1. locks the resource row;
2. re-checks availability under the lock;
3. inserts, protected by the `appointments_no_overlap` exclusion constraint.

A constraint violation becomes the message "This time was just booked. Choose another time."

## Rates and advances (turf pricing, Phase 10)

Bookings without a service (turf slots) are priced from the resource ([ADR-020](../12-decisions/ADR-020-additional-verticals.md)):

- **Resource form → Rates:** a base hourly rate and up to `booking.pricing.max_rates` named rates, each
  with weekdays and a local time range (`to` may be 24:00), e.g. Peak Mon–Fri 18:00–23:00 at 1,400 and
  Weekend Sat–Sun 06:00–24:00 at 1,600. Errors are keyed `rates.{index}.{field}`.
- **Quote:** each minute is priced by the first matching rate, otherwise the base rate. 17:30–18:30 with
  peak from 18:00 costs half an hour at each rate. If any minute has no rate (no base rate and outside
  every named rate), there is no quote.
- **Price on the booking:** `BookAppointment` stores the quote unless the team enters a price. The team
  slot picker and the website show the price per start time; with "any turf" the website shows the
  lowest price and "from" when turfs differ (`price_varies`).
- **Advances:** the appointment page records payments (amount, method, reference) into
  `appointment_payments`; `amount_paid` and the balance are recomputed from the rows under a lock.
  A payment needs a price, cannot exceed the balance, and cancelled appointments take none. The price
  cannot be lowered below the amount paid. Removing a payment is allowed.

| Route | Permission |
|---|---|
| `POST /appointments/{appointment}/payments` | `appointments.update` |
| `DELETE /appointments/{appointment}/payments/{payment}` | `appointments.update` |

Timeline: `payment_recorded`, `payment_removed`. Tests: `tests/Feature/Booking/TurfPricingTest.php`.

## Database

`booking_resources` (with `hourly_rate`, `rates`), `resource_working_hours`, `resource_time_off`,
`booking_resource_service`, `appointments` (with `amount_paid`), `appointment_payments`,
`activities.appointment_id`. See `docs/03-database/schema.md`.

## Routes and permissions

All routes return 404 when the tenant has no `booking` engine.

| Route | Permission |
|---|---|
| `GET /appointments`, `/appointments/list`, `/appointments/{appointment}`, `/appointments/availability` | `appointments.view` |
| `GET /appointments/create`, `POST /appointments`, `GET /appointments/customers` | `appointments.create` |
| `PUT /appointments/{appointment}`, `PATCH /appointments/{appointment}/reschedule` | `appointments.update` |
| `PATCH /appointments/{appointment}/status`, `POST /appointments/bulk` | `appointments.cancel` to cancel, otherwise `appointments.update` |
| `GET /resources`, `/resources/{bookingResource}` | `resources.view` |
| Create, edit and delete resources; add and remove time off | `resources.manage` |
| `GET /settings/booking` / `PUT /settings/booking` | `settings.view` / `settings.update` |

Default roles:

| Role | Access |
|---|---|
| Owner | Everything |
| Manager | Full appointments, services and resources; settings are view-only |
| Receptionist | Full appointments; view services and resources |
| Sales executive | View and create appointments |
| Staff | View appointments and mark them confirmed, completed or no-show (cannot cancel) |
| Accountant | No appointments; views services |

## Events (automation triggers)

Dispatched after commit from `app/Domain/Booking/Events`:

| Event | Trigger |
|---|---|
| `AppointmentCreated` | `appointment.created` |
| `AppointmentConfirmed` | `appointment.confirmed` (also fired when a booking is created confirmed) |
| `AppointmentCompleted` | `appointment.completed` |
| `AppointmentCancelled` | `appointment.cancelled` |
| `AppointmentNoShow` | `appointment.no_show` |
| `AppointmentRescheduled` | Carries the previous start and resource |

Nothing listens yet. Listeners come with Phase 5 (AW-024).

## Timeline and audit

- **Activities** (visible on the appointment and the customer): `appointment_booked`,
  `appointment_rescheduled`, `appointment_confirmed`, `appointment_completed`, `appointment_cancelled` (the
  reason is the body), `appointment_no_show`, `appointment_updated`.
- **Audit log:** `booking_resource.deleted`, `booking_resource.time_off_added` / `_removed`,
  `booking.settings_updated`, `appointments.bulk_*`.

## Dashboard widgets

Shown when listed in the business type's `dashboard_widgets` and the user can view appointments:

- appointments or bookings today;
- free slots left today;
- no-shows (30 days);
- cancellations (7 days);
- repeat customers (2+ completed visits).

Money widgets also need `reports.view`: revenue today and service sales over 30 days, both from completed
appointments.

## Security

- `BelongsToTenant` on every model, with composite FKs on every cross-row reference, including the pivot
  and `activities`.
- Route binding happens inside the tenant, so another tenant's ids return 404.
- Covered by `BookingIsolationTest`: read, update, reschedule, cancel, bulk, lists, customer search,
  availability, booking with foreign resource, service or customer ids, and DB-level FKs.

## Testing

`tests/Feature/Booking/`:

- `AppointmentBookingTest`: create, duplicate, overlap, unavailable slots, constraint, and a simulated
  concurrent insert;
- `AppointmentLifecycleTest`: reschedule, cancel, confirm, complete, no-show, HTTP and permissions;
- `AvailabilityTest`: slots, split shifts, time off, past times, turf interval, timezone and DST;
- `BookingResourceTest`, `ServiceTest`, `BookingIsolationTest`, `BookingHttpTest` (including the §113
  milestone and engine 404s), `BookingSettingsTest`, `BookingMetricsTest`, `BookingProvisioningTest`.

True parallel requests cannot run inside the transactional test harness. The concurrency test inserts a
conflicting row between the availability check and the insert, and asserts that the constraint rejects the
booking.

## Known limitations

- Staff-side booking only. Online booking from the tenant website comes in Phase 6.
- No "any available staff" option, buffer time between appointments, recurring appointments, group or
  multi-resource bookings, or waitlist.
- No reminders or confirmations are sent until automations and messaging (Phase 5/6).
- Staff see the whole calendar. "My schedule" is a filter, not a permission (AW-022).
- Calendar is a day view; there is no week or month grid.
- Revenue counts completed appointments only. Payments are manual (advances, Phase 10); there are no
  invoices or online payments.
