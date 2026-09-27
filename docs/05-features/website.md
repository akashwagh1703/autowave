# Website

- **Status:** 🚧 Phase 2 foundation (provisioning + rendering; editing arrives later)
- **Last updated:** 2026-09-27

## Purpose

Every business gets a fast, mobile-friendly public website at `{slug}.{root_domain}` built from
configurable sections — no page builder (master prompt §22, §23; ADR-012).

## User flow

1. Onboarding picks a template (default = first recommended template of the business type).
2. `ProvisionWebsite` creates the website config and the business type's sections.
3. Visitors open `{slug}.{root_domain}`; the tenant is resolved from the host (ADR-007).

## Rules

- Served only when the `website` module is enabled and the config is `published`; otherwise 404.
- Disabled sections are not rendered; sections render in `sort_order`.
- Templates only change look (hero style, font, corner radius); switching never touches sections.
- Rendered today: `header`, `hero`, `about` (when there is a description), `contact` (when there are contact
  details), `footer`. Other section types (`services`, `products`, `gallery`, `booking`, …) are provisioned
  but skipped until their modules supply data.
- Hero CTA is **Book now** when the tenant has the booking engine, otherwise **Contact us** (scrolls to
  contact).

## Database

`website_templates`, `website_configs`, `website_sections` — see `docs/03-database/schema.md`.

## UI

`resources/js/pages/website/Home.jsx`, theme helpers in `resources/js/utils/websiteTheme.js` (shared with
the onboarding template preview). Business settings (`/settings`) show template, status and sections.

## Security

`WebsiteConfig` and `WebsiteSection` use `BelongsToTenant` (fail-closed). Only public data is sent to the
page: branding, contact details, enabled sections.

## Testing

`tests/Feature/Website/WebsiteProvisioningTest.php`, plus site checks in `OnboardingTest`.

## Known limitations

No section editor, reordering, SEO editor, logo/gallery upload or template switcher UI yet.
