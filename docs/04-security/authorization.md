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
  booking settings. Navigation hides disabled engines (the `tenant.engines` share).
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
- **New permission groups for existing tenants:** `RbacSeeder` updates the templates, but tenant roles are
  copies. `TenantBackfillSeeder` calls `ProvisionTenantRoles::grantNewPermissionGroups()` for `services` and
  `resources`:
  - it gives each tenant role its template's permissions in those groups;
  - it runs once per tenant and group (recorded in the `rbac_backfilled_groups` setting);
  - it skips roles that already hold any permission of the group.

  A tenant that later removes a permission does not get it back on the next deploy.
- Every permission-guarded action has allowed and forbidden tests (e.g. `/settings`: owner 200, staff 403).

## Super Admin

`is_platform_admin` + `platform.admin` middleware on the admin host. Platform-level roles/permissions for
admin staff are deferred until more admin features exist.
