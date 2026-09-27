# Architecture Overview

## Style

**Modular monolith** (ADR-001): one Laravel 13 application, one Inertia/React frontend, one PostgreSQL
database, one Redis. Domains are separated in code, not in deployables.

```text
Internet → Nginx → PHP-FPM → Laravel ──┬── PostgreSQL (all tenants, tenant_id scoped)
                                       ├── Redis (cache, queues)
                                       ├── Queue workers (Supervisor)
                                       └── Scheduler (one cron: schedule:run)
```

## Code layout

Current (Phase 0) and target structure. Items marked _(planned)_ do not exist yet.

```text
app/
├── Console/Commands/        HealthCheckCommand (autowave:health)
├── Domain/                  (planned) one folder per domain
│   ├── Auth/  Tenant/  User/  RBAC/  Business/  Engine/  Module/
│   ├── Customer/  Lead/  Service/  Booking/  Commerce/  Education/  Food/
│   ├── Automation/  Website/  Messaging/  Marketing/  Payment/  Domain/  Analytics/  AI/
├── Http/
│   ├── Controllers/         thin controllers
│   └── Middleware/          HandleInertiaRequests, SecurityHeaders, (planned) ResolveTenant
├── Jobs/ Events/ Listeners/ Notifications/ Policies/   (planned, cross-domain wiring)
├── Providers/
└── Support/                 (planned) shared helpers, TenantContext
resources/js/
├── app.jsx                  Inertia bootstrap
├── app/AppProviders.jsx     MUI theme + CSS-layer provider
├── theme/                   design tokens + MUI theme
├── layouts/                 PublicLayout (later BusinessLayout, AdminLayout)
├── pages/                   Inertia pages (Welcome; later auth/, admin/, onboarding/, business/, website/)
├── components/              shared UI (FeatureCard)
└── modules/                 (planned) customers/, leads/, bookings/, ...
```

Each domain folder will contain, as needed: `Models/`, `Actions/`, `Services/`, `Events/`, `Policies/`,
`Data/` (DTOs), `Enums/`.

## Request flow (target)

```text
Request → Domain resolution (Host header) → Tenant resolution → Authentication
        → Tenant membership → Role → Permission (Policy)
        → Controller → Domain Action/Service → Database
```

Phase 0 has only the web middleware group + `SecurityHeaders` + `HandleInertiaRequests`.

## Engines & modules (target)

- **Business Type** (versioned preset) → recommends engines, modules, widgets, website sections, automations.
- **Engine** (Service, Booking, Commerce, Education, Food) — generic models (e.g. Booking uses a generic *resource*).
- **Module** registry with dependencies; `tenant_modules` stores enabled flag, version and configuration.
- **Sidebar/Dashboard resolvers** build navigation and widgets from enabled engines/modules + user permissions.

See ADR-005 and ADR-006.

## Cross-cutting services (target)

| Service | Purpose | ADR |
|---|---|---|
| `TenantContext` | Current tenant, settings, branding, engines, modules | ADR-002 |
| `DomainResolver` | Host → tenant | ADR-007 |
| `AIService` + provider interface | Gemini/OpenRouter behind one API | ADR-008 |
| `MessagingService` + providers | WhatsApp/Instagram/Email behind one API | — (future ADR) |
| Automation resolver + runner | Event → run → job → action → log | ADR-004 |

## Queues

Redis queues: `default`, `automation`, `messaging`, `ai`, `notifications`, `reports`, `media` (ADR-004).
One scheduler dispatches due work every minute; never one cron per tenant.

## Related

- [multi-tenancy.md](multi-tenancy.md)
- [frontend.md](frontend.md)
- [dependencies.md](dependencies.md)
- [ADRs](../12-decisions/README.md)
