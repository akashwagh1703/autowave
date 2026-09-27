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
