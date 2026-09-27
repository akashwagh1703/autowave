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
