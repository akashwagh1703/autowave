# Authorization (RBAC)

> Status: **implemented in Phase 1** (ADR-011).

## Model

`users` ↔ `tenant_users` (membership) ↔ `user_roles` ↔ `roles` ↔ `role_permissions` ↔ `permissions`.

- Roles are tenant-scoped (`BelongsToTenant`). `roles.tenant_id = null` rows are **templates**, synced from
  `config/rbac.php` and copied into each new tenant by `ProvisionTenantRoles`.
- Template roles: Owner (`grants_all`, locked), Manager, Receptionist, Sales Executive, Staff, Accountant.
- `grants_all` roles receive every permission, including permissions added later.
- `user_roles` has composite FKs to `(tenant_users.id, tenant_id)` and `(roles.id, tenant_id)` — cross-tenant
  assignment is impossible at the database level. `AssignRole` also checks in code.

## Permission catalogue

Source of truth: `config/rbac.php` (synced by `RbacSeeder`). Keys are `{group}.{action}`:

```text
customers.view  customers.create  customers.update  customers.delete
leads.view  leads.create  leads.update  leads.assign  leads.delete
services.view  services.manage
resources.view  resources.manage
appointments.view  appointments.create  appointments.update  appointments.cancel
products.view  products.create  products.update  products.delete
orders.view  orders.create  orders.update
automation.view  automation.create  automation.update  automation.delete
website.view  website.manage
conversations.view  conversations.reply  conversations.assign
ai.use  ai.assistant
reports.view
users.view  users.manage
roles.manage
settings.view  settings.update
```

Role templates may use wildcards (`leads.*`), expanded by `PermissionCatalog::expand()`.

## Enforcement

- Every permission key is registered as a Gate ability (`AppServiceProvider`), so
  `$user->can('settings.view')` and `->middleware('can:settings.view')` work.
- `PermissionResolver` (scoped, per-request cache) grants nothing unless the **user**, the **tenant** and the
  **membership** are all active, and only within the current `TenantContext`. Platform admins get no implicit
  tenant permissions.
- Permissions for the current tenant are shared to React as `permissions` (for UI visibility only —
  frontend visibility is **not** authorization).
- Tenant-owned resources (Phase 3+) check the permission with `can:` route middleware and rely on the tenant
  scope for ownership. Route model binding runs after tenant resolution (middleware priority in
  `bootstrap/app.php`), so another tenant's id is a 404, not a 403.
- **Module gating:** `module:<code>[,<code>…]` middleware (`EnsureModuleEnabled`) returns 404 unless every
  listed module is enabled for the tenant (e.g. `module:leads`, `module:customers`). Navigation items are
  hidden for disabled modules.
- **CRM specifics (Phase 3):**
  - Converting a lead, marking it lost and logging activities need `leads.update`. Conversion creates the
    customer through the action, so no `customers.create` is required.
  - Setting an assignee when creating a lead needs `leads.assign`; otherwise the field is rejected.
  - Only members whose role grants `leads.update` (or `grants_all`) can be assigned leads.
  - Bulk actions check the permission of each action: assign → `leads.assign`, stage → `leads.update`,
    delete → `leads.delete`.
  - CRM settings: view with `settings.view`, change with `settings.update`.
- **Engine gating (Phase 4):** `engine:<code>` middleware (`EnsureEngineEnabled`) returns 404 unless the
  tenant has the engine: `engine:service` for services, `engine:booking` for resources, appointments and
  booking settings, `engine:commerce` for products, orders and order settings (Phase 7). Navigation hides
  disabled engines (the `tenant.engines` share).
- **Booking specifics (Phase 4):**
  - Status changes need `appointments.update`, except cancelling, which needs `appointments.cancel`. The
    route requires `appointments.view`; the controller checks the right ability for the requested status,
    including bulk actions.
  - Rescheduling and editing price or notes need `appointments.update`.
  - Resources: `resources.view` to see them, `resources.manage` for create, edit, delete and time off.
  - Services and categories: `services.view` / `services.manage`.
  - Money dashboard widgets (revenue today, service sales) also need `reports.view`.
  - The customer page includes appointments only for users with `appointments.view`.
  - "My schedule" is a calendar filter, not a restriction: anyone with `appointments.view` sees every
    resource (AW-022).
- **Automation specifics (Phase 5):**
  - The routes are behind `module:automation`, so a tenant without the module gets 404.
  - Permissions:
    - `automation.view`: the list, automation pages, run history and run detail, including message bodies.
    - `automation.create`: the builder and saving a new automation.
    - `automation.update`: edit, on/off, retry or cancel a run, send a failed message again.
    - `automation.delete`: delete.
  - Owner and Manager have all four; Staff has none by default.
  - Automations act as the system, not as the user who built them. So a user with `automation.create`
    but without `leads.assign` can still build an automation that assigns leads. Treat `automation.create`
    and `automation.update` as manager-level permissions (AW-033).
  - Condition values and action targets (stages, members, services) are checked against the tenant's own
    records when saved. A member chosen as a notification target who later leaves is skipped at run time.
  - Activities written by automations have no user; the timeline shows "Automation".
- **Website specifics (Phase 6):**
  - The editor routes are behind `module:website`.
  - `website.view`: the website page, design, business details and section edit pages (read-only).
  - `website.manage`: publish or unpublish, template and colours, business details, sections (add, edit,
    reorder, show or hide, remove), images, and the online booking settings (also behind `engine:booking`).
  - Owner and Manager have both; Receptionist and Staff have neither by default.
  - Section content is validated against the section's schema in `config/website.php`; unknown keys are
    dropped. Section and media ids are resolved inside the tenant scope, so another tenant's id is a 404.
- **Public site (Phase 6):** the tenant site has no login. Its protections are:
  - The site is resolved from the host. It returns 404 for unknown, disabled or suspended hosts, when the
    website module is off or when the site is unpublished (`site.live` middleware for the forms).
  - Unpublished sites can be previewed only through a signed `/preview` link for that tenant, valid for
    `website.preview_minutes`. Preview pages are marked `noindex`.
  - The enquiry form needs the leads module and a visible contact form. Online booking needs the booking
    engine, a visible booking section and online booking switched on.
  - Both forms have a honeypot field, server-side validation and rate limits keyed on the host and visitor IP
    (`website-enquiry`, `website-booking`, `website-slots`). There is no captcha (AW-038).
  - Visitors can only choose active services offered by an active resource, and times that pass the
    availability check again when booking. The price and duration come from the service, never from the
    request.
  - Pages only receive the public fields built by `WebsiteContent`: active services, and each resource's
    name, description, colour and services. Staff contact details and inactive records are never sent.
- **Commerce specifics (Phase 7):**
  - Every business-app route is behind `engine:commerce` (404 without it).
  - `products.view`: the product list. `products.create`: add a product. `products.update`: edit, adjust
    stock, product images and categories. `products.delete`: delete. Bulk actions check the ability of the
    requested action (delete → `products.delete`, otherwise `products.update`).
  - `orders.view`: the order list and order pages. `orders.create`: the new-order form, the customer search
    it uses and placing orders. `orders.update`: status changes (including cancel), recording and removing
    payments, and notes.
  - Order settings: view with `settings.view`, change with `settings.update`.
  - Default roles: Manager has `products.*` and `orders.*`; Receptionist has `orders.view` and
    `orders.create` only, so they can take an order but not confirm, cancel or take payment on it (AW-046);
    Accountant has `products.view` and `orders.view`; Sales Executive and Staff have none.
  - Money dashboard widgets (revenue today, product sales) also need `reports.view`. The low-stock widget
    needs `products.view`; the orders widgets need `orders.view`.
  - The customer page includes orders only for users with `orders.view` in a tenant with the engine.
  - Order lines, customers and payments are resolved inside the tenant scope: another tenant's product,
    customer, order or payment id is a 404 (routes) or a validation error (order lines, customer id).
    A payment id from another order of the same tenant is also a 404.
  - Prices, discounts and delivery fees are computed on the server. Staff can enter a discount and a
    delivery fee; website visitors cannot.
- **Online ordering (Phase 7):** `POST /cart/quote` and `POST /orders` on the tenant site run behind
  `site.live`, and only while the shop is open (commerce engine, online ordering on, a visible products
  section). They have a honeypot field on checkout, server-side validation and rate limits keyed on the host
  and visitor IP (`website-cart`, `website-order`). Website orders never accept a discount, a status, a
  payment or an in-store handover. The public products data has no stock field, only `in_stock` and
  `max_quantity` (the most that can be added: the stock for tracked products, capped at 999). The page does
  not show stock, but a visitor reading the page data can infer a low stock count.
- **Messaging specifics (Phase 8):**
  - Every business-app route is behind `module:messaging` (404 without it).
  - `conversations.view`: the inbox, conversation pages, the nav unread badge, and the **Chat** buttons on
    customer pages (also `customers.view`) and lead pages (also `module:leads` and `leads.view`).
  - `conversations.reply`: text replies (60/minute), templates (30/minute), close/reopen and opt-out.
  - `conversations.assign`: assigning. Only active members whose role grants `conversations.view` (or
    `grants_all`) can be chosen; the member list is only sent to users who can assign.
  - Everyone with `conversations.view` sees every conversation, not only their own (AW-052).
  - Default roles: Manager has all three; Receptionist and Sales Executive have `view` and `reply`; Staff
    and Accountant have none.
  - Settings → Messaging: view with `settings.view`; connect, disconnect, sync templates (10/minute) and
    preferences with `settings.update`. The webhook callback URL and verify token are only sent to users
    with `settings.update`. Tokens and app secrets are never sent to the browser.
  - Conversations, templates and members are resolved inside the tenant scope: another tenant's id is a
    404 (routes) or a validation error (template, assignee).
  - The Meta webhook routes have no user: they are authenticated by the URL key and the payload signature
    (see [webhook-security.md](webhook-security.md)).
- **AI specifics (Phase 9):**
  - Every AI route is behind `module:ai` (404 without it) and, except Settings → AI, `can:ai.use`.
    AI requests are limited to `ai.limits.per_minute` per user (`throttle:ai`).
  - Each helper also needs the permission of what it touches: reply drafts and dismissing drafts
    `conversations.reply`; conversation summary `conversations.view`; lead summary `leads.view`; filling
    and applying lead details `leads.update`; customer summary `customers.view`; website text
    `website.manage`; automation messages `automation.create` or `automation.update`.
  - The assistant's Ask tab needs `ai.assistant`. Its tools are offered per permission and module/engine,
    and never return phone numbers or e-mail addresses (see [ai-data.md](ai-data.md)).
  - Default roles: Manager has both; Receptionist and Sales Executive have `ai.use`; Staff and
    Accountant have none.
  - Settings → AI: view with `settings.view`, save with `settings.update`. The monthly allowance
    (`ai_quota`) is set only in Super Admin.
- **New permission groups for existing tenants:** `RbacSeeder` updates the templates, but tenant roles are
  copies. `TenantBackfillSeeder` calls `ProvisionTenantRoles::grantNewPermissionGroups()` for `services`,
  `resources`, `website`, `conversations` and `ai`:
  - it gives each tenant role its template's permissions in those groups;
  - it runs once per tenant and group (recorded in the `rbac_backfilled_groups` setting);
  - it skips roles that already hold any permission of the group.

  A tenant that later removes a permission does not get it back on the next deploy.
- Every permission-guarded action has allowed and forbidden tests (e.g. `/settings`: owner 200, staff 403).

## Super Admin

`is_platform_admin` + `platform.admin` middleware on the admin host. Platform-level roles/permissions for
admin staff are deferred until more admin features exist. Super Admin → AI usage (`/ai-usage`) and the
per-business AI allowance (`PUT /tenants/{tenant}/ai-limit`) are admin-only and audit-logged.
