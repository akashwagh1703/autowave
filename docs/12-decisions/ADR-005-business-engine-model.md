# ADR-005: Business Type and Engine Model

- **Status:** Accepted
- **Date:** 2026-09-27

## Context

Salons, turfs, coaching centres, cafes and clinics need different capabilities, but separate apps or
per-vertical code would multiply maintenance.

## Decision

- **Engines** are major generic capabilities: Service, Booking, Commerce, Education, Food (future: Field Service,
  Property, Event, Membership). Engines use generic models — e.g. Booking is built around a generic *resource*
  (staff, turf, doctor, table).
- **Business Types** are versioned presets that recommend engines, modules, dashboard widgets, website sections,
  default automations and settings. Tables: `business_types`, `business_type_engines`, `business_type_modules`,
  `tenant_engines`.
- Tenants store the business-type version they were created with; new versions never auto-migrate existing tenants.
- Sidebar and dashboard are resolved from configuration, not per-vertical components.

## Alternatives

- **One app per vertical** — fast initially, unmaintainable at scale.
- **Hard-coded `if business_type == ...` branches** — spreads vertical logic everywhere.

## Consequences

- New verticals are mostly configuration plus occasional engine enhancements.
- Engines must be designed generically from the start (first: Service + Booking + Commerce for Beauty & Salon).
