# Onboarding

- **Status:** ✅ Phase 2
- **Last updated:** 2026-09-27

## Purpose

Let a business owner go from sign-up to a working workspace, website and web address in a few minutes,
with no Super Admin involvement (master prompt §20).

## Problem

Phase 1 could only create tenants from seeders. New users landed on an empty "Your businesses" page.

## Actors

A signed-in user with a verified email (new or already a member of other businesses).

## User flow

1. Sign up → verify email → log in. A user with **no memberships at all** is redirected from `/dashboard`
   to `/onboarding`. Users whose memberships were suspended still go to `/workspaces` (so they see why).
2. The wizard (`/onboarding`, `onboarding/Create`) has six steps:
   1. **Business type** — public, active business types (newest version of each code).
   2. **Details** — name, web address (slug, live availability check with suggestion), phone, email, city,
      address, short description.
   3. **Capabilities** — every active module; the type's preset modules are pre-ticked; modules required by
      the type's engines are locked; ticking a module auto-ticks and locks its dependencies.
   4. **Branding** — preset colours or any hex, tagline, live preview.
   5. **Website** — template cards with a live preview; the type's recommended templates are listed first.
   6. **Review** — summary with per-section Edit, then **Create my business**.
3. On submit the server creates everything in one transaction, switches the session to the new tenant and
   redirects to the dashboard with "*Name* is ready".
4. Existing members reach the wizard from **Your businesses → Create a business**.

## What gets created (single transaction)

| Item | Source |
|---|---|
| Tenant (`created_by_user_id`, pinned business type version) | wizard |
| Owner membership + `owner` role; tenant roles from templates | RBAC templates |
| Modules: selection ∪ engine-required, with dependencies | wizard + catalogue |
| Engines of the business type | catalogue |
| Settings `branding` (name, colour, tagline), `business_profile` (contact, description) | wizard |
| Settings from business type configuration (e.g. `dashboard_widgets`, `booking_resource_label`) | catalogue |
| Website config (template, colour, SEO) and default sections | ADR-012 |
| Default domain `{slug}.{root_domain}` | `AssignDefaultDomain` |
| Audit log `tenant.created` (`source: onboarding`) | `OnboardBusiness` |

`TenantCreated` fires after commit (for future welcome automations).

## Rules

- Slug: 3–63 chars, DNS label, not reserved, unique across tenants and domains. A race between validation
  and insert is reported as a slug validation error.
- Business type must be public and active; `autowave_internal` is never offered or accepted.
- Modules must be known active codes; engine-required modules are always added server-side even if the
  client omits them.
- Template must be an active template code (any template may be chosen; recommended ones are hints).
- Phone `^\+?[0-9][0-9\-\s]{6,19}$`; colour `#rrggbb`; tagline ≤ 120; description ≤ 500.
- A user may create at most `AUTOWAVE_MAX_BUSINESSES_PER_USER` businesses (default 3), counted by
  `tenants.created_by_user_id`.

## Database

`tenants.created_by_user_id` (new), `website_templates`, `website_configs`, `website_sections` (new) —
see `docs/03-database/schema.md`.

## API (app host, `auth` + `active` + `verified`)

| Method | Path | Name | Notes |
|---|---|---|---|
| GET | `/onboarding` | `onboarding.create` | Wizard with catalogue payload |
| GET | `/onboarding/slug?slug=&name=` | `onboarding.slug` | JSON `{slug, valid, available, suggestion, domain}`; `throttle:60,1` |
| POST | `/onboarding` | `onboarding.store` | `throttle:onboarding` (20/hour per user) |

## Permissions

Any verified, active user. Ownership of the new tenant is granted through the `owner` role.

## UI

`resources/js/pages/onboarding/Create.jsx` and `resources/js/components/onboarding/*` (step components,
icon registry keyed by catalogue `icon`, module dependency helpers, slugify). Errors returned by the server
jump the wizard to the first step that has one.

## Security

- All choices are re-validated server-side against the catalogue; the client's module list is advisory.
- Rate limits on the slug check and on creation; per-user business cap.
- No secrets in the catalogue payload (only public catalogue data and the user's own email).

## Testing

`tests/Feature/Onboarding/OnboardingTest.php`, `tests/Feature/Website/WebsiteProvisioningTest.php`,
`tests/Feature/Auth/RegistrationTest.php`.

## Known limitations

- Default automations and a default dashboard layout are not created yet: automations arrive with the
  automation engine (Phase 5); the dashboard uses the business type's `dashboard_widgets` setting.
- No logo upload yet (needs the file-security pipeline).
- Email verification is required before onboarding, unless a platform admin has made it optional in
  Super Admin → Settings (the confirmation email is still sent and a reminder banner is shown).
- The platform-admin UI cannot raise a user's business limit yet (change the env value).

## Future extensions

Invite team members as a final optional step; choose a plan once billing exists; import contacts.
