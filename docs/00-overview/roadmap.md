# Roadmap

Do not implement roadmap items unless explicitly requested. Work phase-by-phase; after every phase:
Build → Test → Review → Document → Commit → Update status.

## Current

**Phase 1 — Platform Foundation** (awaiting approval to start)

- Authentication (login, logout, registration, email verification, password reset, remember me, account status)
- Tenants, `TenantContext`, tenant resolution middleware, tenant-scoped models
- Tenant membership (`tenant_users`)
- RBAC (roles, permissions, role/user assignment, policies)
- Business types, engines, module registry, module dependencies
- Domains and domain resolution
- Cross-tenant isolation test harness

## Next

**Phase 2 — Onboarding** → _Milestone 1: a user can create a Beauty Salon business and receive a working isolated tenant workspace._

- Signup → choose business type → details → capabilities → branding → website template → workspace → dashboard
- Automatic tenant, owner, role, business type, engines, modules, settings, website config, default domain, default automations, dashboard

**Phase 3 — CRM**: customers, leads, lead stages, lead sources, assignment, activities.

**Phase 4 — Service + Booking** → _Milestone 2: create service, staff, customer, lead, appointment._

## Later

- **Phase 5 — Automation** → _Milestone 3: lead created → follow-up job → Redis → worker → message action → execution log._
- **Phase 6 — Website** → _Milestone 4: `abc-salon.autowave.in` shows business info, services, products, booking, enquiry form, WhatsApp CTA._
- **Phase 7 — Commerce**: products, categories, inventory, cart, orders.

## Future

- **Phase 8 — Messaging**: provider abstraction, WhatsApp, Instagram, email.
- **Phase 9 — AI**: lead extraction, reply generation, summaries, assistant.
- **Phase 10 — Additional verticals**: Turf, Coaching, Cafe, Local Commerce, then Clinic, Fitness, Car Service, Home Services.
- Billing (plans, subscriptions, usage), custom domains with verification/SSL, Horizon, PWA.
