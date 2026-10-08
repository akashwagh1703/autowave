# Plan: Photo Studio + complete unfinished modules

- **Status:** Phase 1 implemented locally (awaiting PHP 8.4 test run + commit)
- **Created:** 2026-10-08
- **Goal:** Add a Photo Studio business type, and make every catalogue module either truly working or not offered as “active”.

## Why phased

Finishing every unfinished module (reviews, loyalty, membership, marketing campaigns, forms, QR, analytics, packages, module toggle UI) is several product phases. Doing them all in one change risks breaking salons, turfs, cafes and stores that already work.

**Rule:** each phase ships with tests; existing business types must keep passing; unfinished modules are marked `draft` until they have real screens and routes.

## Current module truth (baseline)

| Module | Today | Action |
|---|---|---|
| customers, leads, messaging, automation, website, ai, offers | Working | Keep |
| crm | Partial (lives under leads/customers) | Keep; no separate app |
| payments | Partial (manual payment records) | Keep as dependency; no fake “Payments app” |
| inventory | Partial (stock on products) | Keep as flag with commerce |
| marketing, forms, qr, reviews, loyalty, membership, analytics | Declared only | Mark `draft` until built |
| packages (website section) | Placeholder | Build in Phase 2 |

Also: owners **cannot** turn modules on/off after signup (wizard text is wrong). That is Phase 6.

---

## Phase 1 — Photo Studio + hygiene (this phase)

### 1A. Photo Studio business type (`photo_studio`)

Reuse existing engines (no new engine):

| Piece | Choice |
|---|---|
| Engines | `service`, `booking`, `commerce` |
| Modules (working only) | `customers`, `crm`, `leads`, `messaging`, `automation`, `website`, `offers`, `ai` (+ `payments` auto via commerce) |
| Resource label | Photographer |
| Service categories | Wedding, Maternity, Portrait, Product, Events, Passport & ID |
| Website sections | header, hero, about, services, products, gallery, team, testimonials, offers, faq, contact, booking, footer |
| Dashboard | revenue_today, appointments_today, new_leads, pending_followups, service_sales, product_sales, potential_revenue |
| Templates | premium, elegant, modern |
| Marketing | `/for/studios` industry page |
| Demo tenant | `abc-studio` / `owner@abc-studio.test` (local only) |

WhatsApp assistant: book sessions, services & prices, products/albums, offers, FAQ, talk to a person (same as salon).

### 1B. Module hygiene (do not break sellers)

1. Set unfinished modules to `status: draft` in `config/catalog.php` (CatalogSeeder already respects this; onboarding only lists `active`).
2. Remove draft modules from all business-type presets so new businesses are not told they have Reviews/Loyalty/Marketing when nothing exists.
3. Fix Offers nav: require `engine: commerce` as well as `module: offers`.
4. Soften wizard copy: do not promise “switch on later” until Phase 6 exists.
5. Settings → Modules: show draft/disabled clearly; only claim Active when the module is active in the catalogue **and** enabled for the tenant.

### Tests (Phase 1)

- Onboarding offers 7 public types including `photo_studio`.
- Vertical provisioning: photo studio engines/modules/sections/widgets.
- Marketing `/for/studios` renders.
- Regression: existing Onboarding, Catalog, Booking, Commerce, Website, MarketingSite tests.

### Deploy notes

- No schema migration for Photo Studio (catalogue seed only).
- Production: `php artisan db:seed --class=CatalogSeeder --force` after deploy (or whatever deploy already runs).
- Existing tenants keep any already-enabled draft modules in `tenant_modules`; they just stop being offered to new signups.

---

## Phase 2 — Packages (needed for studios and salons) — done

- Service/product packages (bundles with price and duration) as `services.is_package` + `package_items`.
- Admin CRUD (Services → Add package) + website Packages section; WhatsApp services list excludes packages.
- Booking uses the package’s own duration/price via existing `service_id`.

### Deploy notes (Phase 2)

1. Deploy code (migration runs in the deploy script).
2. `php8.4 artisan db:seed --class=CatalogSeeder --force`
3. `php8.4 artisan db:seed --class=TenantBackfillSeeder --force`
4. `php8.4 artisan optimize && php8.4 artisan queue:restart`

## Phase 3 — Reviews

- Collect rating + text (after appointment/order, or public link).
- Moderate / publish; website Reviews section uses real data.
- Flip module `reviews` to `active`; add back to salon, clinic, photo_studio presets; backfill.

## Phase 4 — Loyalty

- Points per visit/order; simple rewards; customer balance on timeline.
- Flip `loyalty` to active; salon + photo_studio presets.

## Phase 5 — Membership

- Membership plans (monthly/yearly); link to customer; optional booking discount.
- Flip `membership` to active.

## Phase 6 — Module toggle UI

- Owner (or Super Admin) enable/disable modules after signup with dependency checks (`ModuleManager::disable` already enforces).
- Fix wizard copy permanently.

## Phase 7 — Marketing campaigns

- Simple outbound campaigns (audience = tags/stages; send via WhatsApp templates / email).
- `campaign_id` on leads (AW-014). Flip `marketing` to active.

## Phase 8 — Forms + QR

- Custom enquiry forms (beyond the fixed website contact form).
- QR codes that open a form or offer. Flip `forms` and `qr`.

## Phase 9 — Analytics

- Gate richer reports behind `analytics` (or keep free dashboard widgets and make Analytics the export/date-range reports). Flip module when real.

---

## Safety checklist (every phase)

1. Do not change behaviour of other business types except intentional hygiene.
2. Run targeted tests for the phase + smoke: Onboarding, Catalog, Tenancy, Booking, Commerce, Website, Chatbot, MarketingSite.
3. Pint + Vite build.
4. Secret scan before commit; push only when asked.
5. Update CHANGELOG, current-state, known-issues (close/update AW items when done).

## Out of scope for Phase 1

- Building reviews/loyalty/membership/marketing/forms/QR/analytics features.
- Online customer payment gateway for bookings (separate billing/commerce work).
- Custom domains (AW-039).
