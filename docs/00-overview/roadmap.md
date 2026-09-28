# Roadmap

Do not implement roadmap items unless explicitly requested. Work phase-by-phase; after every phase:
Build → Test → Review → Document → Commit → Update status.

## Done

What exists is described in [current-state.md](current-state.md).

- **Phase 0 — Foundation**
- **Phase 1 — Platform foundation:** authentication, tenancy, RBAC, catalogue, domains, isolation harness.
- **Phase 2 — Onboarding** → _Milestone 1 reached._ Default automations were added in Phase 5.
- **Phase 3 — CRM:** customers, leads, stages, sources, assignment, activities.
- **Phase 4 — Service + Booking** → _Milestone 2 reached: create service, staff, customer, lead, appointment._
- **Phase 5 — Automation** → _Milestone 3 reached: lead created → follow-up job → Redis → worker → message
  action → execution log._ Triggers from the CRM and booking events, conditions, waits, 7 actions, run
  history, retry and cancel, default automations, and the outbound messaging pipeline (WhatsApp simulated).
- **Phase 6 — Website** → _Milestone 4 reached except products: `abc-salon.autowave.in` shows business
  info, services, booking, an enquiry form and a WhatsApp button._ Section editor, design, business details,
  images, publish and preview, SEO meta. The Products section is built and stays hidden until Phase 7
  creates products (AW-034).
- **Phase 7 — Commerce** → _Milestone 4 complete: the website's Products section shows the catalogue and
  takes orders._ Products, categories, stock tracking and ledger, staff and website orders (pickup and
  delivery), manual payments, order settings, order automations and dashboard widgets.

## Next

**Phase 8 — Messaging**: real WhatsApp provider, consent and quiet hours (AW-025), inbox, Instagram. The
provider abstraction and outbound pipeline exist since Phase 5. Starts only after Phase 7 is approved.

## Future

- **Phase 9 — AI**: lead extraction, reply generation, summaries, assistant.
- **Phase 10 — Additional verticals**: Turf, Coaching, Cafe, Local Commerce, then Clinic, Fitness, Car Service, Home Services.
- Billing (plans, subscriptions, usage), custom domains with verification/SSL, Horizon, PWA.
