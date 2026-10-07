# Services

- **Status:** ✅ Phase 4
- **Last updated:** 2026-09-28

## Purpose

The catalogue of what a business sells as time: a haircut, a consultation, a demo (master prompt §27). A
service gives a booking its default duration and price, and says which staff or resources can perform it.

Services belong to the **service engine**: beauty salon, clinic and AutoWave internal. A turf has booking but
no services, so there it books resources by duration.

## User flow

- `/services` lists services grouped by category, with search, a category filter (including
  "Uncategorised"), a status filter and sorting (category, name, price, duration, newest).
- Create or edit a service with: name, category, duration, price, active flag, description, and who offers
  it (resources, shown with the tenant's resource label).
- The edit page has an optional **Photo** (JPG, PNG or WebP; one per service, replaced or removed there).
  JPG and PNG photos show as cards in the WhatsApp assistant.
- Categories are managed inline on the list page: add, rename, delete.
- Bulk actions: activate, deactivate, delete.
- "Book" on a service opens the booking form with the service preselected.

## Rules

- Names are unique per tenant, case-insensitively, among live services. A deleted service's name can be
  reused.
- Duration: 5–720 minutes (`config/booking.php`). Price ≥ 0.
- Category and resource ids are resolved inside the tenant. A foreign id is a validation error.
- Inactive services stay on past appointments but cannot be booked.
- Deleting a service is a soft delete. It is detached from resources; past and future appointments keep
  showing its name.
- Deleting a category leaves its services uncategorised. Up to 50 categories per tenant.
- New tenants get default categories from `configuration.service_categories` in `config/catalog.php`:
  - salon: Hair, Skin, Nails, Makeup, Spa;
  - clinic: Consultation, Procedures, Diagnostics;
  - internal: Demos, Onboarding.

  These are catalogue-only and are not copied into tenant settings.

## Database

`service_categories`, `services`, `booking_resource_service`. See `docs/03-database/schema.md`.

## Routes and permissions

All routes return 404 when the tenant has no `service` engine.

| Route | Permission |
|---|---|
| `GET /services` | `services.view` |
| `GET /services/create`, `POST /services`, `POST /services/bulk` | `services.manage` |
| `GET /services/{service}/edit`, `PUT /services/{service}`, `DELETE /services/{service}` | `services.manage` |
| `POST/DELETE /services/{service}/image` (photo) | `services.manage` |
| `POST /service-categories`, `PUT/DELETE /service-categories/{category}` | `services.manage` |

## Audit

`service.deleted`, `service_category.deleted`, `services.bulk_*`.

## Testing

`tests/Feature/Booking/ServiceTest.php`, `BookingIsolationTest.php`, `BookingProvisioningTest.php`.

## Known limitations

- No packages or memberships (bundles of services) yet.
- No per-resource price or duration: every resource offering a service uses the service defaults. Staff can
  override the price on the appointment.
- No service images or online visibility settings until the website booking flow (Phase 6).
- If a tenant deletes every category, the defaults come back on the next deploy backfill (AW-023).
