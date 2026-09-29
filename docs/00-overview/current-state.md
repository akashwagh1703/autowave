# Current State

_Last updated: 2026-09-30 — Phase 10 complete; first production deployment._

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

### Phase 5 — Automation (ADR-015)

- **Automations** (`/automations`, needs the `automation` module):
  - a list with steps in plain language, run counts, an on/off switch and 7-day figures;
  - a builder with trigger, conditions, waits and actions;
  - an automation page with its recent runs; delete.
- **Triggers** (from the CRM and booking events):
  - leads: created, updated, stage changed, assigned, converted;
  - customers: created;
  - appointments: created, confirmed, rescheduled, completed, cancelled, no-show.

  Only triggers the tenant's modules and engines support are offered.
- **Steps:**
  - conditions: all or any rules on subject fields, with operators by field type;
  - waits: a fixed delay, or relative to the appointment start;
  - actions: WhatsApp, email, notify team, follow-up task, assign lead, move lead stage, tag customer.

  Messages support `{{variables}}`. All of it is driven by `config/automation.php`.
- **Engine:**
  - One queued job per step (`automation` queue). Waits are rows the every-minute
    `automation:dispatch-due` command dispatches; it also recovers stuck steps.
  - Runs keep a snapshot of their steps.
  - Duplicate protection: a unique dedupe key per run, a unique row per step and message idempotency keys.
  - Optional once per record; loop guard (depth 3).
  - Rescheduling an appointment moves its pending reminders.
  - A run stops if the automation, module, tenant or subject goes away.
- **Run history and run page:** step statuses, a readable log, messages; retry failed runs, cancel runs in
  progress, send failed messages again.
- **Messaging foundation:**
  - `MessagingService` with a provider per channel; WhatsApp is simulated (log provider) and email uses
    the Laravel mailer;
  - the `SendOutboundMessage` job (`messaging` queue) and `messaging:dispatch-pending` recovery;
  - messages to leads and customers appear on their timeline.
- **Default automations:** six templates, provisioned at onboarding and backfilled once. Business types can
  choose their own list; templates a tenant cannot use are skipped. Templates that message customers start
  paused.
- **Demo data:** the local-only `DemoAutomationSeeder` turns on the salon's welcome and confirmation
  automations, and adds "Tag big-ticket customers".
- **Local run:** `composer dev` now also runs the scheduler and listens on the `automation` and `messaging`
  queues.
- **Tests:** 283 feature tests. Phase 5 adds 32 in `tests/Feature/Automation`: engine
  (including the §114 milestone), HTTP and permissions, isolation, provisioning.

### Phase 6 — Website (ADR-016)

- **Website editor** (`/website`, needs the `website` module; `website.view` to see, `website.manage` to
  change):
  - the site's status and address, a setup checklist, publish and unpublish, and a signed preview link for
    unpublished sites;
  - sections: add, edit, reorder, show or hide, remove. Header and footer are pinned. Sections that need
    records (services, team, gallery, testimonials…) show why they are hidden;
  - a section edit page generated from the section's schema in `config/website.php`, including repeatable
    items (testimonials, offers, FAQ) and its images;
  - **Design and logo:** template, colours and logo;
  - **Business details:** tagline, description, phone, WhatsApp, email, address, city, opening hours,
    social links, and the search title and description. They are stored in the tenant's business profile;
    "Get directions" opens a map search for the address;
  - **Online booking settings:** on/off, auto-confirm, minimum notice, how far ahead, "Any available".
- **Images:** logo, hero image and gallery uploads (jpg, png, webp; 4 MB; dimension limits) stored under
  the tenant's folder on the `WEBSITE_MEDIA_DISK` disk, with alt text and ordering (`media` table).
- **Public site** (`{slug}.{root}`): header, hero, about, services with prices, team, gallery with a
  lightbox, testimonials, offers, FAQ, contact with directions, footer with social links, and a floating
  WhatsApp button. Titles, descriptions and Open Graph tags are rendered on the server (AW-010 resolved).
- **Enquiry form:** creates or updates a lead with the source "Website", logs a `website_enquiry` activity
  and starts automations with the new `website.enquiry` trigger. Honeypot and rate limits.
- **Online booking:** service, staff or resource ("Any available"), date and free time, then name and phone.
  Creates the customer and an appointment with `source = website`, pending unless auto-confirm is on. Turfs
  without the service engine book fixed slots. The `appointment.source` automation condition tells online
  bookings apart.
- **Products section:** built and reading from the product catalogue, hidden until Phase 7 created
  products (AW-034). Packages and reviews sections likewise.
- **Backfill:** `TenantBackfillSeeder` grants the `website` permission group to existing roles, once.
- **Demo data:** the local-only `DemoWebsiteSeeder` fills the profile and section content of ABC Salon and
  ABC Turf.
- **Tests:** 326 feature tests. Phase 6 adds 43 in `tests/Feature/Website`: public site, preview and
  SEO, enquiry, online booking, editor and permissions, media, isolation.

### Phase 7 — Commerce (ADR-017)

- **Products** (`/products`, commerce engine: salon, cafe, local store):
  - catalogue with categories, SKU, price and original price, description, one image, active flag;
  - list with category buttons, search, status and stock filters, sorting, a low-stock banner and bulk
    actions; categories are managed inline;
  - optional stock tracking per product with opening stock, a low-stock level, "Adjust stock" (add, remove,
    set, with a reason) and a movement history. Stock never goes below zero (row lock plus a check
    constraint).
- **Orders** (`/orders`):
  - a list with status tabs, search, payment, source and date filters;
  - a new-order form: customer search or a new customer, products from the catalogue, in store, pickup or
    delivery (address and fee), discount, "handed over now" and "payment received now";
  - an order page: confirm, ready, completed, cancel with a reason (stock goes back once), record and
    remove payments (cash, UPI, card, bank transfer, other), notes, customer call and WhatsApp, history.
  - Prices, totals and stock are always computed on the server. Order numbers run per business from #1001.
- **Order settings** (`/settings/commerce`): online ordering, auto-confirm, pickup, delivery fee, free
  delivery threshold, minimum order, delivery note.
- **Website shop:** the products section now shows the catalogue. When online ordering is open, visitors add
  products to a cart (kept in the browser), see a server-priced quote, and check out for pickup or delivery.
  Website orders start pending (or confirmed with auto-confirm) and the owners get an email. Honeypot and
  rate limits.
- **Automations:** triggers order placed, confirmed, ready, completed, cancelled and paid; order conditions
  and variables; default templates "Tell the team about website orders" (on) and "Tell customers their
  order is ready" (paused).
- **Dashboard:** orders today, low stock and repeat customers (local store); revenue today (orders added to
  appointments for a salon) and product sales (salon) need `reports.view`.
- **Customer page:** an orders card and a "New order" button; order entries on the timeline.
- **Backfill:** `TenantBackfillSeeder` adds the two order automation templates to existing tenants.
- **Demo data:** the local-only `DemoCommerceSeeder` adds ABC Salon's product categories, seven products
  (one out of stock, one low) and four orders in different states.
- **Tests:** 372 feature tests. Phase 7 adds 46 in `tests/Feature/Commerce` and `tests/Feature/Website/OnlineShopTest`:
  products and stock, orders and payments, settings, dashboard and automations, isolation, and website
  ordering.

### Phase 8 — Messaging (ADR-018)

- **Settings → Messaging** (`settings.view` to see, `settings.update` to change):
  - connect WhatsApp (phone number id, WhatsApp Business Account id, access token, app secret) and
    Instagram (token, app secret); checked against Meta before saving;
  - tokens and secrets stored encrypted and write-only (the page only says whether one is saved);
  - the webhook callback URL and verify token to paste into Meta; disconnect;
  - sync WhatsApp templates; quiet hours; email sender name and reply-to.
- **Webhooks** (`/webhooks/meta/{key}` on the app host): subscription check, `X-Hub-Signature-256`
  verification, size limit and rate limit; verified bodies are stored and processed on the `messaging`
  queue, recovered by `messaging:dispatch-pending` and pruned after 14 days.
- **Inbound:** text and button replies, media as placeholders; one conversation per contact and channel;
  linked to a customer or open lead with the same phone, or a new lead (source WhatsApp or Instagram);
  timeline entries; STOP/START opt-out; idempotent on Meta's message id.
- **Inbox** (`/inbox`, `conversations.*`): open, mine, unassigned, closed and all tabs, channel filter,
  search, unread badge in the nav, 10-second polling; thread with day separators and delivery ticks;
  replies, template dialog, assignment, close/reopen, opt-out; **Chat** buttons on customer and lead pages.
- **Outbound:** every WhatsApp and Instagram message (automation, reply, system) is threaded in its
  conversation; per-tenant provider resolution (Meta when connected, simulated otherwise); delivery and read
  receipts that only move forward; permanent Meta errors fail at once.
- **Compliance:** the 24-hour window (free text blocked outside it with a real provider), approved templates,
  opt-out and quiet hours, all in `MessagingCompliance`. The automation WhatsApp action has a template mode
  with one field per variable.
- **Timeline:** inbound messages show the contact as the author; outbound show the sender, automation or
  template.
- **Tools:** `messaging:simulate-inbound` (not in production), `messaging:prune-webhooks`.
- **Backfill:** `TenantBackfillSeeder` grants the new `conversations` permission group once.
- **Demo data:** the local-only `DemoMessagingSeeder` adds ABC Salon conversations (WhatsApp and Instagram,
  a reply, an opted-out contact, a closed conversation) and two sample templates.
- **Tests:** 423 tests (3,472 assertions). Phase 8 adds 51 in `tests/Feature/Messaging`: webhooks, delivery and
  compliance, settings, inbox and permissions, isolation.

### Phase 9 — AI (ADR-019)

- **Provider:** OpenRouter with one platform key (`OPENROUTER_API_KEY`, server only), called only through
  `AIService` → `AIGateway`. `AI_PROVIDER=fake` gives labelled sample answers without a network (tests,
  local development). Prompts in `resources/prompts/*.md`, grounded in business facts (profile, hours,
  services, products, the owner's notes).
- **Availability:** module `ai` (on for every business type, backfilled once), Settings → AI switch,
  provider configured, monthly allowance left. Otherwise AI buttons are disabled with the reason and AI
  automation steps are skipped. The platform works fully without AI.
- **Inbox:** "Suggest reply" (or improve the typed text) fills the composer — AI never sends; conversation
  summary in the header; drafts left by automations wait above the composer until used, dismissed or
  replied to.
- **Leads and customers:** summary cards (cached until the timeline changes); "Fill details from
  messages" fills empty name, email, interest and estimated value, and turns other differences into
  suggestions to apply or dismiss; automatic extraction after a lead writes (debounced, on the `ai` queue).
- **Assistant** (`/assistant`): owners and managers ask about appointments, leads, customers, orders,
  products and services through read-only tools that follow their permissions and never return phone
  numbers or e-mails; a Write tab for marketing text.
- **Writing help:** website section fields, automation messages (with the trigger's placeholders) and
  marketing kinds.
- **Automations:** trigger "Message received" (conversation subject; channel, assigned and message text
  conditions); actions "Fill lead details with AI", "Draft a reply with AI", "Add an AI summary".
- **Settings → AI:** on/off, automatic extraction, tone, notes for AI, this month's usage by feature.
- **Super Admin → AI usage:** per-business requests, tokens, cost (USD), failures and allowance by
  month; set or reset a business's allowance; AI totals on the admin dashboard.
- **Permissions:** `ai.use` (Manager, Receptionist, Sales Executive) and `ai.assistant` (Manager);
  owner has all. 20 AI requests per user per minute.
- **Backfill:** `TenantBackfillSeeder` grants the `ai` group and switches the `ai` module on once per
  tenant (`modules_backfilled`).
- **Tests:** Phase 9 adds 46 in `tests/Feature/AI`: OpenRouter (faked HTTP), availability, metering and
  cap, endpoints and permissions, extraction, assistant tools, automations, settings, Super Admin,
  backfill and isolation.

### Phase 10 — Additional verticals (ADR-020)

- **Coaching (education engine):** courses, batches (days, times, teacher, room, capacity, dates, fee),
  students as customers with enrolments; admission from a lead (converted to "Admitted"), a customer or
  a walk-in, under a batch lock with capacity checks; fee plans (equal monthly or typed instalments),
  payments allocated oldest first, plan changes; drop / complete / re-activate; attendance per class and
  date; demo classes for leads (lead moves to "Demo scheduled"); Fees page; Settings → Coaching;
  website section "Courses"; widgets students, admissions, fees due, demo classes.
- **Fee reminders:** `education:fee-reminders` hourly fires `fee.due_soon` / `fee.overdue` once per
  instalment. Templates: fee due reminder (paused), overdue follow-up task (active), admission welcome
  and demo confirmation (paused).
- **Cafe (food engine + commerce):** products carry food type and "available now"; dining tables;
  reservations (team or website; no double booking per table via an exclusion constraint) with confirm,
  seat, complete, no-show, cancel; dine-in orders by table, walk-ins without a customer, "Add items";
  kitchen screen (KOT) that marks tickets ready and moves orders to Ready; Settings → Reservation
  settings; website section "Reserve a table"; widgets reservations today, kitchen queue, top products.
- **Turf pricing:** base, peak and weekend hourly rates per resource; quotes per minute; the price on the
  team and website slot pickers and stored on the booking; advances recorded as appointment payments.
- **Local Commerce:** renamed in place (existing stores keep their business type); coupon codes on the
  Offers page, in the order form and the website cart, with locked usage counts; widgets repeat
  customers and top products.
- **Platform:** new permission groups `courses`, `students`, `fees`, `reservations` (backfilled once);
  `offers` module switched on once for Cafe and Local Commerce tenants; automation templates provisioned
  only when the business can use them; assistant tools `students` and `reservations`.
- **Demo data (local):** ABC Coaching (courses, batches, students with instalments, attendance, demos),
  ABC Cafe (menu, tables, reservations, dine-in and website orders, coupons), ABC Store (groceries,
  coupons, repeat customer), turf rates and an advance on ABC Turf.
- **Tests:** 525 tests (4,644 assertions). Phase 10 adds 56: `tests/Feature/Education` (18), `tests/Feature/Food` (13),
  `Commerce/CouponTest` (7), `Booking/TurfPricingTest` (9), `Tenancy/VerticalProvisioningTest` (5),
  `AI/VerticalAssistantToolsTest` (3) and a cafe automation provisioning test.

### Production (2026-09-29)

- **Live:** <https://autowave.co.in>, <https://app.autowave.co.in>, <https://admin.autowave.co.in> and
  `https://{slug}.autowave.co.in`, on a DigitalOcean droplet shared with other projects
  ([production.md](../09-devops/production.md)). Started clean: platform data and one Super Admin.
- **Deploys:** `scripts/deploy.sh` builds atomic releases from GitHub `master` (migrate, cache, health
  check, switch, worker restart, keep 5) and rolls back ([deployment.md](../09-devops/deployment.md)).
- **Runs with:** Nginx and a Let's Encrypt wildcard certificate (DNS-01 via DigitalOcean), PHP-FPM 8.4
  pool, PostgreSQL 16, shared Redis (DB 2/3), one systemd queue worker, cron scheduler.
- **Not yet on:** real email (SMTP), OpenRouter and Meta per business — see
  [enable-integrations.md](../11-runbooks/enable-integrations.md). Backups, firewall and server size:
  AW-065–067.

## In progress

- Nothing. Phase 10 is complete and awaiting approval before Phase 11.

## Not implemented

- **Platform:** invitations and member management, role editor, logo upload during onboarding, custom
  domain UI, feature flags, custom fields, analytics, billing.
- **AI gaps:** redaction of customer text (AW-055), plan-based allowances and INR cost (AW-056), streaming
  and saved assistant chats (AW-057).
- **Vertical gaps:** website sections not added to existing sites (AW-059), discounts not recomputed when
  items are added (AW-060), per-item kitchen notes (AW-061), kitchen access for Staff (AW-062), table
  availability for website reservations (AW-063), fee reminder sending hour (AW-064).
- **Commerce gaps:** online payments (AW-041), variants (AW-042), returns and refunds (AW-043), taxes
  (AW-044), stock reservation for carts (AW-045), receptionist order updates (AW-046), editing orders
  (AW-047), customer order messages and tracking (AW-048), delivery zones (AW-049).
- **CRM gaps:** kanban board, own-leads visibility, campaigns, import/export.
- **Booking gaps:** buffers, recurring or group bookings, week view, packages, scoped staff visibility,
  customer self-cancel and reschedule (AW-037).
- **Website gaps:** packages and reviews content (AW-034), image resizing (AW-035), server
  rendering of section content (AW-036), captcha (AW-038), custom domains (AW-039).
- **Automation gaps:**
  - branches;
  - webhook and payment actions and triggers (AW-026);
  - an Instagram action (AW-053);
  - retention (AW-030).
- **Messaging gaps:** Embedded Signup (AW-050), media download and sending (AW-051), own-conversations
  visibility (AW-052), inbound email (AW-054).

## Known technical debt

See [known-issues.md](known-issues.md) (AW-001 → AW-068).
