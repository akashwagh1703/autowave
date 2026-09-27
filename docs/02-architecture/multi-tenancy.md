# Multi-Tenancy

> **Status: designed, not implemented.** Implementation is Phase 1. Update this document when it lands.

## Model

Shared database, shared schema, row-level isolation by `tenant_id` (ADR-002).

```text
tenants ──< tenant_users >── users
   │
   ├──< domains               (hostname → tenant, exactly one tenant per domain)
   ├──< tenant_engines / tenant_modules / tenant_settings
   └──< every tenant-owned table (customers, leads, appointments, ...) via tenant_id
```

A user can belong to multiple tenants through `tenant_users` (status, joined_at, role information).

## Tenant resolution

1. **Public tenant websites** (`{slug}.autowave.in`, custom domains): `DomainResolver` maps the `Host`
   header to a row in `domains` (active, verified) → tenant.
2. **Business app** (`app.autowave.in`): tenant comes from the authenticated user's selected, active
   membership (stored in session). Switching tenants re-validates membership.
3. **Super Admin** (`admin.autowave.in`): no tenant context by default; platform admins may inspect a tenant explicitly.
4. **Queued jobs**: every tenant-scoped job carries `tenant_id` and re-establishes `TenantContext` before running.

Reserved hostnames (`www`, `app`, `admin`, `api`) never resolve to a tenant website.

## Enforcement layers

| Layer | Mechanism |
|---|---|
| Middleware | `ResolveTenant` sets `TenantContext`; aborts 404 for unknown/inactive domains |
| Model | `BelongsToTenant` trait: global scope `where tenant_id = current`, auto-fills `tenant_id` on create |
| Routing | Route model binding resolves through the scope → cross-tenant IDs return 404 |
| Authorization | Policies check membership + permission within the current tenant |
| Storage | Paths prefixed `tenant/{tenant_id}/`; private files via authorized routes |
| Cache | Keys prefixed with tenant ID |
| Tests | Mandatory cross-tenant tests for every tenant-owned resource |

Code must **never** accept `tenant_id` from request input when it can be derived from context.
Bypassing the global scope (`withoutGlobalScope`) is only allowed in Super Admin / system code and must be
reviewed.

## Mandatory isolation tests

- Tenant A cannot read Tenant B customer
- Tenant A cannot update Tenant B lead
- Tenant A cannot access Tenant B appointment
- Tenant A cannot access Tenant B files
- Tenant A cannot use Tenant B domain

These are release-blocking.
