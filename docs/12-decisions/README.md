# Architecture Decision Records

Every major architectural change requires an ADR. Create the next number; never renumber.
Superseded ADRs keep their file and get `Status: Superseded by ADR-XXX`.

Format: **Title, Status, Date, Context, Decision, Alternatives, Consequences.**

| ADR | Title | Status |
|---|---|---|
| [ADR-001](ADR-001-modular-monolith.md) | Modular monolith | Accepted |
| [ADR-002](ADR-002-shared-postgres-tenancy.md) | Shared PostgreSQL database with `tenant_id` isolation | Accepted |
| [ADR-003](ADR-003-laravel-inertia-react.md) | Laravel + Inertia + React (JavaScript) | Accepted |
| [ADR-004](ADR-004-redis-queues.md) | Redis queues and single scheduler | Accepted |
| [ADR-005](ADR-005-business-engine-model.md) | Business type and engine model | Accepted |
| [ADR-006](ADR-006-module-system.md) | Module registry and dependencies | Accepted |
| [ADR-007](ADR-007-domain-resolution.md) | Host-based domain resolution | Accepted |
| [ADR-008](ADR-008-ai-provider-abstraction.md) | AI provider abstraction | Accepted |
| [ADR-009](ADR-009-local-infrastructure.md) | Local infrastructure: Docker services, predis, PostgreSQL tests | Accepted |
| [ADR-010](ADR-010-authentication.md) | Fortify for business-app auth; separate Super Admin login | Accepted |
| [ADR-011](ADR-011-tenant-enforcement.md) | Tenant enforcement: host routing, fail-closed scope, tenant-safe RBAC keys | Accepted |
| [ADR-012](ADR-012-website-sections-and-templates.md) | Section-based website configuration and templates | Accepted |
| [ADR-013](ADR-013-crm-data-model.md) | CRM data model: configurable stages with outcomes, one activity timeline | Accepted |
| [ADR-014](ADR-014-booking-model.md) | Booking model: generic resources, range exclusion constraint, locked bookings | Accepted |
| [ADR-015](ADR-015-automation-engine.md) | Automation engine: one queued job per step, database-backed waits, idempotent actions | Accepted |
| [ADR-016](ADR-016-website-builder.md) | Website builder: schema-driven sections, tenant media, public forms through domain actions | Accepted |
| [ADR-017](ADR-017-commerce-engine.md) | Commerce engine: server-priced orders, a locked stock ledger, manual payments | Accepted |
| [ADR-018](ADR-018-messaging-channels.md) | Messaging channels: per-business Meta apps, stored webhooks, one compliance gate | Accepted |
| [ADR-019](ADR-019-ai-features.md) | AI features: OpenRouter behind one gateway, drafts never sent, metered monthly cap | Accepted |
| [ADR-020](ADR-020-additional-verticals.md) | Additional verticals: education and food engines, turf rates, coupons | Accepted |
| [ADR-021](ADR-021-whatsapp-assistant.md) | WhatsApp assistant: rule-based menu from live business data, interactive messages, hand-over to staff | Accepted |
