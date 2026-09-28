# Entity Relationship Diagram

## Platform core (Phases 1–2 — implemented)

Column details: [schema.md](schema.md).

```mermaid
erDiagram
    USERS ||--o{ TENANT_USERS : "member of"
    TENANTS ||--o{ TENANT_USERS : has
    TENANT_USERS ||--o{ USER_ROLES : has
    ROLES ||--o{ USER_ROLES : assigned
    ROLES ||--o{ ROLE_PERMISSIONS : grants
    PERMISSIONS ||--o{ ROLE_PERMISSIONS : in
    TENANTS ||--o{ ROLES : "defines (null = template)"
    TENANTS ||--o{ DOMAINS : owns
    BUSINESS_TYPES ||--o{ TENANTS : "preset of (version pinned)"
    BUSINESS_TYPES ||--o{ BUSINESS_TYPE_ENGINES : recommends
    BUSINESS_TYPES ||--o{ BUSINESS_TYPE_MODULES : recommends
    ENGINES ||--o{ BUSINESS_TYPE_ENGINES : ""
    MODULES ||--o{ BUSINESS_TYPE_MODULES : ""
    MODULES ||--o{ MODULE_DEPENDENCIES : "depends on"
    TENANTS ||--o{ TENANT_ENGINES : enables
    TENANTS ||--o{ TENANT_MODULES : enables
    ENGINES ||--o{ TENANT_ENGINES : ""
    MODULES ||--o{ TENANT_MODULES : ""
    TENANTS ||--o{ TENANT_SETTINGS : has
    TENANTS ||--o{ AUDIT_LOGS : "about (nullable)"
    USERS ||--o{ AUDIT_LOGS : "actor (nullable)"
    USERS ||--o{ TENANTS : "created (nullable)"
    TENANTS ||--o| WEBSITE_CONFIGS : "has one"
    WEBSITE_TEMPLATES ||--o{ WEBSITE_CONFIGS : "styles"
    TENANTS ||--o{ WEBSITE_SECTIONS : "has (ordered)"
```

Phase 2 added `tenants.created_by_user_id` and the website tables (ADR-012).

`USER_ROLES` carries `tenant_id` and composite FKs to both `TENANT_USERS (id, tenant_id)` and
`ROLES (id, tenant_id)`, so a membership and its roles always share a tenant (ADR-011).

Laravel default tables (`sessions`, `password_reset_tokens`, cache, jobs) are omitted.

## CRM (Phase 3 — implemented, ADR-013)

```mermaid
erDiagram
    TENANTS ||--o{ CUSTOMERS : owns
    TENANTS ||--o{ LEAD_STAGES : "configures (ordered)"
    TENANTS ||--o{ LEAD_SOURCES : configures
    TENANTS ||--o{ LEADS : owns
    LEAD_STAGES ||--o{ LEADS : "current stage"
    LEAD_SOURCES ||--o{ LEADS : "came from (nullable)"
    CUSTOMERS ||--o{ LEADS : "linked / converted to (nullable)"
    TENANT_USERS ||--o{ LEADS : "assigned (nullable)"
    LEADS ||--o{ ACTIVITIES : timeline
    CUSTOMERS ||--o{ ACTIVITIES : timeline
    USERS ||--o{ ACTIVITIES : "actor (nullable)"
```

Every arrow between tenant-owned tables is a composite FK `(x_id, tenant_id) → x(id, tenant_id)`. An
activity can belong to a lead, a customer or both (converted leads' history is copied onto the customer).

## Services and booking (Phase 4 — implemented, ADR-014)

```mermaid
erDiagram
    TENANTS ||--o{ SERVICE_CATEGORIES : configures
    SERVICE_CATEGORIES ||--o{ SERVICES : "groups (nullable)"
    TENANTS ||--o{ BOOKING_RESOURCES : owns
    TENANT_USERS ||--o| BOOKING_RESOURCES : "own calendar (nullable)"
    BOOKING_RESOURCES ||--o{ BOOKING_RESOURCE_SERVICE : offers
    SERVICES ||--o{ BOOKING_RESOURCE_SERVICE : "offered by"
    BOOKING_RESOURCES ||--o{ RESOURCE_WORKING_HOURS : "weekly hours"
    BOOKING_RESOURCES ||--o{ RESOURCE_TIME_OFF : "time off"
    BOOKING_RESOURCES ||--o{ APPOINTMENTS : "booked on"
    CUSTOMERS ||--o{ APPOINTMENTS : books
    SERVICES ||--o{ APPOINTMENTS : "for (nullable)"
    APPOINTMENTS ||--o{ ACTIVITIES : timeline
```

All arrows are composite FKs. An appointment's activities also carry its `customer_id`, so they show on the
customer timeline.

## Automation and outbound messages (Phase 5 — implemented, ADR-015)

```mermaid
erDiagram
    TENANTS ||--o{ AUTOMATIONS : owns
    AUTOMATIONS ||--o{ AUTOMATION_NODES : "ordered steps"
    AUTOMATIONS ||--o{ AUTOMATION_RUNS : "runs (steps snapshot)"
    AUTOMATION_RUNS ||--o{ AUTOMATION_JOBS : "one per step"
    AUTOMATION_RUNS ||--o{ AUTOMATION_LOGS : log
    AUTOMATION_RUNS ||--o{ OUTBOUND_MESSAGES : "sent by (nullable)"
    LEADS ||--o{ OUTBOUND_MESSAGES : "to (nullable)"
    CUSTOMERS ||--o{ OUTBOUND_MESSAGES : "to (nullable)"
    TENANT_USERS ||--o{ OUTBOUND_MESSAGES : "team notification (nullable)"
```

A run's subject (`subject_type`, `subject_id`) is polymorphic: a lead, customer, appointment or order
(Phase 7). It has no
FK, so deleting the subject does not delete the run's history; the run is cancelled at its next step.
Other arrows are composite FKs.

## Website builder and media (Phase 6 — implemented, ADR-016)

```mermaid
erDiagram
    TENANTS ||--|| WEBSITE_CONFIGS : "one website"
    WEBSITE_TEMPLATES ||--o{ WEBSITE_CONFIGS : "look and feel"
    TENANTS ||--o{ WEBSITE_SECTIONS : "one per type"
    TENANTS ||--o{ MEDIA : "logo, hero, gallery"
    USERS ||--o{ MEDIA : "uploaded by (nullable)"
```

Sections don't reference business records. The public page reads services, booking resources, working
hours, media and the `branding` / `business_profile` settings at request time. Online bookings are ordinary
`appointments` rows with `source = website`, and enquiries are ordinary `leads` and `activities`.

## Commerce (Phase 7 — implemented, ADR-017)

```mermaid
erDiagram
    TENANTS ||--o{ PRODUCT_CATEGORIES : configures
    PRODUCT_CATEGORIES ||--o{ PRODUCTS : "groups (nullable)"
    MEDIA ||--o| PRODUCTS : "image (nullable)"
    PRODUCTS ||--o{ STOCK_MOVEMENTS : "stock ledger"
    CUSTOMERS ||--o{ ORDERS : places
    ORDERS ||--o{ ORDER_ITEMS : contains
    PRODUCTS ||--o{ ORDER_ITEMS : "sold as (copy of name, price)"
    ORDERS ||--o{ ORDER_PAYMENTS : "paid by"
    ORDERS ||--o{ STOCK_MOVEMENTS : "sale / cancellation (nullable)"
    ORDERS ||--o{ ACTIVITIES : timeline
```

All arrows are composite FKs except `products.image_media_id`, which references `media.id` alone so it can
be set to null when the image is deleted (PG11). An order's activities also carry its `customer_id`, so they
show on the customer timeline. Website orders are ordinary `orders` rows with `source = website`.

## Messaging (Phase 8 — implemented, ADR-018)

```mermaid
erDiagram
    TENANTS ||--o{ MESSAGING_CHANNELS : "one per channel"
    MESSAGING_CHANNELS ||--o{ MESSAGING_WEBHOOK_CALLS : "received (pruned)"
    TENANTS ||--o{ CONVERSATIONS : owns
    CUSTOMERS ||--o{ CONVERSATIONS : "linked (nullable)"
    LEADS ||--o{ CONVERSATIONS : "linked (nullable)"
    TENANT_USERS ||--o{ CONVERSATIONS : "assigned (nullable)"
    CONVERSATIONS ||--o{ CONVERSATION_MESSAGES : thread
    CONVERSATIONS ||--o{ OUTBOUND_MESSAGES : "replies and automation messages"
    OUTBOUND_MESSAGES ||--o| CONVERSATION_MESSAGES : "outbound row"
    USERS ||--o{ OUTBOUND_MESSAGES : "sent by (nullable)"
    TENANTS ||--o{ MESSAGE_TEMPLATES : "synced from Meta"
```

All arrows between tenant-owned tables are composite FKs. A conversation is keyed by
(`tenant`, `channel`, `contact_handle`), so the same person messaging two businesses has two unrelated
conversations. Templates are referenced by name and language (in `outbound_messages.template` and
automation configs), not by FK, because a sync may replace them.

## AI (Phase 9 — implemented, ADR-019)

```mermaid
erDiagram
    TENANTS ||--o{ AI_USAGE : "one row per provider call"
    USERS ||--o{ AI_USAGE : "caller (nullable)"
    TENANTS ||--o{ AI_RESULTS : owns
    USERS ||--o{ AI_RESULTS : "created by (nullable)"
    CONVERSATIONS ||--o{ AI_RESULTS : "summary, reply draft (polymorphic)"
    LEADS ||--o{ AI_RESULTS : "summary, extraction (polymorphic)"
    CUSTOMERS ||--o{ AI_RESULTS : "summary (polymorphic)"
```

`ai_results` points at its record by `subject_type` + `subject_id` (no FK), like automation runs; a result
for a deleted record is simply never shown. `(tenant_id, feature, key)` is unique, so the same input or
automation step never produces a second result.

## Additional verticals (Phase 10 — implemented, ADR-020)

```mermaid
erDiagram
    COURSES ||--o{ BATCHES : "taught in"
    TENANT_USERS ||--o{ BATCHES : "teacher (nullable)"
    CUSTOMERS ||--o{ ENROLMENTS : "student"
    BATCHES ||--o{ ENROLMENTS : has
    LEADS ||--o{ ENROLMENTS : "admitted from (nullable)"
    ENROLMENTS ||--o{ FEE_INSTALMENTS : "fee plan"
    ENROLMENTS ||--o{ FEE_PAYMENTS : "paid by"
    BATCHES ||--o{ CLASS_SESSIONS : "class on a date"
    CLASS_SESSIONS ||--o{ ATTENDANCE_RECORDS : marks
    ENROLMENTS ||--o{ ATTENDANCE_RECORDS : "attended by"
    LEADS ||--o{ DEMO_CLASSES : "trial"
    COURSES ||--o{ DEMO_CLASSES : "for (nullable)"
    DINING_TABLES ||--o{ RESERVATIONS : "assigned (nullable)"
    CUSTOMERS ||--o{ RESERVATIONS : guest
    DINING_TABLES ||--o{ ORDERS : "dine-in (nullable)"
    COUPONS ||--o{ ORDERS : "applied (nullable)"
    APPOINTMENTS ||--o{ APPOINTMENT_PAYMENTS : "advance / payment"
```

All arrows are composite FKs. Students are customers and menu items are products, so the customer
timeline, messaging and commerce reports cover the new verticals without new link tables. Orders keep
`coupon_code` as typed, so history survives a coupon being edited or deleted.
