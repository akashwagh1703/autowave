# ADR-012: Section-based website configuration and templates

- **Status:** Accepted
- **Date:** 2026-09-27

## Context

Every tenant gets a public website at `{slug}.{root_domain}` as soon as onboarding finishes (master prompt
§20, §22, §23). The product explicitly rejects a Wix-style page builder: owners configure sections, not
pixels. Templates (Modern, Premium, Minimal, Elegant, Corporate) must be switchable without losing data, and
each business type recommends templates and a default section list.

## Decision

1. **Three tables.**
   - `website_templates` (platform catalogue, synced from `config/catalog.php` by `CatalogSeeder`):
     `code`, `name`, `description`, `status`, `configuration.theme` (`hero`, `font`, `radius`).
   - `website_configs` (one per tenant, `tenant_id` unique): chosen template, `theme` overrides (brand
     colour), `seo` (title, description), `status` (`published`/`draft`), `published_at`.
   - `website_sections` (tenant-owned): `type`, `sort_order`, `enabled`, `configuration` jsonb — exactly the
     shape in master prompt §22.
2. **Templates control look only.** A template is a small theme token set interpreted by the React renderer
   (`resources/js/utils/websiteTheme.js`). Sections and their content never reference a template, so
   switching template is a single `website_template_id` update and cannot lose content.
3. **Content lives with its owner.** Section `configuration` only stores copy that belongs to the section
   (hero headline, CTA kind, about text). Contact details come from the `business_profile` setting; services,
   products, gallery etc. will come from their modules. The renderer skips section types that have no
   renderer or no data yet.
4. **Business type drives defaults.** `business_types.*.website_templates` (first = default) and
   `configuration.website_sections` define what `ProvisionWebsite` creates. Both keys stay on the business
   type row and are *not* copied into tenant settings.
5. **Provisioning is part of tenant creation.** `CreateTenant` calls `ProvisionWebsite` inside its
   transaction and tenant context. `ProvisionWebsite` is idempotent; `ensureFor()` backfills tenants created
   before websites existed (called by the internal/demo seeders).
6. **Visibility.** The site is served only when the tenant has the `website` module enabled *and* the config
   is `published`. The configuration is created even when the module is off, so enabling it later needs no
   setup.

## Alternatives

- **Page builder / free-form JSON page tree** — explicitly out of scope; hard to keep consistent and secure.
- **Template-specific section schemas** — would make template switching lossy.
- **Store the section list in `tenant_settings`** (Phase 1 placeholder) — no ordering/enable flags per
  section, no room for per-section configuration; replaced.

## Consequences

- New section types need a renderer in `website/Home.jsx` and (optionally) default configuration in
  `ProvisionWebsite::defaults()`; no migration.
- New templates are a catalogue entry plus, if a new theme token is introduced, a branch in `websiteTheme.js`.
- Section editing, reordering, SEO editing and logo upload arrive with the website feature phase; the data
  model already supports them.
