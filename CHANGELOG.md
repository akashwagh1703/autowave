# Changelog

All notable user-visible changes are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Categories: Added, Changed, Fixed, Security, Deprecated, Removed.

## [Unreleased]

### Added

- Phase 4 Services + Booking:
  - Services: a catalogue with categories, duration, price and who offers each service. Search, filters,
    sorting and bulk activate, deactivate and delete. Salons and clinics start with default categories.
  - Staff and resources (named per business: Staff, Turf, Doctor…): weekly working hours with split shifts,
    time off, the services each one offers, and an optional link to a team member.
  - Appointments calendar: a day view with a column per staff member or resource, working hours, time off,
    a now-line, click-to-book and a "Mine" filter.
  - Appointment list with upcoming, today, past and all ranges, filters, search and bulk status changes.
  - Booking form: find a customer or add one on the spot, pick a service and a free slot; staff can
    deliberately book outside working hours.
  - Appointment page: confirm, mark completed, mark no-show, cancel with a reason, reschedule (also to
    another staff member), edit price and notes, and one-tap call or WhatsApp.
  - Double-booking is impossible, even when two people book the same slot at the same moment.
  - Booking settings: slot interval, automatic confirmation, what customers book (label) and default working
    hours.
  - Customer page shows the customer's appointments, and bookings appear on their timeline.
  - Dashboard shows appointments today, free slots, no-shows, cancellations, repeat customers, and (with
    report access) revenue today and service sales.
  - Existing businesses receive booking settings, service categories and the new permissions automatically.
- Phase 3 CRM:
  - Leads: add, edit, delete, search, filter by stage/source/assignee, sort, paginate, and bulk
    assign/move/delete.
  - Lead page with pipeline bar, convert to customer, mark lost (with reason), reactivate, reassign, one-tap
    call/WhatsApp, and logging of notes, calls, WhatsApp messages, emails and meetings with next follow-up.
  - "Follow-ups due" view and duplicate warning when an open lead already has the same phone number.
  - Customers: add, edit, delete, tags, search and a full timeline that includes the history of their leads.
  - Converting a lead reuses an existing customer with the same phone or email, otherwise creates one.
  - CRM settings: rename, recolour, reorder and deactivate pipeline stages and lead sources; optional
    automatic lead assignment to the least busy team member.
  - Coaching businesses get an admissions pipeline (New enquiry → Demo scheduled → Admitted).
  - Dashboard shows new leads, pending follow-ups, potential revenue and new customers.
  - Leads and Customers menu items (shown only with permission and when the feature is enabled).
- Phase 2 one-click onboarding:
  - Six-step business setup wizard: business type, details with live web-address check, capabilities,
    branding, website style and review.
  - New users without a business go straight to setup; existing users can add another business.
  - One click creates the workspace, owner role, features, branding, contact details, website and free
    subdomain.
  - Five website templates (Modern, Premium, Minimal, Elegant, Corporate); each business type recommends some.
  - Public website now shows the business's hero, about and contact sections in the chosen style and colour.
  - Settings page shows contact details, tagline and website template.
- Phase 1 platform foundation:
  - Host-based routing for marketing, business app, Super Admin and tenant websites.
  - Login, logout, remember me, registration, email verification and password reset (Laravel Fortify).
  - Separate Super Admin sign-in, dashboard and tenant list with suspend/activate.
  - Tenants, memberships, workspace switching and tenant-scoped data with fail-closed isolation.
  - Roles and permissions per business (Owner, Manager, Receptionist, Sales Executive, Staff, Accountant).
  - Business types, engines and modules catalogue with dependency checks.
  - Tenant subdomains and custom-domain resolution with caching.
  - Business dashboard, read-only settings page and placeholder public website.
  - Audit log for admin sign-in and tenant status changes.
  - Seeders for the catalogue, roles, first platform admin, AutoWave Internal tenant and local demo tenants.
- Phase 0 project foundation: Laravel 13, Inertia.js v3 + React 19, Vite, Tailwind CSS v4, MUI v9.
- PostgreSQL 17 and Redis 7 for local development via `docker-compose.yml`.
- Redis-backed cache and queue configuration.
- `php artisan autowave:health` command to verify database, Redis and cache connectivity.
- Placeholder AutoWave welcome page.
- GitHub Actions CI (Pint, PHPUnit on PostgreSQL, frontend build).
- Project documentation structure, `AGENTS.md`, Cursor rules and initial ADRs.

### Fixed

- MUI component styles were overridden by Tailwind's reset on pages using text fields (cascade layer order).

### Security

- CRM records can only reference records of the same business (database-level composite keys); another
  business's lead or customer id returns 404.
- Disabled features (modules) return 404 for their pages and actions.
- Business creation is rate limited and capped per user (default 3).
- Suspended users are blocked at login and signed out mid-session.
- Database-level guarantee that roles cannot be assigned across businesses.
- Tests refuse to run against any database not named `*_testing`.

- Security headers middleware (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`, HSTS over HTTPS).
