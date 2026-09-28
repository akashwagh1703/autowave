# Roadmap

Do not implement roadmap items unless explicitly requested. Work phase-by-phase; after every phase:
Build → Test → Review → Document → Commit → Update status.

## Done

What exists is described in [current-state.md](current-state.md).

- **Phase 0 — Foundation**
- **Phase 1 — Platform foundation:** authentication, tenancy, RBAC, catalogue, domains, isolation harness.
- **Phase 2 — Onboarding** → _Milestone 1 reached._ Default automations are deferred (AW-011).
- **Phase 3 — CRM:** customers, leads, stages, sources, assignment, activities.
- **Phase 4 — Service + Booking** → _Milestone 2 reached: create service, staff, customer, lead, appointment._

## Next

**Phase 5 — Automation** → _Milestone 3: lead created → follow-up job → Redis → worker → message action → execution log._
It will also consume the CRM and booking events (AW-017, AW-024) and add default automations (AW-011).

## Later

- **Phase 6 — Website** → _Milestone 4: `abc-salon.autowave.in` shows business info, services, products, booking, enquiry form, WhatsApp CTA._
- **Phase 7 — Commerce**: products, categories, inventory, cart, orders.

## Future

- **Phase 8 — Messaging**: provider abstraction, WhatsApp, Instagram, email.
- **Phase 9 — AI**: lead extraction, reply generation, summaries, assistant.
- **Phase 10 — Additional verticals**: Turf, Coaching, Cafe, Local Commerce, then Clinic, Fitness, Car Service, Home Services.
- Billing (plans, subscriptions, usage), custom domains with verification/SSL, Horizon, PWA.
