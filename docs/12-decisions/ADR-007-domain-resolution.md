# ADR-007: Host-Based Domain Resolution

- **Status:** Accepted
- **Date:** 2026-09-27

## Context

Each tenant gets a public website at `{slug}.autowave.in` and later a custom domain. The platform itself uses
`autowave.in`, `app.`, `admin.` and `api.` subdomains.

## Decision

- Nginx forwards all hostnames (wildcard `*.autowave.in` + custom domains) to Laravel.
- A `DomainResolver` maps the `Host` header to the `domains` table (id, tenant_id, domain, type, is_primary,
  status, verified_at, ssl_status). A domain maps to exactly one tenant (unique index on `domain`).
- Platform hosts (`autowave.in`, `www`, `app`, `admin`, `api`) are reserved and routed to platform route groups.
- Custom domains require ownership verification before activation; Super Admin can add, verify, set primary,
  deactivate, remove and remap.
- The public website (customer-facing) is distinct from the business dashboard (`app.autowave.in`).
- Resolution results are cached (Redis) with invalidation on domain changes.

## Alternatives

- **Path-based tenancy** (`autowave.in/abc-salon`) — simpler SSL, weaker branding and SEO.
- **Per-tenant Nginx vhosts** — operational burden, does not scale.

## Consequences

- Needs wildcard DNS + wildcard TLS for `*.autowave.in`, and on-demand TLS for custom domains (see `docs/09-devops/ssl.md`).
- A custom domain never bypasses tenant authorization.
