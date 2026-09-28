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
- **Phase 8 — Messaging:** per-business WhatsApp (Meta Cloud API) and Instagram, signed webhooks, a shared
  inbox with assignment and unread counts, unknown senders as leads, delivery receipts, synced templates,
  opt-out and quiet hours (ADR-018; AW-025 resolved).
- **Phase 9 — AI:** OpenRouter behind one service with per-business metering and a monthly cap; reply
  suggestions and drafts (a person always sends), summaries, lead details from messages, the business
  assistant, writing help, the "message received" trigger and AI automation actions (ADR-019; AW-053
  partly resolved).
- **Phase 10 — Additional verticals:** Coaching (education engine: courses, batches, admissions from
  enquiries, fees with instalments and reminders, attendance, demo classes), Cafe (food engine on
  commerce: menu marks, tables, reservations, dine-in, kitchen screen), Turf rates and advances, Local
  Commerce (coupons, widgets) (ADR-020; AW-044 coupons resolved).

## Next

The master prompt's phases are complete. Candidates, to be chosen with the product owner: Clinic,
Fitness, Car Service and Home Services presets; the vertical gaps AW-059 → AW-064; billing.

## Future

- Billing (plans, subscriptions, usage), custom domains with verification/SSL, Horizon, PWA.
