# AGENTS.md — AutoWave Project Constitution

This file is binding for every human developer and AI coding agent working on this repository.
The repository is the source of truth. If something important is only in a chat, it is undocumented.

The full product specification lives in [`docs/01-product/master-prompt.md`](docs/01-product/master-prompt.md).
Read it for anything not covered here.

---

## 1. Product overview

**AutoWave** is a multi-tenant local business operating and automation platform.

> Build, manage and automate a local business from one platform.

Every business (salon, turf, coaching centre, cafe, clinic, local store) runs on **one application**
as a **tenant**. Differences between business types are **configuration** (business types, engines,
modules, settings, custom fields), never separate code paths or separate apps.

AutoWave itself is a tenant ("AutoWave Internal") and uses the platform for its own marketing,
lead capture, CRM and demos (dogfooding).

First commercial vertical: **Beauty & Salon**.

Current state: see [`docs/00-overview/current-state.md`](docs/00-overview/current-state.md).

## 2. Technology stack

| Layer | Choice |
|---|---|
| Backend | PHP 8.3+ (8.4 used locally), Laravel 13, Eloquent, Policies, Jobs, Events, Notifications, Scheduler |
| Frontend | React 19 (JavaScript/JSX — **no TypeScript**), Inertia.js v3, Vite 7, Tailwind CSS v4, MUI v9 |
| Forms | React Hook Form + Zod — add when the first real forms are built |
| Database | PostgreSQL 17 |
| Queue / cache | Redis 7, Laravel Queue (Horizon planned for production) |
| Server | Nginx + PHP-FPM + Supervisor on a DigitalOcean VPS |
| CI | GitHub Actions |
| AI | Provider abstraction (Gemini, OpenRouter, ...) |

Not allowed for the core app: Cloudflare Workers/Pages, Supabase, serverless, microservices, TypeScript
(unless an ADR approves it).

## 3. Architecture rules

- **Modular monolith**: one Laravel app, one Inertia/React frontend, one PostgreSQL, one Redis.
- Business logic lives in `app/Domain/<Domain>/` (actions/services, models, events, policies).
  Controllers stay thin: validate (Form Request) → authorize (Policy) → call domain action → return response.
- **Configure, don't hard-code.** Never create `SalonX`, `TurfX`, `ClinicX` classes or pages.
  Build one reusable module/engine and configure it per business type/tenant.
- Customization hierarchy: Configuration → Custom Field → Workflow → Reusable Module → Engine Enhancement → Tenant Extension.
- Providers (AI, messaging, payments) are always behind an interface. Business code never calls a vendor SDK directly.
- External webhooks → signature verification → provider adapter → normalized event → core domain.
- AI is never the source of truth for price, inventory, availability, bookings, payments or order status.
- Any architectural change requires an ADR in `docs/12-decisions/` **before** implementation.
- Details: [`docs/02-architecture/overview.md`](docs/02-architecture/overview.md).

## 4. Tenant rules (release-blocking)

- Every tenant-owned table has a non-null `tenant_id` foreign key and an index starting with `tenant_id`.
- Tenant is resolved server-side (domain → tenant, or authenticated membership). **Never trust a client-supplied `tenant_id`.**
- Tenant-owned models must be scoped to the current tenant via the central `TenantContext` + global scope (Phase 1).
- A tenant must never read, update or delete another tenant's data, files, conversations, reports or website config.
- Every tenant-owned feature ships with **cross-tenant isolation tests** ("Tenant A cannot access Tenant B ...").
- Files live under `tenant/{tenant_id}/...`.
- Details: [`docs/02-architecture/multi-tenancy.md`](docs/02-architecture/multi-tenancy.md).

## 5. RBAC rules

- Dynamic RBAC: `roles`, `permissions`, `role_permissions`, `user_roles`, scoped by tenant membership (`tenant_users`).
- Permissions are granular dot-strings: `customers.view`, `leads.assign`, `appointments.cancel`, `settings.update`.
- Authorization is enforced **server-side** in Policies/Gates. Hiding a button in React is not authorization.
- Super Admin (platform) is separate from tenant administration (`admin.autowave.in` vs `app.autowave.in`).

## 6. Module rules

- Modules are reusable capabilities (CRM, Leads, Messaging, Website, ...). Engines are major capabilities (Service, Booking, Commerce, Education, Food).
- Module registry + `tenant_modules` + `business_type_modules` + `module_dependencies`. Incompatible activation must be rejected.
- Modules and business types are versioned; tenants store the version they run so upgrades never silently break them.
- Sidebar and dashboard are **resolved** from enabled engines/modules + user permissions — never hard-coded per business type.

## 7. Database rules

- Every schema change is a migration. Never edit production schema by hand.
- Use foreign keys, unique constraints and indexes (tenant_id, FKs, status, dates, domain lookups, availability).
- Wrap multi-step writes in transactions (create tenant, create booking, create order, convert lead, enable modules).
- Booking must prevent double-booking with DB constraints and/or row locking — not just application checks.
- Store timestamps in UTC (`APP_TIMEZONE` stays UTC); tenant timezone is a tenant setting.
- Update `docs/03-database/` whenever the schema changes.

## 8. Testing rules

- Tests run on **PostgreSQL** (`autowave_testing`), never SQLite. `php artisan test` must pass before commit.
- Required when applicable: unit, feature, authorization, tenant isolation, queue/job, automation, booking concurrency.
- New features are not done without tests. Never delete or weaken tests to make a build pass.
- Details: [`docs/10-testing/strategy.md`](docs/10-testing/strategy.md).

## 9. Documentation rules

Documentation drift is a defect. When code changes:

| Change | Update |
|---|---|
| Architecture | `docs/02-architecture/`, ADR in `docs/12-decisions/` |
| Database | `docs/03-database/` |
| API | `docs/07-api/` |
| Permissions | `docs/04-security/authorization.md` |
| Workflow / feature | `docs/05-features/<feature>.md` |
| Deployment | `docs/09-devops/`, `docs/11-runbooks/` |
| User-visible | `CHANGELOG.md` |
| Any significant feature | `current-state.md`, `implementation-status.md`, `known-issues.md` |

Never describe planned features as implemented.

## 10. Git rules

- Branches: `main` (production), `develop` (integration), `feature/*`, `fix/*`, `hotfix/*`.
- Conventional commits: `feat(scope):`, `fix(scope):`, `refactor:`, `docs:`, `test:`, `chore:`, `security:` — reference issue IDs (`[AW-012]`).
- Small, focused commits. Review `git diff` before committing. Never commit `.env` or secrets.

## 11. Security rules

- Validate all input (Form Requests server-side; Zod client-side is only UX).
- CSRF on web routes, rate limiting on auth and public forms, secure cookies, security headers (`SecurityHeaders` middleware).
- Verify webhook signatures. Validate uploads (size, MIME, extension, filename).
- Never expose secrets/tokens/passwords to the frontend or Inertia shared props.
- Audit-log sensitive platform and tenant actions.

## 12. AI development workflow (for coding agents)

For every significant task: **Read → Plan → Implement → Test → Review → Document → Commit**.

1. **Read** `AGENTS.md`, `.cursor/rules/*`, relevant `docs/`, and existing code.
2. **Plan**: affected files, database, backend, frontend, security, tests, documentation, risks.
3. **Implement** only the approved scope. No unrelated refactors, renames, or dependency upgrades.
4. **Test**: `php artisan test`, `vendor/bin/pint --test`, `npm run build`.
5. **Review** the git diff.
6. **Document** per section 9.
7. **Report**: what changed, files, DB changes, tests run, docs updated, risks, follow-ups.

Ask for clarification only when ambiguity materially affects architecture, security, data correctness or product behaviour.
Otherwise make a reasonable decision and document the assumption.

Do not overbuild. Do not implement roadmap items that were not requested. Work phase-by-phase
(see `docs/00-overview/roadmap.md`).

Before adding a dependency: check Laravel/existing deps first, maintenance, security, compatibility,
and record the reason (ADR or `docs/02-architecture/dependencies.md`).
