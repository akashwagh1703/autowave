# Tenant Management

- **Status:** ✅ Phase 1 foundation; self-service creation via [onboarding](onboarding.md) (Phase 2)
- **Last updated:** 2026-09-27

## Purpose

Every business is a tenant with its own members, configuration, modules and website domain.

## Actors

Business owner, team members, platform admin.

## User flow

1. A user registers and verifies email. Without any membership they are sent to onboarding; users whose
   memberships were all suspended see **Your businesses** (empty state, with **Create a business**).
2. Tenants are created by `CreateTenant` — from onboarding (`OnboardBusiness`) or seeders.
3. Members land on the dashboard of their current business; `Switch business` lists all active memberships.
4. The public site is served at `{slug}.{root_domain}` when the `website` module is enabled.
5. Platform admins list/search tenants and suspend/activate them (`admin.autowave.in/tenants`).

## Rules

- Slug = DNS label, 3–63 chars, not reserved, unique across tenants and domains; generated from the name
  with `-2`, `-3`… suffixes.
- The business type version is pinned on the tenant (`business_type_version`); catalogue upgrades don't
  change existing tenants.
- Suspending a tenant immediately removes app access for all members and 404s its website (domain cache
  flushed). The internal AutoWave tenant cannot be suspended.
- Module enablement respects dependencies (`ModuleManager`); engines require their modules (`EngineManager`).

## Database

`tenants`, `tenant_users`, `tenant_settings`, `tenant_modules`, `tenant_engines`, `domains`, `audit_logs`,
`website_configs`, `website_sections` — see `docs/03-database/schema.md`.

`CreateTenant` options: `slug`, `is_internal`, `timezone`, `locale`, `currency`, `created_by`, `modules`
(replaces the preset list; engine-required modules and dependencies are always added), `website_template`,
`branding` (`primary_color`, `tagline`), `profile` (`phone`, `email`, `city`, `address`, `description`).
Business type configuration keys `icon`, `website_templates` and `website_sections` are catalogue-only and
are not copied into tenant settings.

## Permissions

App: membership required (`tenant.member`); settings page `settings.view`. Admin: `platform.admin`.

## Events

`TenantCreated` (after commit) — onboarding/welcome automation will listen in later phases.

## UI

`business/Dashboard`, `business/Workspaces`, `business/Settings` (read-only), `admin/Dashboard`,
`admin/tenants/Index`, `website/Home` (see [website.md](website.md)), `onboarding/Create`.

## Security

See `docs/02-architecture/multi-tenancy.md` and ADR-011. Suspend/activate is audit-logged.

## Testing

`tests/Feature/Tenancy/*`, `tests/Feature/Catalog/ModuleManagerTest.php`, `tests/Feature/Admin/AdminAccessTest.php`.

## Known limitations

- No invitation flow or member management UI yet (`users.manage` permission exists).
- Settings are read-only; no custom domain UI (custom domains work at the data level).
