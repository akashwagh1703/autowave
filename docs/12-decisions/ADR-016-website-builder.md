# ADR-016: Website builder: schema-driven sections, tenant media, public forms through domain actions

- **Status:** Accepted
- **Date:** 2026-09-30

## Context

Phase 6 turns the provisioned website (ADR-012) into something a business can run on its own (master prompt
§111, milestone §115): templates, sections, branding, an enquiry form, online booking, and service and
product display. The constraints are:

- **Every business type uses the same builder.** A salon shows services, staff and online booking; a turf
  shows courts and booking without services; a café shows products and offers. New section types must not
  need new editor screens ("configure, don't hard-code").
- **Content lives where it belongs.** Services, prices, staff, working hours and contact details already
  exist in their own modules. Copying them into the website would drift out of date.
- **The public site is unauthenticated and on the tenant's host.** Its forms create leads and appointments,
  so they must be protected against spam and floods, and they must stay inside the tenant resolved from
  the host.
- **Uploads are the first user files on the platform**, so they must follow the file-security rules
  (tenant folder, content sniffing, no SVG).
- **Products come in Phase 7 (Commerce).** The product catalogue does not exist yet, but the section has to
  be ready for it.

## Decision

1. **A section registry in `config/website.php`.** Each section type declares its label, its editable
   `fields` (text, textarea, boolean, select, or list of sub-fields, with length limits and defaults), its
   `data` source, an optional `media` collection, its engine or module requirements, and whether it is pinned
   (header first, footer last) or removable.
   - `SectionSchema` builds validation rules from the registry and normalises the input: strings are
     trimmed, unknown keys are dropped, and list items keep only their defined fields.
   - The business-app editor renders the same definitions (`SchemaFields.jsx`). A new section type is a
     config entry plus a public renderer.
2. **Sections store only their own copy.** `WebsiteContent` builds the public page at request time. It reads
   services, staff, working hours, media and the business profile from their own tables. It sends only public
   fields: no member ids, no customer data, no internal notes.
3. **Sections with a data source are hidden while they have nothing to show.** This covers services with no
   active services, a gallery with no photos, testimonials with no items, and products, packages, reviews and
   shop until their phases supply data. The editor shows these as "Waiting for content" with a hint. This is
   how the Products section ships now and lights up in Phase 7 without changing the website.
4. **One section per type per tenant.** A unique `(tenant_id, type)` index enforces it. The header and
   footer cannot be removed, only edited. Reordering sends the full list of ids and is rejected if it is out
   of date.
5. **A tenant `media` table and one upload path.**
   - `ManageMedia` checks the file's size, extension, sniffed MIME type (JPEG, PNG or WebP; SVG is refused)
     and dimensions.
   - It enforces each collection's image limit, and replaces the old image in single-image collections (logo,
     hero).
   - Files get a random name under `tenant/{tenant_id}/{logo|website}/` on the configured disk.
   - Public URLs are host-relative (`/storage/...`), so each tenant's site serves them from its own host.
6. **Business details are edited in the website editor.** The `branding` and `business_profile` tenant
   settings stay the single source of truth; automation message variables use them too. The profile gains
   `whatsapp`, `opening_hours` and `social`.
7. **Public forms call the existing domain actions.**
   - **Enquiry** (`SubmitEnquiry`): creates a lead with source `website` through `CreateLead`. If an open lead
     with the same phone number already exists, it adds a `website_enquiry` activity to that lead instead.
     After commit it fires `WebsiteEnquiryReceived`, which is the automation trigger `website.enquiry`.
   - **Online booking** (`OnlineBooking`):
     - It uses the availability service and `BookAppointment` with `source = website`, so the same
       exclusion constraint and locking protect it.
     - Tenant settings (`booking_settings.online`) control whether it is on, auto-confirmation (off by default,
       so online bookings start as pending), minimum notice, how far ahead visitors can book, and whether
       "any available" is offered.
     - "Any available" tries each qualifying resource in order.
     - Automations can branch on the new `appointment.source` condition field.
8. **Public endpoint protection.**
   - A honeypot field: bots that fill it get a normal "sent" response and nothing is stored.
   - Rate limiters per visitor IP and website host (`website-enquiry`, `website-booking`, `website-slots`).
   - `EnsureWebsiteIsLive`: forms answer only while the website module is on and the site is published.
   - Inertia form posts that hit a rate limit get a form error instead of an error page.
9. **Draft preview through a signed link.**
   - `/preview?preview={tenant_id}&expires&signature` is signed over the path and query only, because it is
     created on the app host and opened on the tenant host.
   - The tenant id in the signed query must match the tenant resolved from the host, so one business's link
     never opens another's draft.
   - Previews carry `noindex`.
10. **Server-side SEO tags.**
    - `app.blade.php` renders the title, description and Open Graph tags for `website/*` pages. The tags use
      Inertia's `inertia="…"` keys, so the client takes them over without duplicating them.
    - Public sites skip the product-name title suffix.

## Alternatives

- **Free-form page builder (blocks, drag-and-drop, HTML).** Rejected: harder to keep mobile-friendly and
  fast, it invites XSS through custom HTML, and it doesn't match the master prompt's "no page builder" rule.
- **A hand-written editor screen per section type.** Rejected: every new section or business type would need
  frontend work. The schema-driven editor covers all current types.
- **Copying services and prices into section configuration.** Rejected: the copies would drift out of date.
  Reading at render time keeps one source of truth.
- **Captcha (reCAPTCHA or hCaptcha) on public forms.** Deferred: it needs a third-party script and keys, and
  it adds friction. A honeypot plus rate limits is enough for launch; a captcha can be added behind config
  if spam appears (see known issues).
- **Server-side rendering (Inertia SSR) for SEO.** Deferred: it needs a Node SSR process in production. Server
  meta tags cover titles and link previews for now.
- **Storing images in `tenant_settings` JSON.** Rejected: there'd be no per-file metadata, no ordering and no
  audit trail, and deleting files safely would be harder.

## Consequences

- Adding a section type means adding a config entry, a renderer in `resources/js/modules/website/sections.jsx`,
  and a data branch in `WebsiteContent` if it shows records.
- Phase 7 only needs to return products and packages from `WebsiteContent::data()` for those sections to
  appear. Tenants don't need to do anything.
- Uploaded images are served from the public disk through `php artisan storage:link`. Production must run
  it, or set `WEBSITE_MEDIA_DISK` to an object-storage disk.
- The site is a client-rendered React page. Section content is not in the initial HTML, so crawlers that
  don't run JavaScript see only the title and meta tags.
- Online bookings create customers, which fires `customer.created` and `appointment.created` automations,
  just like bookings made by staff.
