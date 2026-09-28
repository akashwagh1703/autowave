# Implementation Status

_Last updated: 2026-10-02_

Legend: ✅ done · 🚧 in progress · ⏳ not started

| Area | Status | Phase | Notes |
|---|---|---|---|
| Project foundation (Laravel, Inertia, React, Tailwind, MUI, PostgreSQL, Redis, CI, docs) | ✅ | 0 | |
| Authentication (login, register, verification, reset) | ✅ | 1 | Fortify on app host; separate admin login (ADR-010) |
| Multi-tenancy (tenants, TenantContext, resolution middleware) | ✅ | 1 | Fail-closed scope (ADR-011) |
| Tenant membership (`tenant_users`) | ✅ | 1 | Switching; invitations not yet |
| RBAC (roles, permissions, policies) | ✅ | 1 | Gates per permission; role editor not yet |
| Business types / engines / module registry | ✅ | 1 | Config-driven catalogue with dependencies |
| Domains + domain resolution | ✅ | 1 | Default subdomain; custom domain UI later |
| Audit log | ✅ | 1 | Admin + tenant status actions |
| Feature flags / custom fields | ⏳ | later | AW-009 |
| One-click onboarding | ✅ | 2 | Wizard, dependency-aware modules, per-user cap; default automations added in Phase 5 |
| Website foundation (templates, config, sections, public render) | ✅ | 2 | ADR-012; editor added in Phase 6 |
| Customers / Leads / CRM | ✅ | 3 | ADR-013; configurable stages/sources, conversion, timeline, auto-assign; kanban/own-leads/campaigns later (AW-014–016) |
| Services + Booking | ✅ | 4 | ADR-014; staff-side booking, day calendar, lifecycle, exclusion constraint; online booking added in Phase 6 (AW-021–024, AW-037) |
| Automation engine | ✅ | 5 | ADR-015; lead, customer and appointment triggers (order triggers added in Phase 7), conditions, waits, 7 actions, run history, retry/cancel, default templates; linear flows (AW-025–033) |
| Website engine (editor, SEO, gallery, booking widget) | ✅ | 6 | ADR-016; section editor, design, details, media, publish and preview, enquiry form → leads, online booking, WhatsApp button, SEO meta; products section and cart added in Phase 7; packages and reviews wait for their phases (AW-034–040) |
| Commerce | ✅ | 7 | ADR-017; products with categories, images and optional stock tracking, stock ledger and low-stock alerts, staff orders (in store, pickup, delivery), lifecycle with restock on cancel, manual payments, order settings, website products section with cart and checkout, order automations and dashboard widgets; no gateway, variants, refunds or taxes (AW-041–049) |
| Messaging (WhatsApp, Instagram, email) | ✅ | 5 / 8 | ADR-015, ADR-018; outbound pipeline and email in Phase 5. Phase 8: per-business Meta WhatsApp Cloud API and Instagram (manual connection, encrypted write-only credentials), signed webhooks stored and processed on the queue, shared inbox (assign, open/closed, unread, polling), unknown senders become leads, timeline history, delivery/read receipts, synced templates for automations and outside the 24-hour window, STOP/START opt-out, quiet hours, email sender name and reply-to; media placeholders, no Embedded Signup or inbound email (AW-050–054) |
| AI | ⏳ | 9 | |
| Additional verticals (Turf, Coaching, Cafe, Local Commerce) | ⏳ | 10 | |
| Super Admin | 🚧 | 1+ | Login, dashboard, tenant list/suspend done; more with each phase |
| Billing / plans | ⏳ | later | Architecture only until needed |
| Horizon | ⏳ | production setup | See AW-001 |
