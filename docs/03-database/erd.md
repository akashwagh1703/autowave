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

A run's subject (`subject_type`, `subject_id`) is polymorphic: a lead, customer or appointment. It has no
FK, so deleting the subject does not delete the run's history; the run is cancelled at its next step.
Other arrows are composite FKs.
