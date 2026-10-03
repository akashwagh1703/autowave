# Implementation Status

_Last updated: 2026-10-07 (billing phase A)_

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
| Messaging (WhatsApp, Instagram, email) | ✅ | 5 / 8 | ADR-015, ADR-018; outbound pipeline and email in Phase 5. Phase 8: per-business Meta WhatsApp Cloud API and Instagram (manual connection, encrypted write-only credentials), signed webhooks stored and processed on the queue, shared inbox (assign, open/closed, unread, polling), unknown senders become leads, timeline history, delivery/read receipts, synced templates for automations and outside the 24-hour window, STOP/START opt-out, quiet hours, email sender name and reply-to; received files saved privately and WhatsApp replies with one file (Instagram replies text-only), no Embedded Signup or inbound email (AW-050–054) |
| AI | ✅ | 9 | ADR-019; OpenRouter behind `AIService`/`AIGateway` (fake provider for tests), inbox reply suggestions and automation drafts (never sent automatically), conversation/lead/customer summaries (cached), lead details from messages (fills empty fields, suggestions for the rest; automatic after messages), business assistant with read-only permission-aware tools, writing help (website, automation, marketing), `message.received` trigger and three AI actions, Settings → AI, per-business token metering with a monthly cap and Super Admin → AI usage; no redaction, streaming or saved chats (AW-055–058) |
| Additional verticals (Turf, Coaching, Cafe, Local Commerce) | ✅ | 10 | ADR-020; education engine (courses, batches, admissions from leads, fee instalments and payments, hourly fee reminders, attendance, demo classes, courses website section), food engine on commerce (food types and availability, tables, reservations with no double booking, website reservations, dine-in orders, kitchen screen), turf hourly/peak/weekend rates with prices on bookings and advances, Local Commerce rename, coupon codes and widgets; demo tenants ABC Coaching, ABC Cafe, ABC Store (AW-059–064) |
| Super Admin | 🚧 | 1+ | Login, dashboard, tenant list/suspend done; more with each phase |
| Billing / plans | ✅ | Billing A | [billing.md](../05-features/billing.md); 14-day trial, Starter/Growth/Business monthly or yearly, manual UPI/bank payments approved by Super Admin, gapless invoices, GST and online-payment switches (off), plan limits, optional enforcement (read-only, then locked), hourly `billing:sweep` reminders; Razorpay in Phase B, PDF invoices and coupons in Phase C |
| Horizon | ⏳ | production setup | See AW-001 |
| Production deployment | ✅ | ops | Live at autowave.co.in since 2026-09-29 on a shared DigitalOcean droplet: atomic releases with `scripts/deploy.sh` (deploy, rollback), wildcard TLS, systemd worker, cron scheduler. Backups, firewall and a bigger server pending (AW-065–067) |
