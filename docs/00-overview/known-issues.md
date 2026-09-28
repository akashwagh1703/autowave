# Known Issues

Every significant unresolved issue is listed here. Close an issue by changing its status to `Resolved`
with the date and commit/PR reference; do not delete it.

---

### AW-001 — Laravel Horizon not installed

- **Category:** DevOps / Technical Debt
- **Description:** Horizon requires the `pcntl`/`posix` PHP extensions, which do not exist on Windows. The
  primary development machine is Windows, so Horizon was not added in Phase 0.
- **Impact:** No queue dashboard or per-queue supervisor balancing yet. Queues work via `queue:work`.
- **Status:** Open
- **Workaround:** Run `php artisan queue:work redis --queue=default,automation,messaging,ai,notifications,reports,media`.
  In production use Supervisor with `queue:work` until Horizon is added.
- **Affected:** `composer.json`, `docs/09-devops/supervisor.md`
- **Created:** 2026-09-27

### AW-002 — Default Git branch is `master`; convention requires `main` + `develop`

- **Category:** DevOps
- **Description:** The GitHub repository was created with `master`. `AGENTS.md` specifies `main` and `develop`.
- **Impact:** CI triggers include `master` as a stopgap. Branch naming is inconsistent with docs.
- **Status:** Open — needs the repository owner to rename the default branch on GitHub.
- **Workaround:** Rename on GitHub (Settings → Branches), then `git branch -m master main && git fetch origin && git branch -u origin/main main`; create `develop` from `main`.
- **Affected:** Repository settings, `.github/workflows/ci.yml`
- **Created:** 2026-09-27

### AW-003 — No frontend linter/formatter

- **Category:** Technical Debt
- **Description:** ESLint and Prettier are not configured. Only Pint (PHP) runs in CI.
- **Impact:** JS/JSX style may drift; some bugs (unused vars, hook rules) are not caught automatically.
- **Status:** Open — add when the first real frontend module is built (Phase 1/2).
- **Workaround:** Follow `.cursor/rules/react.mdc`.
- **Affected:** `resources/js/`, `package.json`
- **Created:** 2026-09-27

### AW-004 — Production/staging/runbook docs are unvalidated drafts

- **Category:** Documentation / DevOps
- **Description:** DevOps and runbook documents were written from the target architecture before any
  server exists. Commands have not been executed against a real DigitalOcean VPS.
- **Impact:** Procedures may need corrections on first real deployment.
- **Status:** Open — validate and update during first staging deployment.
- **Workaround:** Treat as a checklist; verify each step.
- **Affected:** `docs/09-devops/*`, `docs/11-runbooks/*`
- **Created:** 2026-09-27

### AW-005 — Local PHP 8.4 is a portable install outside PATH on the primary dev machine

- **Category:** DevOps
- **Description:** The machine's default `php` is XAMPP PHP 8.2, which cannot run Laravel 13. A portable
  PHP 8.4 was installed at `C:\Users\Akash.Wagh\tools\php84` and must be put first on `PATH` per terminal.
- **Impact:** Running `php artisan` in a fresh terminal uses PHP 8.2 and fails.
- **Status:** Open — machine-specific.
- **Workaround:** `$env:Path = "C:\Users\Akash.Wagh\tools\php84;" + $env:Path` (PowerShell), or add it to the
  user PATH ahead of XAMPP. See `docs/09-devops/local-development.md`.
- **Affected:** Local development only
- **Created:** 2026-09-27

### AW-006 — Shared dev database runs PostgreSQL 11 (end-of-life)

- **Category:** DevOps / Security
- **Description:** The shared dev database server runs PostgreSQL 11.20 (EOL since Nov 2023). The target is 17.
- **Impact:** Migrations must avoid PG12+ features (`NULLS NOT DISTINCT`, generated columns, etc.). No security patches.
- **Status:** Open
- **Workaround:** Partial unique indexes are used; tests run on local PostgreSQL 17. Plan an upgrade before staging.
- **Affected:** `database/migrations/*`
- **Created:** 2026-09-27

### AW-007 — App connects to the shared dev server as the `postgres` superuser; credentials shared in chat

- **Category:** Security
- **Description:** The dev server hosts ~75 databases; AutoWave uses the superuser account whose password was
  shared in plain text during setup.
- **Impact:** A leak of the AutoWave `.env` exposes every database on that server.
- **Status:** Open — needs the server owner.
- **Workaround:** Create a dedicated role owning only `autowave` (`CREATE ROLE autowave_app LOGIN PASSWORD ...;
  ALTER DATABASE autowave OWNER TO autowave_app;`), switch `.env`, and rotate the `postgres` password.
- **Affected:** `.env` (not committed)
- **Created:** 2026-09-27

### AW-008 — Remote dev DB latency (~1.3 s per page)

- **Category:** Performance (dev only)
- **Description:** Each query round-trips to the remote server; sessions are also stored there.
- **Impact:** Slow local page loads and seeding (~1–2 minutes). Not representative of production.
- **Status:** Open
- **Workaround:** Use the local Docker database for day-to-day work when latency matters.
- **Affected:** Local development
- **Created:** 2026-09-27

### AW-009 — Feature flags and custom fields tables deferred

- **Category:** Technical Debt
- **Description:** The master prompt lists `feature_flags` and `custom_fields` as platform tables. No Phase 1
  feature needs them, so they were not created.
- **Impact:** None yet.
- **Status:** Open — add with the first feature that uses them.
- **Affected:** Database
- **Created:** 2026-09-27

### AW-010 — Tenant website page title includes the platform name

- **Category:** UI
- **Description:** The Inertia title template appends "· AutoWave" on every page, including public tenant sites.
- **Impact:** Cosmetic; tenant sites show e.g. "ABC Salon · AutoWave".
- **Status:** Resolved 2026-09-30 (Phase 6): `app.blade.php` marks public site pages with
  `data-site="tenant"`, and the title callback in `app.jsx` leaves their titles unchanged.
- **Affected:** `resources/js/app.jsx`
- **Created:** 2026-09-27

### AW-011 — Onboarding does not create default automations or a dashboard layout

- **Category:** Product / Technical Debt
- **Description:** Master prompt §20 lists default automations and a default dashboard among onboarding
  outputs. The automation engine does not exist yet, and the dashboard is driven by the business type's
  `dashboard_widgets` setting rather than a stored layout.
- **Impact:** New tenants have no automations until Phase 5.
- **Status:** Default automations resolved 2026-09-29 (Phase 5): `CreateTenant` calls `ProvisionAutomations`,
  and `TenantBackfillSeeder` backfills existing tenants. A stored dashboard layout is still open.
- **Affected:** `app/Domain/Tenant/Actions/CreateTenant.php`
- **Created:** 2026-09-27

### AW-012 — No logo upload during onboarding

- **Category:** Product
- **Description:** Branding captures colour and tagline only; `branding.logo_path` stays null.
- **Impact:** Sites show the business name as text.
- **Status:** Partly resolved 2026-09-30 (Phase 6): the logo can be uploaded under Website → Design (media
  collection `logo`) and the site header shows it. The onboarding wizard still has no upload step (see also
  AW-040).
- **Affected:** Onboarding wizard, website header
- **Created:** 2026-09-27

### AW-013 — Legacy `website_sections` tenant setting left on pre-Phase-2 tenants

- **Category:** Technical Debt (dev data only)
- **Description:** Tenants created in Phase 1 (internal + demo tenants on the dev DB) still have a
  `website_sections` row in `tenant_settings`. Nothing reads it any more; sections now live in
  `website_sections`.
- **Impact:** None functionally.
- **Status:** Open — harmless; delete the rows or `migrate:fresh --seed` the dev DB when convenient.
- **Affected:** Shared dev DB data
- **Created:** 2026-09-27

### AW-014 — Leads have no `campaign_id` yet

- **Category:** Product
- **Description:** Master prompt §25 lists a campaign on leads. Campaigns do not exist yet, so the column was
  not added; the "Campaign" lead source covers the need for now.
- **Impact:** Leads cannot be attributed to a specific campaign.
- **Status:** Open — add the column (composite FK) with the campaigns feature.
- **Affected:** `leads` table
- **Created:** 2026-09-28

### AW-015 — No kanban pipeline board

- **Category:** Product / UI
- **Description:** The pipeline is shown as stage chips with counts plus a filterable table. There is no
  drag-and-drop board.
- **Impact:** Moving stages takes a click on the lead page or a bulk action.
- **Status:** Open
- **Affected:** `resources/js/pages/business/leads/Index.jsx`
- **Created:** 2026-09-28

### AW-016 — No "own leads only" visibility

- **Category:** Product / Security
- **Description:** Anyone with `leads.view` sees every lead in the business. There is no permission such as
  `leads.view_own` to restrict sales staff to their assigned leads.
- **Impact:** Larger teams cannot hide leads between sales executives.
- **Status:** Open — add a scoped permission and a query constraint when requested.
- **Affected:** `LeadController`, `config/rbac.php`
- **Created:** 2026-09-28

### AW-017 — CRM events have no listeners yet

- **Category:** Technical Debt
- **Description:** `LeadCreated`, `LeadUpdated`, `LeadStatusChanged`, `LeadAssigned`, `LeadConverted` and
  `CustomerCreated` are dispatched (after commit) but nothing consumes them until the automation engine.
- **Impact:** No automatic follow-up messages or notifications yet.
- **Status:** Resolved 2026-09-29 (Phase 5). `StartAutomations` listens to all of them (ADR-015).
- **Affected:** `app/Domain/Lead/Events`, `app/Domain/Customer/Events`
- **Created:** 2026-09-28

### AW-018 — Removing a membership with assigned leads fails

- **Category:** Technical Debt
- **Description:** `leads.assigned_tenant_user_id` is a composite FK without `ON DELETE SET NULL`
  (PostgreSQL 11 cannot null only one column of a composite FK). Deleting a `tenant_users` row that still has
  leads raises an FK error.
- **Impact:** None today (no member removal UI). Member management must unassign or reassign leads first.
  Phase 8 adds the same constraint on `conversations.assigned_tenant_user_id`.
- **Status:** Open — handle in the member-management feature.
- **Affected:** `leads` and `conversations` tables, future member removal action
- **Created:** 2026-09-28

### AW-019 — Timelines show the latest 100 entries

- **Category:** Product
- **Description:** Lead and customer pages load the 100 most recent activities without pagination.
- **Impact:** Very active records do not show older history in the UI (data is kept).
- **Status:** Open — add "load more" when needed.
- **Affected:** `LeadController::show`, `CustomerController::show`
- **Created:** 2026-09-28

### AW-020 — Concurrent conversion of two leads with the same phone

- **Category:** Technical Debt
- **Description:** Two different leads with the same phone (one open, one reactivated or linked by email)
  converted at the same instant could both try to create a customer. The partial unique index rejects the
  second one with a database error instead of reusing the first customer.
- **Impact:** Rare; the second user sees an error and can retry, which then reuses the customer. No
  duplicate data is created.
- **Status:** Open — catch the unique violation in `ResolveLeadCustomer` and re-query if it happens in
  practice.
- **Affected:** `app/Domain/Lead/Actions/ResolveLeadCustomer.php`
- **Created:** 2026-09-28

### AW-021 — Concurrency test simulates, rather than runs, parallel bookings

- **Category:** Testing
- **Description:** Feature tests run inside one database transaction (`RefreshDatabase`), so two real
  parallel requests cannot be run. The concurrency test inserts a conflicting row between
  `BookAppointment`'s availability check and its insert. It proves the exclusion constraint and the error
  handling; the row lock (`SELECT … FOR UPDATE`) is not exercised under real parallelism.
- **Impact:** Low. The constraint is the guarantee (ADR-014); the lock only makes the friendly check race-free.
- **Status:** Open. Add a non-transactional test with two database connections (or a load test on staging)
  if booking volume grows.
- **Affected:** `tests/Feature/Booking/AppointmentBookingTest.php`
- **Created:** 2026-09-28

### AW-022 — Staff see every resource's appointments

- **Category:** Product / Security
- **Description:** `appointments.view` shows the whole calendar. "My schedule" (the `resource=mine` filter)
  only narrows the view; there is no permission such as `appointments.view_own`.
- **Impact:** Staff can see colleagues' appointments and customer names. This is normal for small salons but
  not suitable for every business.
- **Status:** Open. Add a scoped permission and query constraint when requested (same approach as AW-016).
- **Affected:** `AppointmentController`, `config/rbac.php`
- **Created:** 2026-09-28

### AW-023 — Default service categories return if a tenant deletes them all

- **Category:** Technical Debt
- **Description:** `ProvisionServiceCatalog::ensureFor()` (run by `TenantBackfillSeeder` on each deploy)
  creates the default categories whenever a service-engine tenant has none. A tenant that deliberately
  deletes every category gets the defaults back.
- **Impact:** Cosmetic; the tenant can delete them again, and services are unaffected.
- **Status:** Open. Record "catalogue provisioned" in a tenant setting (as `rbac_backfilled_groups` does)
  if it bothers users.
- **Affected:** `app/Domain/Service/Actions/ProvisionServiceCatalog.php`
- **Created:** 2026-09-28

### AW-024 — Booking events have no listeners yet; no reminders

- **Category:** Technical Debt / Product
- **Description:** `AppointmentCreated`, `AppointmentConfirmed`, `AppointmentCompleted`,
  `AppointmentCancelled`, `AppointmentNoShow` and `AppointmentRescheduled` are dispatched after commit, but
  nothing consumes them. Customers receive no confirmations or reminders.
- **Impact:** Staff must contact customers themselves (the appointment page has call and WhatsApp buttons).
- **Status:** Resolved 2026-09-29 (Phase 5). Confirmation, reminder, no-show and thank-you templates exist.
  WhatsApp delivery is still simulated (AW-025).
- **Affected:** `app/Domain/Booking/Events`
- **Created:** 2026-09-28

### AW-025 — WhatsApp messages are simulated; no consent, quiet hours or approved templates

- **Category:** Product / Compliance
- **Description:** The WhatsApp channel uses the `log` provider. Messages are stored, shown on the timeline
  and run log as "simulated", and written to the log, but not delivered. There is no opt-in/opt-out record,
  no quiet hours, and no WhatsApp Business template approval flow.
- **Impact:** Customers receive nothing on WhatsApp yet. Email (`mail` provider) is delivered through the
  app mailer (`log` in local development).
- **Status:** Resolved 2026-10-02 (Phase 8, ADR-018). A business that connects its WhatsApp number in
  Settings → Messaging sends through the Meta Cloud API. STOP/START opt-out, quiet hours and synced,
  approved templates (for automations and outside the 24-hour window) are enforced by
  `MessagingCompliance`. Without a connected number WhatsApp is still simulated, by design.
- **Affected:** `config/messaging.php`, `app/Domain/Messaging`
- **Created:** 2026-09-29

### AW-026 — Some automation actions and triggers from the master prompt are not built

- **Category:** Product
- **Description:** §29 also lists these actions: create booking, send offer, call webhook, AI actions. It
  also lists triggers for forms, payments, orders and reviews. Those features do not exist yet, so neither
  do their triggers or actions.
- **Impact:** Automations cover leads, customers and appointments only.
- **Status:** Open. Each feature adds its trigger or action to `config/automation.php` (and a `StepAction`
  class) when it is built. Order triggers were added in Phase 7; AI actions (fill lead details, draft a
  reply, add a summary) and the message received trigger in Phase 9. Still missing: create booking, send
  offer, call webhook, and form, payment-gateway and review triggers.
- **Affected:** `config/automation.php`
- **Created:** 2026-09-29

### AW-027 — Automations are linear (no branches)

- **Category:** Product
- **Description:** A failed condition stops the run. There is no "otherwise" path or parallel branch.
- **Impact:** "If VIP do A, else do B" needs two automations with opposite conditions.
- **Status:** Open. `automation_nodes` and step snapshots allow branch references later (ADR-015).
- **Affected:** Automation builder, `StepRunner`
- **Created:** 2026-09-29

### AW-028 — Waits need the scheduler; precision is about one minute

- **Category:** DevOps
- **Description:** Steps after a wait are dispatched by `automation:dispatch-due` (every minute). Without
  `schedule:work` (dev) or the `schedule:run` cron (production), waits never finish.
- **Impact:** A reminder due at 10:00:00 runs between 10:00 and about 10:01.
- **Status:** Open (by design). See `docs/09-devops/queue-workers.md`.
- **Affected:** `routes/console.php`
- **Created:** 2026-09-29

### AW-029 — Emails use the platform mailer and sender

- **Category:** Product
- **Description:** `send_email` and team notifications go through the default Laravel mailer with the
  platform `MAIL_FROM_*` address. There is no per-business sender, reply-to or unsubscribe link.
- **Impact:** Customers see the AutoWave sender address.
- **Status:** Partly resolved 2026-10-02 (Phase 8). Emails now show the business name as the sender name
  (or a name set in Settings → Messaging) and can have a reply-to address. The from address is still the
  platform's `MAIL_FROM_ADDRESS` (a per-business domain needs SPF/DKIM setup), and there is no
  unsubscribe link or inbound email (AW-054).
- **Affected:** `app/Domain/Messaging/Providers/MailProvider.php`
- **Created:** 2026-09-29

### AW-030 — No retention for automation runs, logs and messages

- **Category:** Technical Debt
- **Description:** `automation_runs`, `automation_jobs`, `automation_logs` and `outbound_messages` grow
  forever.
- **Impact:** Table growth on busy tenants over months.
- **Status:** Open. Add a scheduled prune (e.g. 180 days for completed runs) before production scale.
  Phase 8 prunes `messaging_webhook_calls` after 14 days (`messaging:prune-webhooks`); conversations and
  `conversation_messages` are kept as the business's message history.
- **Affected:** Automation and messaging tables
- **Created:** 2026-09-29

### AW-031 — Messages and steps are delivered at least once

- **Category:** Technical Debt
- **Description:** Idempotency keys stop a retried step from creating a second message. But a worker that
  dies after the provider accepted a message, and before it was marked `sent`, leaves the message `sending`.
  `messaging:dispatch-pending` then sends it again after 15 minutes.
- **Impact:** With the simulated provider, none. With a real provider, a rare duplicate after a crash.
- **Status:** Open. The Meta Cloud API has no idempotency key. Phase 8 sends our message id as
  `biz_opaque_callback_data`, so a receipt for a message whose worker crashed still finds and updates it,
  but it cannot stop Meta from delivering a resend.
- **Affected:** `MessagingService::deliver()`, `DispatchPendingMessages`
- **Created:** 2026-09-29

### AW-032 — With the sync queue, a failing automation step can surface in the request

- **Category:** Technical Debt (local/test only)
- **Description:** With `QUEUE_CONNECTION=sync`, jobs dispatched after commit run inside the request that
  committed. A step that fails there is marked failed as expected, but its exception can surface in that
  request.
- **Impact:** Only when running without a queue worker. Local `.env` and production use Redis.
- **Status:** Open (by design of the sync driver). Use Redis locally (`QUEUE_CONNECTION=redis` plus a
  worker).
- **Affected:** Local development with the sync queue
- **Created:** 2026-09-29

### AW-033 — Automations act with system rights, not their builder's permissions

- **Category:** Security (design)
- **Description:** Automation actions run as the system. A user with `automation.create` or
  `automation.update` but without `leads.assign` or `leads.update` can build an automation that assigns
  leads or moves their stage, or that messages customers.
- **Impact:** Low while only Owner and Manager hold `automation.*` (the template default). Relevant if a
  business grants automation permissions to other roles.
- **Status:** Open. Documented in `docs/04-security/authorization.md`. Option: check the builder's
  permissions for each action when saving.
- **Affected:** Automation builder, custom roles
- **Created:** 2026-09-29

### AW-034 — Products, packages, reviews and shop sections stay hidden

- **Category:** Product
- **Description:** The website registry includes Products, Packages, Reviews and Shop sections, and the
  content builder reads them. But the records they display (products, packages, reviews, online orders) come
  in later phases, so these sections are never shown on the site. The editor lists them with a hint instead.
- **Impact:** Milestone 4 (master prompt §115) is complete except for products on the public site.
- **Status:** Partly resolved 2026-10-01 (Phase 7). The Products section now shows active products, with
  the cart when online ordering is on; the separate Shop section was removed because the cart lives in
  Products. Packages and Reviews stay hidden until their modules exist.
- **Affected:** `config/website.php`, `WebsiteContent`
- **Created:** 2026-09-30

### AW-035 — Uploaded images are not resized and EXIF data is not stripped

- **Category:** Security / Performance
- **Description:** Uploads are checked for type (jpg, png, webp; no SVG), size (4 MB) and dimensions, and
  stored under a random name in the tenant's folder. They are served as uploaded: no resized variants, no
  WebP conversion and no removal of EXIF metadata (which can include GPS location).
- **Impact:** Large photos slow the site down. Photos taken on a phone can reveal where they were taken.
- **Status:** Open — add image processing on the media queue (`docs/04-security/file-security.md`).
- **Affected:** `ManageMedia`, public site
- **Created:** 2026-09-30

### AW-036 — Public site content is rendered in the browser

- **Category:** SEO
- **Description:** The public site is an Inertia page. The server renders the title, description and Open
  Graph tags, but the section content is rendered by React in the browser (no SSR).
- **Impact:** Search engines that don't run JavaScript see only the meta tags. Google does run JavaScript,
  but indexing may be slower.
- **Status:** Open — options: Inertia SSR, or a server-rendered HTML version of the site.
- **Affected:** `resources/views/app.blade.php`, `pages/website/Home.jsx`
- **Created:** 2026-09-30

### AW-037 — Customers can't cancel or reschedule an online booking themselves

- **Category:** Product
- **Description:** Online booking creates the appointment and shows a confirmation. There is no link or page
  for the customer to cancel or change it.
- **Impact:** Customers must call or message the business, which changes the appointment in the app.
- **Status:** Open — add signed manage-booking links with notifications.
- **Affected:** Online booking
- **Created:** 2026-09-30

### AW-038 — Public forms have no captcha

- **Category:** Security
- **Description:** The enquiry and booking forms are protected by a hidden honeypot field, validation and
  rate limits per visitor IP and business (`website.enquiry`, `booking.online_per_hour`). There is no
  captcha.
- **Impact:** A determined bot that rotates IPs can still create spam leads or pending bookings.
- **Status:** Open — add a captcha (e.g. Cloudflare Turnstile) as a per-tenant option if spam appears.
- **Affected:** `EnquiryController`, `BookingController`, `ShopController` (tenant site)
- **Created:** 2026-09-30

### AW-039 — No custom domains

- **Category:** Product
- **Description:** Tenant sites are served on `{slug}.{base domain}` only. The `domains` table supports more
  hosts, but there is no UI, verification or TLS for custom domains.
- **Impact:** Businesses can't use their own domain yet.
- **Status:** Open — planned with domain management.
- **Affected:** Website, domains
- **Created:** 2026-09-30

### AW-040 — `branding.logo_path` setting is unused

- **Category:** Technical Debt
- **Description:** Phase 2 reserved a `branding.logo_path` tenant setting. The logo is now a media record
  (collection `logo`). `CreateTenant` still writes the setting as null, and nothing reads it.
- **Impact:** None.
- **Status:** Open — stop writing it in `CreateTenant::branding()` and drop it from existing tenants in a
  later cleanup.
- **Affected:** Tenant settings
- **Created:** 2026-09-30

### AW-041 — No online payments

- **Category:** Product
- **Description:** Orders are paid at pickup, on delivery or at the counter. Staff record each payment by
  hand (cash, UPI, card, bank transfer, other). There is no payment gateway, payment link or webhook.
- **Impact:** Website customers cannot pay in advance. Businesses reconcile UPI and card payments
  themselves.
- **Status:** Open — decided for Phase 7 (ADR-017). A gateway would write to `order_payments`.
- **Affected:** Orders, website checkout
- **Created:** 2026-10-01

### AW-042 — No product variants

- **Category:** Product
- **Description:** Each product has one price and one stock level. Sizes, colours or pack sizes are separate
  products.
- **Impact:** Stores with many variants (e.g. 100 ml / 250 ml) need one product per variant.
- **Status:** Open — decided for Phase 7 (ADR-017).
- **Affected:** Products, orders
- **Created:** 2026-10-01

### AW-043 — No returns or refunds

- **Category:** Product
- **Description:** Completed orders are final. Cancelling an open order returns its stock but keeps the
  recorded payments. A payment can be removed if it was recorded by mistake, but that is not a refund.
- **Impact:** A refund given in cash or UPI is not visible in AutoWave; the order still shows as paid.
- **Status:** Open — add returns (restock and refund lines) with reports.
- **Affected:** `ChangeOrderStatus`, `RecordOrderPayment`
- **Created:** 2026-10-01

### AW-044 — No coupons, taxes or website discounts

- **Category:** Product
- **Description:** Staff can give a flat discount on an order. There are no coupon codes, no percentage
  discounts, no discounts on website orders, and no tax (GST) lines or tax invoices.
- **Impact:** Prices must include tax. Promotions are shown with the original ("compare at") price only.
- **Status:** Open — tax handling belongs with invoices and reports.
- **Affected:** `PlaceOrder`, website checkout
- **Created:** 2026-10-01

### AW-045 — Carts do not reserve stock

- **Category:** Product
- **Description:** The website cart is stored in the visitor's browser. Stock is taken only when the order
  is placed. The cart quote warns about low stock, but another order can take the last item between the
  quote and checkout. There are no abandoned-cart reminders.
- **Impact:** A visitor may see "Only 1 left" at checkout and have to change the cart. Overselling is still
  impossible.
- **Status:** Open — by design for now (ADR-017).
- **Affected:** `OnlineShop`, website cart
- **Created:** 2026-10-01

### AW-046 — Receptionists can create orders but not update them

- **Category:** Product / RBAC
- **Description:** The default Receptionist role has `orders.view` and `orders.create`. Changing an order's
  status, recording payments and editing notes need `orders.update`, which only Owner and Manager have.
- **Impact:** At a busy counter the receptionist can complete a sale in one step (handed over now, payment
  received), but cannot mark a later pickup as completed.
- **Status:** Open — tenants can add `orders.update` to the role in Roles. Revisit the default with users.
- **Affected:** `config/rbac.php`
- **Created:** 2026-10-01

### AW-047 — Orders cannot be edited after they are placed

- **Category:** Product
- **Description:** Items, quantities, discount, delivery fee and customer are fixed once an order exists.
  Only notes, status and payments change.
- **Impact:** A wrong order must be cancelled (its stock goes back) and placed again under a new number.
- **Status:** Open — add item editing for open orders, going through `StockLedger`.
- **Affected:** Orders
- **Created:** 2026-10-01

### AW-048 — Customers get no order messages by default and cannot track orders

- **Category:** Product
- **Description:** The website shows a confirmation after checkout, and the owners get an email about each
  website order. The customer gets no message unless the business turns on the paused "Order ready"
  automation or builds its own. There is no order status page for customers.
- **Impact:** Businesses contact customers themselves about confirmation and pickup.
- **Status:** Open — add signed order-status links (see AW-037 for bookings).
- **Affected:** Automations, website checkout
- **Created:** 2026-10-01

### AW-049 — Delivery is a flat fee with no area check

- **Category:** Product
- **Description:** Delivery has one flat fee, optional free delivery above a subtotal and a free-text note
  (e.g. "within 5 km"). There are no delivery zones, distance pricing, delivery slots or address validation.
- **Impact:** The business must turn down out-of-area orders by cancelling them.
- **Status:** Open
- **Affected:** `CommerceSettings`, website checkout
- **Created:** 2026-10-01

### AW-050 — WhatsApp and Instagram are connected by hand (no Embedded Signup)

- **Category:** Product / Onboarding
- **Description:** Each business creates its own Meta app and pastes the phone number id, WhatsApp Business
  Account id, access token and app secret (or the Instagram token and secret) into Settings → Messaging,
  then pastes our callback URL and verify token into Meta.
- **Impact:** Connecting needs a technically confident owner or our help. A token that expires (for example
  the temporary 24-hour token) stops delivery until it is replaced; failures show on each message.
- **Status:** Open — decided for Phase 8 (ADR-018). Embedded Signup needs a platform Meta app, app review
  and Tech Provider onboarding.
- **Affected:** Settings → Messaging, `ConnectChannel`
- **Created:** 2026-10-02

### AW-051 — Media messages are placeholders

- **Category:** Product
- **Description:** Incoming images, videos, voice notes, documents, stickers, locations and contact cards are
  stored as text such as `[Image] caption`. The media is not downloaded, and the inbox cannot send files.
- **Impact:** Staff must open WhatsApp on the business phone to see a photo or document.
- **Status:** Open — download media on the `media` queue into tenant storage, with size and type checks.
- **Affected:** `MetaWebhookNormalizer`, inbox
- **Created:** 2026-10-02

### AW-052 — Everyone with inbox access sees every conversation

- **Category:** Product / Security
- **Description:** `conversations.view` shows all conversations. Assignment and the "Mine" tab only filter;
  there is no `conversations.view_own`.
- **Impact:** Receptionists and sales executives can read every customer conversation.
- **Status:** Open — same approach as AW-016 and AW-022 when requested.
- **Affected:** `InboxController`, `config/rbac.php`
- **Created:** 2026-10-02

### AW-053 — No "message received" automation trigger; no Instagram automation action

- **Category:** Product
- **Description:** `ConversationMessageReceived` is dispatched after each inbound message but no automation
  trigger uses it yet (auto-replies, keyword routing, away messages). Automations can send WhatsApp and
  email, not Instagram.
- **Impact:** New WhatsApp contacts still trigger "lead created" automations, but there are no replies to
  existing contacts' messages.
- **Status:** Partly resolved (Phase 9, 2026-10-03) — trigger `message.received` (subject `conversation`,
  fields channel, assigned and message text) with the AI actions draft reply, fill lead details and
  summarise (ADR-019). Still open: an Instagram "send message" automation action.
- **Affected:** `config/automation.php`, `ReceiveInboundMessage`
- **Created:** 2026-10-02

### AW-054 — No inbound email

- **Category:** Product
- **Description:** Email is outbound only. Replies go to the reply-to address (Settings → Messaging), not to
  the inbox.
- **Impact:** Email conversations happen outside AutoWave.
- **Status:** Open — needs an inbound mail provider (webhook) and email threading.
- **Affected:** Messaging
- **Created:** 2026-10-02

### AW-055 — Customer messages are sent to OpenRouter without redaction

- **Category:** Security / Privacy
- **Description:** Reply drafts, summaries and lead extraction send conversation text (which may contain
  phone numbers, addresses or other personal details the customer typed) to OpenRouter and the chosen
  model provider. Assistant tool results exclude phone numbers and e-mail addresses, but message text is
  not redacted. There is no data processing agreement flow or per-business consent screen beyond the
  Settings → AI switch.
- **Impact:** Businesses with strict privacy needs must switch AI off. See `docs/04-security/ai-data.md`.
- **Status:** Open — options: pattern-based redaction before sending, a zero-retention OpenRouter
  provider setting, and a consent step when AI is first used.
- **Affected:** `AIService`, `Transcript`
- **Created:** 2026-10-03

### AW-056 — The AI cap is approximate and cost is in USD

- **Category:** Product / Billing
- **Description:** The monthly token cap is checked before each call, so the call that crosses it (and
  calls running at the same moment) still complete; the meter total is cached for 60 seconds. Cost is
  recorded only when OpenRouter reports it, in USD. There are no plan-based allowances, top-ups or
  alerts before the cap is reached.
- **Impact:** A business can go slightly over its allowance; platform cost reporting is in USD.
- **Status:** Open — tie allowances to plans when billing is built.
- **Affected:** `AIUsageMeter`, `AIGateway`, Super Admin → AI usage
- **Created:** 2026-10-03

### AW-057 — Assistant chats are not saved and answers are not streamed

- **Category:** Product
- **Description:** The assistant is stateless: the browser keeps the chat and sends the last
  `ai.context.assistant_turns` turns. Refreshing the page loses the chat. Answers arrive in one piece
  after the model (and up to three tool rounds) finish.
- **Impact:** Long answers can take several seconds with only a spinner.
- **Status:** Open — streaming (server-sent events) and saved chats when requested.
- **Affected:** `AssistantController`, `business/assistant/Index.jsx`
- **Created:** 2026-10-03

### AW-058 — Automatic lead extraction waits from the first message of a burst

- **Category:** Product
- **Description:** The extraction job is unique per conversation and delayed `ai.extraction.delay_seconds`
  from the first message of a burst, not the last. Messages that arrive after the job runs are read by
  the next burst. Automatic runs stop after `ai.extraction.max_auto_runs` per lead; staff can still use
  "Fill details from messages".
- **Impact:** A detail typed several minutes into a conversation may be picked up one burst later.
- **Status:** Open — acceptable for V1.
- **Affected:** `QueueLeadExtraction`, `ExtractLeadFromConversation`
- **Created:** 2026-10-03