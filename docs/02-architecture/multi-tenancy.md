# Multi-Tenancy

> **Status: implemented in Phase 1.** Decisions: ADR-002, ADR-007, ADR-011.

## Model

Shared database, shared schema, row-level isolation by `tenant_id` (ADR-002).

```text
tenants ──< tenant_users >── users
   │            └──< user_roles >── roles ──< role_permissions >── permissions
   ├──< domains               (hostname → tenant, exactly one tenant per domain)
   ├──< tenant_engines / tenant_modules / tenant_settings
   └──< every tenant-owned table (customers, leads, appointments, ...) via tenant_id
```

A user can belong to multiple tenants through `tenant_users` (status `active|invited|suspended`, `joined_at`).
Roles hang off the membership, so a user can be Staff in one business and Manager in another.

## Hosts

| Host (local) | Host (production) | Serves | Tenant from |
|---|---|---|---|
| `autowave.localhost` | `autowave.in` | Marketing (`routes/web.php`) | — |
| `app.autowave.localhost` | `app.autowave.in` | Business app + auth (`routes/app.php`, Fortify) | Active membership |
| `admin.autowave.localhost` | `admin.autowave.in` | Super Admin (`routes/admin.php`) | None (platform) |
| `{slug}.autowave.localhost`, custom domains | `{slug}.autowave.in`, custom domains | Public website (`routes/tenant-site.php`) | `domains` table |

Hosts come from `config/autowave.php` (`AUTOWAVE_*` env vars). `www.{root}` redirects to marketing.
Reserved subdomains (`config('autowave.reserved_subdomains')`) can never be tenant slugs.

## Tenant resolution

1. **Public tenant websites** — `ResolveTenantFromDomain` (`tenant.site`) → `DomainResolver`:
   normalises the host (lowercase, no port, no trailing dot), ignores platform hosts, and returns the
   tenant only for an **active** domain of an **active** tenant. Lookups (including misses) are cached for
   `AUTOWAVE_DOMAIN_CACHE_TTL` seconds; `Domain` model events and tenant status changes flush entries.
   Unknown/disabled/suspended → 404.
2. **Business app** — `ResolveTenantFromMembership` (`tenant.member`): the session key
   `current_tenant_id` is a preference, re-validated each request against an active membership in an active
   tenant. No membership → redirect to `/workspaces`. Switching (`POST /workspaces/{tenant}/switch`)
   404s for non-members.
3. **Super Admin** — no tenant context. Admin code that needs tenant data uses `TenantContext::run()`.
4. **Queued jobs** (from Phase 3) — carry `tenant_id` and re-establish context via job middleware.
   `TenantContext` is a scoped singleton, so workers never leak state between jobs.

## Enforcement layers

| Layer | Mechanism |
|---|---|
| Routing | Host-bound route groups; tenant-site group registered last |
| Middleware | `tenant.site`, `tenant.member`, `active`, `platform.admin` |
| Context | `App\Domain\Tenant\Support\TenantContext` (scoped): `set`, `run`, `tenant`, `hasModule`, `setting` |
| Model | `BelongsToTenant`: fail-closed `TenantScope`; auto-fill `tenant_id`; `MissingTenantContext` / `CrossTenantWrite` guards |
| Database | Composite FKs keep `user_roles` within one tenant; unique `domains.domain` |
| Authorization | Gates per permission key, resolved in the current tenant only (`PermissionResolver`) |
| Storage / Cache | Paths `tenant/{tenant_id}/`; tenant-prefixed cache keys (as features add them) |
| Tests | `tests/Feature/Tenancy/*` and `Rbac/PermissionTest` |

Tenancy infrastructure tables (`tenant_users`, `tenant_modules`, `tenant_engines`, `domains`) are not globally
scoped (platform code must query across tenants) and are always filtered by explicit `tenant_id`.
`tenant_settings`, `roles` and every future business table use `BelongsToTenant`.

Code must **never** accept `tenant_id` from request input when it can be derived from context.
`Model::withoutTenantScope()` is only for Super Admin / system code and must be reviewed.

## Creating a tenant

`App\Domain\Tenant\Actions\CreateTenant::handle($owner, $name, $businessType, $options)` — one transaction:

1. Tenant row (slug generated/validated by `TenantSlug`, business type version pinned).
2. Owner membership.
3. Inside `TenantContext::run()`: copy template roles, assign Owner, enable the business type's modules plus
   modules required by its engines (dependency order), enable engines, write default settings
   (`branding` + business type configuration), assign `{slug}.{root_domain}` as primary active domain.
4. `TenantCreated` event after commit.

## Isolation tests (release-blocking)

Implemented now: fail-closed reads, scoped reads, auto-fill, cross-tenant create/update, context restore,
template roles invisible, domain A vs domain B, disabled/suspended/unknown hosts, platform hosts never
resolve, membership switching/tampering/suspension, per-tenant permissions, DB-level cross-tenant role FK.

Each new tenant-owned resource adds: Tenant A cannot read/update/delete Tenant B's record (including via
route model binding), and cannot reach it through Tenant B's domain.
