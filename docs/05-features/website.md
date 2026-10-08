# Website

- **Status:** ✅ Phase 6: builder, public site, enquiry form and online booking; products and cart in Phase 7
- **Last updated:** 2026-10-05 (structured data, robots.txt and sitemap)

## Purpose

Every business gets a fast, mobile-friendly public website at `{slug}.{root_domain}`, built from
configurable sections instead of a page builder (master prompt §22, §23 and §111; ADR-012, ADR-016). The
business can edit it without help: design, content, images, publishing, the enquiry form and online booking.

## User flows

### Business (app host, `/website`)

1. **Overview** (`/website`) shows:
   - the live/draft status, with **View website**, **Preview** (for drafts) and **Publish / Unpublish**;
   - the section list: show/hide, move up and down, edit, remove and add, with a status for each section
     ("On your site", "Waiting for content", "Hidden");
   - the **online booking** settings;
   - a "Get your website ready" checklist.
2. **Design** (`/website/design`): template, brand colour and logo upload.
3. **Business details** (`/website/details`):
   - display name, tagline and description;
   - phone, WhatsApp, email, address, city and opening hours;
   - social links;
   - SEO title and description.
4. **Section edit** (`/website/sections/{id}/edit`): the section's fields, rendered from
   `config/website.php`, plus its images for the hero and gallery.

### Visitors (tenant host)

- The page shows a sticky header (logo, section links with the current one underlined, **Call** and the main
  action), then the enabled sections in order, a call-to-action band before the contact section, and a
  footer with links, address, hours, contact and social links. The main action is the first of **Book**,
  **Reserve a table**, **Order online** or **Contact** that the site offers.
- On phones the header links move into a full-screen menu and a bottom bar offers **Call**, **WhatsApp**
  and the main action. Elsewhere a floating WhatsApp button appears when a WhatsApp number is set.
- Sections fade in as they scroll into view (off when the visitor prefers reduced motion), and their
  backgrounds alternate.
- **Enquiry form** (contact section): name, phone, optional email, interest and message. The form is replaced
  by the section's success message after sending. Owners are emailed the enquiry (and each online booking)
  unless Settings → Messaging → Email alerts is off.
- **Online booking** (booking section) goes through these steps:
  1. service (when the business has the service engine);
  2. staff or resource, or "Any available";
  3. date;
  4. free time;
  5. details;
  6. confirmation ("We will confirm shortly" while the booking is pending).
- **Book** on a service card jumps to the booking section with that service chosen.
- **Online ordering** (products section, Phase 7): add to cart, a cart button in the header, a cart drawer
  with server-priced totals, and checkout for pickup or delivery. The rules are in
  [commerce.md](commerce.md#online-ordering-website).

## Section types (`config/website.php`)

| Type | Shows | Needs |
|---|---|---|
| header, footer | Logo, name, buttons / text, social links | pinned, not removable |
| hero | Headline, subheadline, main button (book, contact, WhatsApp or call), optional image | — |
| about | Heading and text (falls back to the business description) | — |
| services | Active services by category, prices, durations, **Book** buttons | service engine |
| products | Active products by category, optional prices, "Out of stock", **Add to cart** when online ordering is open ([commerce.md](commerce.md)) | commerce engine, at least one active product |
| packages | Active packages (`is_package`); hidden while none | service engine |
| gallery | Uploaded photos with a lightbox | — |
| video | Up to three uploaded videos (MP4 or WebM, 50 MB each) played on the page, with optional captions | at least one video; not in any default layout, added from the editor |
| downloads | Up to ten uploaded files (PDF, Word, Excel, JPG, PNG, 10 MB each) as download cards | at least one file; added from the editor |
| team | Active staff or resources and what they offer | booking engine |
| testimonials, offers, faq | Items entered in the editor | — |
| reviews | Hidden until the Reviews module exists | reviews module |
| contact | Contact details, opening hours, "Get directions", enquiry form | form needs the leads module |
| booking | Online booking widget | booking engine, online booking on, at least one bookable resource |

## Rules

- **When the site is served.** The site is served only while the `website` module is enabled and the site
  is published. Drafts return 404, except through a signed preview link.
- **Hidden sections.** Disabled sections are never rendered. Sections with a data source are hidden while
  they have nothing to show, and so are sections the tenant's engines or modules don't support.
- **Order.** Header first and footer last; the rest by `sort_order`. There is one section per type.
- **Section files.** Video and Downloads files belong to the section (`attachments`, public). They are
  uploaded on the section's edit page with `website.manage`, and removing the section deletes them; hiding
  it keeps them. Products, services and courses show their own video and brochures inside their sections.
- **Templates.** Templates change only the look. Switching keeps all content. Each has its own layout
  (`siteLayout` in `utils/websiteTheme.js`):

  | Template | Hero | Type | Notes |
  |---|---|---|---|
  | modern | Split: text left; photo, else a card of real services / turfs / products / courses / team | Plus Jakarta Sans | rounded buttons |
  | premium | Full-screen dark; photo, else brand glow with glass cards of real items; info strip (open, find us, call) | Playfair Display | dark header and reviews, services as a price list |
  | elegant | Soft tint, arch-framed photo or monogram | Cormorant Garamond | centred headings with an ornament, services as a price list |
  | minimal | Editorial: huge name, hours / location / contact columns | Inter | numbered section labels, flat cards |
  | corporate | Brand panel with a diagonal pattern and a card of real items | Plus Jakarta Sans | — |

- **Default wording.** Where the owner left a field empty (section intro, hero subheadline) or kept a
  catalogue default heading, the site uses wording for the business type from `modules/website/copy.js`
  (e.g. "Our menu" for a cafe, "Meet our doctors" for a clinic). Text the owner wrote is always shown as is.
- **Business data.** Business data is never copied into sections. The site reads services, staff, hours,
  media and the business profile at request time.
- **Enquiries.**
  - An enquiry creates a lead with the source "Website". If an open lead with the same phone exists, a
    `website_enquiry` activity is added to it instead.
  - Automations: trigger **Website enquiry received** (`website.enquiry`), payload `new_lead`.
- **Online booking.**
  - Settings come from `booking_settings.online`, over the defaults in `config('booking.online')`:
    - on/off;
    - auto-confirm (off, so online bookings start as pending);
    - minimum notice (60 min);
    - booking window (30 days);
    - "Any available" (on).
  - Slots are the union of the free slots of every qualifying resource, from the earliest allowed start
    onwards.
  - Bookings go through `BookAppointment` with `source = website`. The customer is matched by phone or
    created, and the customer timeline shows "booked … online".
  - Automations can use the condition **Appointment source**.
- **Uploads.**
  - JPG, PNG or WebP only (sniffed MIME type, no SVG), up to 4 MB, 100–6000 px per side.
  - Limits: logo 1, hero 1, gallery 24. Uploading a new logo or hero image replaces the old one.
- **Search engines and link previews** (rendered on the server in `app.blade.php`, so crawlers and
  WhatsApp/Facebook previews see them without JavaScript):
  - title, description, `og:*` tags and a canonical link; the preview image is the hero photo, else the logo;
    the logo is also the favicon;
  - schema.org `LocalBusiness` JSON-LD (`WebsiteSchema`): name, description, logo, image, phone, email,
    address, map link and social links; the type follows the business type (`BeautySalon`, `MedicalClinic`,
    `SportsActivityLocation`, `EducationalOrganization`, `CafeOrCoffeeShop`, `Store`);
  - `/robots.txt` and `/sitemap.xml` (`SeoController`): open with a sitemap while the site is live; a draft or
    locked site answers `Disallow: /` and its sitemap returns 404. Previews carry `noindex` and no JSON-LD.

## Database

`website_templates`, `website_configs`, `website_sections` (unique `(tenant_id, type)`) and `media`. Settings:
`branding`, `business_profile` (adds `whatsapp`, `opening_hours`, `social`) and `booking_settings.online`. See
`docs/03-database/schema.md`.

## Code

- **Config:** `config/website.php` (sections, media, social networks, WhatsApp text, enquiry limits,
  preview lifetime); `config/booking.php` (`online`, `sources`).
- **Domain:**
  - `app/Domain/Website/{Support/SectionCatalog, Support/SectionSchema, Support/WebsitePreview}`;
  - `app/Domain/Website/{Actions/ManageWebsiteSections, Actions/UpdateWebsiteSettings, Actions/SubmitEnquiry}`;
  - `app/Domain/Website/{Services/WebsiteContent, Services/OnlineBooking, Events/WebsiteEnquiryReceived}`;
  - `app/Domain/Media/{Models/Media, Actions/ManageMedia}`.
- **HTTP:**
  - App host: `App\WebsiteController`, `App\WebsiteSectionController` and `App\WebsiteMediaController`, with
    `WebsitePresenter`.
  - Tenant host: `Website\HomeController`, `Website\EnquiryController` and `Website\BookingController`,
    behind the `EnsureWebsiteIsLive` middleware (`site.live`).
- **UI:**
  - Public site: `pages/website/Home.jsx` and `modules/website/{site, Hero, sections, ContactSection,
    BookingSection, ReservationSection, ShopSection, CoursesSection}.jsx`; default wording in
    `modules/website/copy.js`; template layouts and colour helpers in `utils/websiteTheme.js`. Template fonts
    are loaded in `app.blade.php`.
  - Editor: `pages/business/website/{Index, Design, Details, SectionEdit}.jsx` and
    `modules/website/{SchemaFields, MediaManager}.jsx`.

## Security

- `WebsiteConfig`, `WebsiteSection` and `Media` use `BelongsToTenant`, which fails closed. Route model
  binding runs after tenant resolution, so another tenant's section or image id returns 404.
- **Permissions:** `website.view` for the editor pages, `website.manage` for every change (Manager role:
  `website.*`).
- **Public forms:**
  - honeypot field;
  - rate limits per IP and website host (enquiry 5/min and 20/hour, booking 10/hour, slots 60/min,
    orders 10/hour, cart quotes 60/min);
  - forms answer only while the website is live;
  - input is validated server-side;
  - leads and appointments are created in the tenant resolved from the host.
- **Uploads:** see `docs/04-security/file-security.md`.
- The page receives public data only. Preview links are signed, expire after 60 minutes, are bound to the
  tenant id, and carry `noindex`.

## Testing

`tests/Feature/Website/`:

- `WebsiteEditorTest`: overview, sections, schema validation, design and details, online booking settings,
  permissions, module gate, isolation.
- `WebsiteMediaTest`: storage path, replacement, type/size/dimension checks, limits, order, deletion,
  isolation, permissions.
- `PublicWebsiteTest`: database-driven content, hidden empty sections including products, SEO tags, media,
  signed preview, module gate, turf.
- `WebsiteEnquiryTest`: lead creation and automation run, duplicates, validation, honeypot, throttling, form
  gates, isolation.
- `OnlineBookingTest`: slots, notice and window, "Any available", pending vs auto-confirm, rules, turf
  without services, switch-off, honeypot and throttle, the `appointment.source` condition.
- `WebsiteProvisioningTest`: provisioning.
- `OnlineShopTest` (Phase 7): products section, cart quote, website orders, delivery rules, honeypot and
  throttle, owner alert.

## Known limitations

See `docs/00-overview/known-issues.md` (AW-034 to AW-040, AW-045): reviews stay hidden until
their phases; carts don't reserve stock; images are not resized; the section content isn't server-rendered; there's no customer
self-cancel or reschedule; there's no captcha; there are no custom domains yet.
