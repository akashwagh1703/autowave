# Current State

_Last updated: 2026-09-28 — end of Phase 4 (Services + Booking)._

This document describes what **actually exists** in the repository today. Planned work is in
[roadmap.md](roadmap.md).

## Implemented

### Phase 0 — foundation
- Laravel 13 (PHP 8.4), Inertia v3 + React 19 (JSX), Vite 7, Tailwind v4, MUI v9 (CSS layers), PostgreSQL,
  Redis (cache + queue), `autowave:health`, `/up`, security headers, CI, docs/ADRs.

### Phase 1 — platform foundation
- **Host-based routing** (ADR-007/011): marketing, app, admin hosts; `www` redirect; every other host is a
  tenant website resolved via `domains`.
- **Authentication** (ADR-010): Fortify on the app host — login/logout, remember me, registration, email
  verification, password reset, suspended-account blocking, `last_login_at`. Separate Super Admin login.
- **Tenancy**: `tenants`, `TenantContext` (scoped), fail-closed `BelongsToTenant` scope with cross-tenant
  write guards, `ResolveTenantFromDomain`, `ResolveTenantFromMembership`, workspace switching.
- **Membership**: `tenant_users` with status; users can belong to several businesses.
- **RBAC**: permission catalogue + template roles from `config/rbac.php`, per-tenant role copies, composite
  FKs, `PermissionResolver`, Gates for every permission, `permissions` shared to React.
- **Catalogue**: business types (versioned presets: beauty_salon, turf, coaching, cafe, clinic, local_store,
  autowave_internal), engines, modules with dependencies; `ModuleManager` / `EngineManager`.
- **Domains**: `domains` table, `DomainResolver` with cache, default `{slug}.{root}` subdomain.
- **CreateTenant** action provisioning roles, modules, engines, settings and domain atomically.
- **Audit log** (`audit_logs`) for admin login and tenant suspend/activate.
- **Super Admin**: dashboard (stats, business types) and tenant list with search, filter, suspend/activate.
- **UI**: auth pages, business dashboard (business-type widgets as placeholders), workspaces, read-only
  settings, admin pages, tenant website placeholder, error page.
- **Seeders**: catalogue, RBAC, platform admin, AutoWave Internal tenant, local demo tenants.
- **Tests**: 76 feature tests (auth, isolation, domains, membership, RBAC, modules, tenant creation, admin).
- **Shared remote dev DB** (PostgreSQL 11.20; host in the team's private `.env`) migrated and seeded.

### Phase 2 — one-click onboarding
- **Onboarding wizard** (`/onboarding`): business type → details (live slug check) → capabilities
  (dependency-aware) → branding → website template → review. New users without a business are sent there;
  existing members can add businesses from **Your businesses** (per-user cap, rate limited).
- **One transaction** creates tenant, owner role, modules, engines, branding/profile settings, website and
  default subdomain; audit `tenant.created`; session switches to the new business.
- **Website foundation** (ADR-012): `website_templates` (Modern, Premium, Minimal, Elegant, Corporate),
  `website_configs`, `website_sections`; `ProvisionWebsite` (idempotent, backfills older tenants); public site
  renders header, hero, about, contact and footer with the template theme and brand colour.
- **Settings** page shows contact details, tagline and website template/sections.
- **Tests**: 98 feature tests (Phase 2 adds onboarding and website provisioning suites).

### Phase 3 — CRM (ADR-013)
- **Leads**:
  - List with pipeline counts, tabs (open, follow-ups due, closed, all), search, filters, sorting,
    pagination and bulk assign/stage/delete.
  - Create/edit and a detail page with stage bar, convert, mark lost, reactivate, reassign, activity
    logging and timeline.
  - Duplicate open-lead check by phone.
- **Configurable pipeline** (`/settings/crm`):
  - Per-tenant stages (name, colour, order, outcome open/won/lost, active) and lead sources.
  - Auto-assignment toggle (fewest open leads).
  - Defaults from `config/crm.php` or the business type (coaching has its own). `ProvisionCrm` runs in
    `CreateTenant` and backfills older tenants.
- **Customers**: list (search, tag filter, sort), create/edit/delete, detail page with leads and a unified
  timeline, one customer per phone.
- **Conversion**: one transaction resolves or creates the customer, moves the lead to the won stage and
  copies its history to the customer.
- **Events** (after commit): `LeadCreated`, `LeadUpdated`, `LeadStatusChanged`, `LeadAssigned`,
  `LeadConverted`, `CustomerCreated`.
- **Module gating** (`module:` middleware → 404). Tenant resolution now runs before route model binding.
- **Dashboard**: live metrics — new leads/enquiries (7 days), pending follow-ups, potential revenue, new
  customers.
- **Demo data**: local-only `DemoCrmSeeder` for ABC Salon.
- **Tests**: 172 feature tests. Phase 3 adds 74 across CRM isolation, lifecycle, assignment, HTTP/bulk,
  customers, settings, provisioning, metrics and phone normalisation.

### Phase 4 — Services + Booking (ADR-014)
- **Services** (service engine: salon, clinic, internal):
  - catalogue with categories, duration, price, active flag, and who offers each service;
  - list with category, status and search filters, sorting and bulk actions;
  - default categories per business type; categories are managed inline.
- **Resources** (booking engine: salon, turf, clinic, internal): staff members or things such as turfs and
  courts.
  - The label is configurable per tenant (Staff, Turf, Doctor…).
  - A resource can be linked to a team member.
  - Weekly working hours with split shifts, dated time off, and the services it offers.
- **Appointments:**
  - a day calendar with a column per resource, working hours, time off, a now-line, click-to-book and a
    "Mine" filter;
  - a list with ranges, filters, search and bulk status changes;
  - a booking form with customer search or inline new customer, and a slot picker;
  - an appointment page with confirm, complete, no-show, cancel with reason, reschedule (including to
    another resource), price and notes, and history.
- **Double-booking prevention:** resource row lock, availability re-check, and the `appointments_no_overlap`
  exclusion constraint (range form, no `btree_gist`). Concurrent conflicts become a friendly validation
  error.
- **Time zones:** working hours are in the tenant's local time and storage is UTC. Slots are built per local
  date, so daylight-saving changes are handled.
- **Lifecycle events** (after commit): `AppointmentCreated`, `Confirmed`, `Completed`, `Cancelled`,
  `NoShow`, `Rescheduled`. Appointment entries appear on the customer timeline.
- **Booking settings** (`/settings/booking`): slot interval, auto-confirm, resource label, default hours.
- **Engine gating** (`engine:` middleware returns 404). Navigation follows the tenant's engines.
- **Dashboard:** appointments or bookings today, free slots today, no-shows, cancellations, repeat
  customers. Revenue today and service sales need `reports.view`.
- **Customer page:** appointments card and a "Book" button.
- **Backfill:** `TenantBackfillSeeder` adds booking settings, service categories and the new `services` and
  `resources` permissions to existing tenants, once.
- **Demo data:** the local-only `DemoBookingSeeder` adds services, two stylists and appointments for ABC
  Salon, and turfs for ABC Turf.
- **Tests:** 251 feature tests. Phase 4 adds 79 in `tests/Feature/Booking`: booking, lifecycle,
  availability and timezone, resources, services, isolation, HTTP and permissions, settings, metrics, and
  provisioning.

## In progress

- Nothing. Phase 4 is complete and awaiting approval before Phase 5 (Automation engine).

## Not implemented

- **Platform:** invitations and member management, role editor, editable business settings, website editor,
  logo upload, custom domain UI, default automations, feature flags, custom fields, commerce, automation,
  messaging, AI, analytics, billing.
- **CRM gaps:** kanban board, own-leads visibility, campaigns, import/export.
- **Booking gaps:** online booking (Phase 6), reminders, "any staff", buffers, recurring or group bookings,
  week view, packages, scoped staff visibility.

## Known technical debt

See [known-issues.md](known-issues.md) (AW-001 → AW-024).
