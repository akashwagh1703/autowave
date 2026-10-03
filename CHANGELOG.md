# Changelog

All notable user-visible changes are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Categories: Added, Changed, Fixed, Security, Deprecated, Removed.

## [Unreleased]

### Added

- **Pay for a plan online.** Once AutoWave's Razorpay account is connected, owners can pay by UPI, card,
  net banking or wallet in Razorpay Checkout and the plan is active straight away, with no approval. It
  stays off until the keys are set and Super Admin switches it on; UPI and bank transfer keep working.
- **Coupons for plans.** Super Admin creates discount codes in **Coupons** (percent or fixed amount, for
  chosen plans and periods, with a use limit and dates). Owners enter the code when paying; the discount is
  shown on the invoice, and a 100% coupon activates the plan without paying.
- **Invoice PDFs.** Owners and Super Admin download invoices as PDF, and the payment confirmation email
  has the PDF attached.
- **Plans and billing.** Every business starts a 14-day free trial, then picks Starter (₹499/month),
  Growth (₹1,499) or Business (₹3,999), monthly or yearly (two months free). Owners open **Settings →
  Plan and billing** to see their plan, usage and invoices, and pay by UPI (ID and QR code) or bank
  transfer, then enter the UTR; Super Admin approves it in **Payments** and the owner gets an invoice
  numbered `AW/2026-27/0001`. Upgrades start straight away with credit for the unused days; renewals and
  downgrades start when the current period ends. Reminders go out 7, 3 and 1 days before the end.
  Super Admin edits plans, records cash or cheque payments, gives free periods, changes a business's plan,
  and sets the payment details, GST and the payment methods in **Settings → Billing**. GST (Tax Invoice
  with CGST/SGST or IGST) and online payments are switches, off for now.
- Plan limits: storage, AI usage, active automations and Instagram follow the plan. With **Enforce plans**
  switched on (off by default), a business that hasn't paid 7 days after its end date becomes read-only,
  and after 30 days only Billing opens and its website goes offline.
- **Files in the inbox.** Photos, videos, voice notes and documents that contacts send on WhatsApp or
  Instagram are now saved and shown in the conversation (photos inline, videos and voice notes playable,
  documents to open or download) instead of "[Image]". They are stored privately and count against the
  storage allowance; a file that is too large (over 16 MB) or of a type we do not accept keeps its
  "[Image]" text with the reason. On WhatsApp, staff can attach one photo, video, voice note or document
  to a reply with the paperclip button, with the text as its caption. Instagram replies stay text-only.
  Needs a queue worker for the `media` queue.
- Website sections **Video** (up to three videos played on the page) and **Downloads** (up to ten
  brochures, price lists, menus or forms). Add them from Website → Add section and upload the files on
  the section's page; the section shows once it has a file. Removing the section deletes its files.
- **Videos and brochures** for products, services and courses: one MP4 or WebM video (up to 50 MB) and up
  to three PDF, Word, Excel or image files per item, added from the product or service edit page or the
  course's **Files** button. They appear on the business website under the item as a **Watch video**
  button and download links, and are deleted together with the item.
- **Documents** on customer and student pages: upload PDF, Word, Excel, JPG or PNG files (up to 10 MB
  each, an optional name such as "Aadhaar card"), open or download them, and delete them. Files are
  private: they are opened through AutoWave after a permission check and can be kept in a private MinIO
  bucket. New permissions **View documents** and **Manage documents** (Manager and Receptionist can
  upload; Accountant can view).
- A storage allowance per business (1 GB by default) covering images and documents. The documents card
  shows how much is used, and Super Admin → Tenants shows each business's usage and can change its
  allowance.
- First production deployment at autowave.co.in (marketing, business app, Super Admin and tenant
  websites on `*.autowave.co.in`, HTTPS with a wildcard certificate). `scripts/deploy.sh` builds a new
  release from GitHub and switches to it with no downtime, keeps the last five, and rolls back with
  `deploy.sh rollback`. Runbook for turning on email, AI and WhatsApp/Instagram in production.
- Uploaded images can be stored on MinIO (S3-compatible object storage) and served from
  `media.autowave.co.in`. `php artisan autowave:media-move` copies existing images across, and the health
  check covers object storage. An upload during a storage outage shows "could not be saved" instead of an
  error page.
- Super Admin → Settings with **Require email confirmation for new accounts**. When switched off, new
  sign-ups can use the app straight away without a confirmation email (for testing, or while email is not
  set up). `php artisan autowave:admin-password` sets a new password for a platform admin.
- `OPENROUTER_TIMEOUT` and `OPENROUTER_REASONING` (`off`, `low`, `medium`, `high`) so slower or free
  OpenRouter models can be used; queued AI jobs get a matching timeout and `autowave:health` fails when
  the queue's `retry_after` is shorter than it.
- Phase 10 Additional verticals:
  - **Coaching centres:** courses and batches (days, times, teacher, capacity, fee); admit students from
    an enquiry, an existing customer or a walk-in, with the fee split into instalments and an optional
    first payment; record and remove fee payments; change fee plans; drop, complete or re-activate
    students; take and correct attendance per class; schedule demo classes for enquiries and mark them
    attended or no-show; a Fees page with overdue and upcoming instalments; Settings → Coaching.
  - Hourly fee reminders: automations "Fee due reminder" (WhatsApp, paused by default) and "Follow up on
    overdue fees" (adds a task), plus "Welcome new students" and "Confirm demo classes" templates.
  - Coaching website section "Courses" with fees, schedules and an enquire button.
  - **Cafes and restaurants:** menu items marked veg / non-veg / egg and "available now" (bulk sold-out);
    tables; reservations from the team or the website (party size, date, time) with confirm, seat,
    complete, no-show and cancel; dine-in orders by table (walk-ins need no customer) with "Add items";
    a kitchen screen that marks tickets ready; Settings → Reservation settings; website section
    "Reserve a table".
  - **Turfs:** hourly, peak and weekend rates per turf; the price shows on the website slot picker and the
    team slot picker and is stored on the booking; advances and payments recorded on the appointment.
  - **Local Commerce** (renamed from Local Store): coupon codes (percentage with a cap, or a fixed
    amount; minimum order, dates, usage limit, online or in-store) on the Offers page, applied on the
    order form and in the website cart; dashboard widgets for repeat customers and top products.
  - Dashboard widgets: students, admissions, fees due, demo classes, reservations today, kitchen queue,
    top products.
  - Assistant tools for students (batches, seats left, overdue fees) and reservations.
  - Demo businesses ABC Coaching, ABC Cafe and ABC Store with sample data (local only).
- Phase 9 AI:
  - Inbox: "Suggest reply" writes a reply (or improves what you typed) from the conversation and your
    business details. It goes into the message box; AI never sends anything by itself.
  - Summaries of conversations, leads and customers, kept until something new happens.
  - "Fill details from messages" on a lead reads what the lead wrote and fills in the empty name, email,
    interest and estimated value. Different values are shown as suggestions to apply or dismiss. It can
    also run automatically after a lead writes.
  - Assistant: owners and managers ask questions such as "How many appointments tomorrow?" and get answers
    from their own data. A Write tab drafts offers, WhatsApp promotions, Instagram captions, greetings and
    review replies.
  - "Write with AI" on website section text and automation messages.
  - Automations: new trigger "Message received" and actions "Fill lead details with AI", "Draft a reply
    with AI" (waits in the inbox for someone to send) and "Add an AI summary".
  - Settings → AI: switch AI on or off, automatic lead details, tone, notes for AI, and this month's usage.
  - Super Admin → AI usage: requests, tokens and cost per business by month, and a monthly allowance per
    business. AI totals on the admin dashboard.
- Phase 8 Messaging:
  - Settings → Messaging: connect the business's own WhatsApp number (Meta Cloud API) and Instagram account.
    The details are checked with Meta before saving; tokens and app secrets are stored encrypted and never
    shown again. The page shows the webhook address and verify token to paste into Meta, and when the last
    webhook arrived.
  - Inbox: WhatsApp and Instagram conversations in one place, with open, mine, unassigned, closed and all
    tabs, channel filter and search. Reply, assign to a team member, close and reopen. Unread counts show in
    the menu, and the inbox refreshes by itself.
  - New WhatsApp or Instagram contacts become leads automatically (or are matched to an existing customer
    or lead by phone), and their messages appear on the timeline.
  - Delivery and read ticks on sent messages, and Meta's reason when a message fails.
  - WhatsApp templates: sync approved templates from Meta, send them from the inbox, and use them in the
    automation WhatsApp action with a field per variable.
  - Opt-out: customers who reply STOP get no more automation messages or templates; START opts them back
    in. Staff can also mark a contact opted out.
  - Quiet hours: automation messages that would go out at night wait until the morning (off by default).
  - Email sender name and reply-to address.
  - "Chat" buttons on customer and lead pages open their WhatsApp conversation.
  - `php artisan messaging:simulate-inbound` to try the inbox locally without Meta.
- Phase 7 Commerce:
  - Products: a catalogue with categories, SKU, price and original price, description, a photo and an
    active flag. Search, category, status and stock filters, sorting, and bulk activate, deactivate and
    delete.
  - Stock: switch on stock tracking per product, set the opening stock and a low-stock level, and adjust
    stock (new stock received, count correction, damaged, used in the business) with a full history. Stock
    never goes below zero, and a banner and dashboard widget show low-stock products.
  - Orders: take an order for a new or existing customer, in store, for pickup or for delivery (address and
    fee), with an optional discount. Mark it handed over and paid in the same step for counter sales.
  - Order page: confirm, mark ready ("Ready for pickup" / "Out for delivery"), mark completed or delivered,
    cancel with a reason (the stock goes back), record payments (cash, UPI, card, bank transfer, other) and
    remove one recorded by mistake. Orders show on the customer page and timeline.
  - Orders list with open, pending, confirmed, ready, completed and cancelled tabs, search, and payment,
    source and date filters.
  - Order settings: online ordering, auto-confirm, pickup, delivery fee, free delivery above an amount,
    minimum order and a delivery note.
  - Website shop: the Products section shows the catalogue with prices and "Out of stock". Visitors add
    products to a cart, see the total with any delivery fee, and order for pickup or delivery. Website
    orders appear in Orders (pending until confirmed, unless auto-confirm is on) and the owners get an email.
  - Automations: triggers for orders placed, confirmed, ready, completed, cancelled and paid; conditions on
    order status, source, type, payment and total; order number, items, total and type in messages. A new
    paused template tells customers when their order is ready.
  - Dashboard: orders today, revenue today, low stock and repeat customers for local stores (orders and
    revenue for cafes too). Salons get product sales, and their revenue today now includes completed orders.
- Phase 6 Website:
  - Website page in the business app: the site's status and address, a setup checklist, publish and
    unpublish, and a preview link for sites that are not published yet.
  - Section editor: add, edit, reorder, show or hide and remove sections. Each section has its own form
    (headings, texts, buttons, testimonials, offers, FAQ) and hides itself while it has nothing to show.
  - Design and logo (template, colours, logo upload) and Business details (description, contact details,
    address, opening hours, social links, search title and description).
  - Images: logo, hero banner and gallery uploads with alt text and ordering.
  - Public website: hero, about, services with prices, team, gallery, testimonials, offers, FAQ, contact
    with "Get directions", social links and a WhatsApp button. Pages have proper titles, descriptions and
    share previews, without "· AutoWave" in the title.
  - Enquiry form: enquiries become leads with the source "Website" (or update the existing lead with that
    phone number) and appear on the lead's timeline.
  - Online booking: visitors pick a service, a staff member or "Any available", a date and a free time.
    Bookings appear in the calendar marked as online, pending until confirmed unless auto-confirm is on.
    Settings for minimum notice, how far ahead and "Any available".
  - Automations: new trigger "Website enquiry received" and the condition "Appointment source is Online
    booking".
  - The Products section is ready and appears once products are added (Phase 7).
- Phase 5 Automation:
  - Automations page: every automation with its steps in plain language, run counts, an on/off switch,
    and figures for runs, completed, in progress and failed in the last 7 days.
  - Automation builder: choose what starts it (a new, updated, moved, assigned or converted lead; a new
    customer; an appointment booked, confirmed, rescheduled, completed, cancelled or marked no-show). Then
    add steps in any order:
    - conditions, e.g. "lead stage is New" or "estimated value at least 5000";
    - waits, e.g. "wait 4 hours" or "until 24 hours before the appointment";
    - actions: send WhatsApp, send email, notify the team, create a follow-up task, assign the lead, move
      the lead's stage, tag the customer.
    Messages can include the customer's name, the appointment date and time, the business name and more.
  - Optional "run only once per record".
  - Run history and run pages: every step's status, a readable log of what happened and why, and the
    messages sent. Retry failed runs, cancel runs in progress, and send failed messages again.
  - Rescheduling an appointment moves its pending reminders.
  - Automation tasks and messages appear on lead and customer timelines as "Automation".
  - New businesses get default automations for their type: lead welcome, follow-up of untouched leads,
    appointment confirmation and reminder, no-show rebooking task, thank-you after a visit. Templates that
    message customers start paused. Existing businesses receive them once.
  - WhatsApp messages are simulated (logged, not delivered) until a provider is connected. Email is sent
    with the platform mailer.
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

- Public website forms (enquiry, booking) have a hidden spam trap, validation and rate limits per visitor
  and business. Image uploads accept only jpg, png and webp (no SVG) within size and dimension limits, and
  are stored under random names in the business's own folder.
- Unpublished websites return 404; their preview links are signed, expire after an hour and are not indexed.
- CRM records can only reference records of the same business (database-level composite keys); another
  business's lead or customer id returns 404.
- Disabled features (modules) return 404 for their pages and actions.
- Business creation is rate limited and capped per user (default 3).
- Suspended users are blocked at login and signed out mid-session.
- Database-level guarantee that roles cannot be assigned across businesses.
- Tests refuse to run against any database not named `*_testing`.

- Security headers middleware (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`, HSTS over HTTPS).
