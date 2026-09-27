# Implementation Status

_Last updated: 2026-09-27_

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
| One-click onboarding | ⏳ | 2 | First milestone |
| Customers / Leads / CRM | ⏳ | 3 | |
| Services + Booking | ⏳ | 4 | |
| Automation engine | ⏳ | 5 | |
| Website engine | ⏳ | 6 | |
| Commerce | ⏳ | 7 | |
| Messaging (WhatsApp, Instagram, email) | ⏳ | 8 | |
| AI | ⏳ | 9 | |
| Additional verticals (Turf, Coaching, Cafe, Local Commerce) | ⏳ | 10 | |
| Super Admin | 🚧 | 1+ | Login, dashboard, tenant list/suspend done; more with each phase |
| Billing / plans | ⏳ | later | Architecture only until needed |
| Horizon | ⏳ | production setup | See AW-001 |
