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
appointments.view  appointments.create  appointments.update  appointments.cancel
products.view  products.create  products.update  products.delete
orders.view  orders.create  orders.update
automation.view  automation.create  automation.update  automation.delete
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
- Policies for tenant-owned resources (Phase 3+) must check the permission and rely on the tenant scope for
  ownership.
- Every permission-guarded action has allowed and forbidden tests (e.g. `/settings`: owner 200, staff 403).

## Super Admin

`is_platform_admin` + `platform.admin` middleware on the admin host. Platform-level roles/permissions for
admin staff are deferred until more admin features exist.
