# ADR-014: Booking model — generic resources, range exclusion constraint, locked bookings

- **Status:** Accepted
- **Date:** 2026-09-28

## Context

Phase 4 adds services and appointments (master prompt §27, §28, §33, §59, §87). Requirements that shape the
design:

- The same engine must serve a salon (book a stylist), a clinic (a doctor), a turf (a pitch) and AutoWave
  itself (a sales executive's demo slot). Some bookables are people, some are things.
- A turf has no "services"; a salon books a service *with* someone. Engines are toggled per business type
  (ADR-005): `service` and `booking` are separate engines.
- Double-booking must be impossible, not just unlikely. The prompt asks for constraints, locking and
  transactions, and for a test with concurrent attempts.
- Businesses think in local wall-clock time ("Sana works 10:00–20:00, Monday to Saturday"). Storage is UTC.
- Automations (Phase 5) need stable triggers: `appointment.created`, `confirmed`, `completed`, `cancelled`,
  `no_show`.
- The shared dev database is PostgreSQL 11 and cannot install `btree_gist` (AW-006).

## Decision

1. **One generic `booking_resources` table.** A resource is anything that can be booked. It may be linked
   to a team member (`tenant_user_id`, at most one live resource per member, partial unique index) so staff
   see "my schedule".
   - What a resource is called comes from the tenant setting `booking_resource_label`, seeded from the
     business type: Staff, Turf, Doctor, Sales Executive. The UI shows that label everywhere.
   - Resources offer services through the `booking_resource_service` pivot (it carries `tenant_id` for the
     composite FKs).
2. **Services are optional on appointments.** `appointments.service_id` is nullable. With the `service`
   engine off (turf), staff enter a duration and price. With it on, the service supplies both as defaults.
   A service can only be booked on a resource that offers it.
3. **Weekly working hours plus dated time off.**
   - `resource_working_hours` stores ISO weekday + local `HH:MM` windows. Up to 4 windows per day, so split
     shifts work.
   - `resource_time_off` stores absolute UTC periods.
   - Windows are turned into instants per local date with the tenant timezone, so a daylight-saving change
     moves the UTC times, not the local ones.
   - New resources get the tenant's default hours (`booking.default_hours` setting, else
     `config/booking.php`).
4. **Double-booking is prevented three times, inside one transaction:**
   1. `SELECT … FOR UPDATE` on the resource row serialises bookings per resource.
   2. `Availability::problem()` re-checks, under the lock: working hours, time off, and overlapping
      blocking appointments.
   3. The exclusion constraint `appointments_no_overlap` is the guarantee:

      ```sql
      EXCLUDE USING gist (
          int8range(booking_resource_id, booking_resource_id, '[]') WITH &&,
          tsrange(starts_at, ends_at, '[)') WITH &&
      ) WHERE (status IN ('pending', 'confirmed', 'completed'))
      ```

      The resource id is written as a one-value range so both columns use built-in GiST range operators.
      This avoids `btree_gist`. Half-open ranges (`[)`) let back-to-back appointments touch.

   A constraint violation (SQLSTATE `23P01`) is turned into the validation error "This time was just booked.
   Choose another time." Rescheduling locks both resources in id order (no deadlock between two opposite
   moves) plus the appointment row.
5. **Status lifecycle as an enum with explicit transitions** (`AppointmentStatus`):

   | From | Allowed to |
   |---|---|
   | pending | confirmed, completed, cancelled, no_show |
   | confirmed | completed, cancelled, no_show |
   | completed, cancelled, no_show | — (final) |

   - `completed` and `no_show` need the start time to have passed.
   - Pending, confirmed and completed hold the time; cancelled and no-show release it.
   - New bookings are `confirmed`, or `pending` when the tenant turns off auto-confirm.
   - Every change records a timeline activity and dispatches an event (`ShouldDispatchAfterCommit`):
     `AppointmentCreated`, `AppointmentConfirmed`, `AppointmentCompleted`, `AppointmentCancelled`,
     `AppointmentNoShow`, `AppointmentRescheduled`.
6. **Appointments share the CRM timeline.** `activities.appointment_id` (composite FK) links entries to an
   appointment. `customer_id` is always set, so the customer timeline shows bookings without extra queries.
7. **Engine gating by middleware.** `engine:service` and `engine:booking` return 404 when the tenant's
   business type does not include the engine. This matches `module:` gating (ADR-013).
8. **Backfill for existing tenants.** `TenantBackfillSeeder` runs on every deploy. It adds:
   - missing business type configuration keys as tenant settings (`BackfillTenantSettings`);
   - default service categories;
   - the new permission groups `services` and `resources`, via
     `ProvisionTenantRoles::grantNewPermissionGroups()`. This runs once per tenant and group (recorded in
     the `rbac_backfilled_groups` setting) and skips roles that already hold any permission of the group, so
     later edits by the tenant survive.

## Alternatives

- **Separate `staff` and `rooms`/`courts` tables.** This would duplicate availability logic, and each new
  business type would need a schema change.
- **Pre-generated slot rows.** They would need regeneration on every change of hours, interval or duration,
  and could still be double-booked without a constraint.
- **`btree_gist` with `booking_resource_id WITH =`.** This is the textbook form, but it is not installable
  on the dev server. The `int8range` form is equivalent.
- **Application-level check only.** It races under concurrency. The lock alone would not protect writes
  from future code paths (imports, API, automations) that forget to take it.
- **Unique index on `(resource, starts_at)`.** It only catches identical start times, not overlaps.
- **Storing working hours in UTC.** This breaks twice a year in daylight-saving timezones and does not match
  how businesses think.

## Consequences

- Every booking path (UI today; public booking, API and automations later) must go through
  `BookAppointment` / `RescheduleAppointment`. Even if one does not, the constraint still rejects the
  overlap.
- The constraint uses `timestamp without time zone` columns holding UTC. Code must always pass UTC instants
  (the actions do).
- A resource can be booked outside working hours only through an explicit "allow outside hours" choice.
  Overlaps are never allowed.
- Changing working hours never moves or cancels existing appointments.
- Resources with upcoming appointments cannot be deleted, and time off cannot cover active appointments.
- Staff see the whole calendar. "My schedule" is a filter, not a permission (AW-022).
