# Authorization (RBAC)

> Status: **not implemented** (Phase 1).

## Model

`users` ↔ `tenant_users` (membership) ↔ `user_roles` ↔ `roles` ↔ `role_permissions` ↔ `permissions`.

- Roles are tenant-scoped; `roles.tenant_id = null` marks platform template roles copied into new tenants.
- Default business roles: Owner, Manager, Receptionist, Sales Executive, Staff, Accountant.
- Super Admin manages platform roles and the permission catalogue.

## Permission catalogue (initial)

```text
customers.view  customers.create  customers.update  customers.delete
leads.view  leads.create  leads.update  leads.assign  leads.delete
appointments.view  appointments.create  appointments.update  appointments.cancel
products.view  products.create  products.update  products.delete
orders.view  orders.create  orders.update
automation.view  automation.create  automation.update  automation.delete
reports.view
settings.view  settings.update
```

Keep this list in sync with the permissions seeder.

## Enforcement

- Every controller action authorizes via a Policy or Gate; policies check (a) current tenant membership is active,
  (b) the resource belongs to the current tenant, (c) the user's roles grant the permission.
- Permission flags for the UI (`can`) are computed server-side and shared via Inertia props.
- Frontend visibility is **not** authorization.
- Every permission-guarded action has allowed and forbidden tests.
