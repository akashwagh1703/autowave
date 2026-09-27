# Entity Relationship Diagram

## Current (Phase 0)

Only Laravel default tables exist (`users`, `sessions`, `password_reset_tokens`, cache, jobs). No relationships
beyond `sessions.user_id → users.id` (nullable, not a foreign key in the default migration).

## Target platform core (Phase 1 design — not implemented)

```mermaid
erDiagram
    TENANTS ||--o{ TENANT_USERS : has
    USERS ||--o{ TENANT_USERS : "member of"
    TENANT_USERS ||--o{ USER_ROLES : has
    ROLES ||--o{ USER_ROLES : assigned
    ROLES ||--o{ ROLE_PERMISSIONS : grants
    PERMISSIONS ||--o{ ROLE_PERMISSIONS : in
    TENANTS ||--o{ ROLES : "defines (null = platform)"
    TENANTS ||--o{ DOMAINS : owns
    BUSINESS_TYPES ||--o{ TENANTS : "preset of"
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
```

Update this diagram when the Phase 1 migrations are written, reflecting the actual columns.
