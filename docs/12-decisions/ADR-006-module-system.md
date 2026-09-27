# ADR-006: Module Registry and Dependencies

- **Status:** Accepted
- **Date:** 2026-09-27

## Context

Capabilities such as CRM, Leads, Messaging, Website, Automation and Payments are shared by many business types
and must be switchable per tenant without code changes.

## Decision

- A **module registry** (`modules`: id, code, name, description, type, version, status, configuration).
- Per-tenant activation in `tenant_modules` (enabled, version, configuration jsonb).
- Business-type defaults in `business_type_modules`.
- Dependencies in `module_dependencies`; enabling a module requires its dependencies (e.g. Booking → Customers,
  Services, Staff; Commerce → Customers, Products, Payments). Incompatible activation is rejected in a transaction.
- One module per capability (`Lead`), configured per tenant — never `SalonLeadModule`.
- Feature flags (`feature_flags`) cover finer-grained toggles (e.g. `AI_ASSISTANT`, `CUSTOM_DOMAIN`), globally or per tenant/plan.

## Alternatives

- **Composer packages per module** — strong boundaries but heavy release overhead for a single product.
- **Static config file** — no per-tenant control or versioning.

## Consequences

- Menus, dashboard widgets, routes and policies must check module enablement.
- Module versions let us evolve modules without breaking existing tenants.
