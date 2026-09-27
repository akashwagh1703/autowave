# AutoWave Documentation

Start here if you are new (human or AI agent):

1. [`../AGENTS.md`](../AGENTS.md) — rules of the project
2. [`00-overview/current-state.md`](00-overview/current-state.md) — what actually exists today
3. [`02-architecture/overview.md`](02-architecture/overview.md) — how the system is built
4. [`09-devops/local-development.md`](09-devops/local-development.md) — run it locally

| Folder | Contents |
|---|---|
| `00-overview/` | Product overview, current state, implementation status, known issues, roadmap, glossary |
| `01-product/` | Master product/engineering specification |
| `02-architecture/` | Architecture overview, multi-tenancy, frontend, dependencies |
| `03-database/` | ERD, schema, tenant isolation, indexes, migrations |
| `04-security/` | Authentication, authorization, tenant isolation, API/webhook/file security, threat model |
| `05-features/` | One document per feature (template in README) |
| `06-integrations/` | External providers (AI, messaging, payments) |
| `07-api/` | API conventions and endpoint docs |
| `08-ui/` | Design system |
| `09-devops/` | Environments, deployment, server components, CI/CD |
| `10-testing/` | Testing strategy |
| `11-runbooks/` | Operational procedures |
| `12-decisions/` | Architecture Decision Records |

## Where do I find...?

| Question | Answer |
|---|---|
| What is AutoWave? | `00-overview/product-overview.md` |
| What architecture does it use? | `02-architecture/overview.md`, ADR-001 |
| How does multi-tenancy work? | `02-architecture/multi-tenancy.md`, ADR-002 |
| How does authentication / RBAC work? | `04-security/authentication.md`, `04-security/authorization.md` |
| How are modules / business types enabled? | `02-architecture/overview.md` §Engines & Modules, ADR-005, ADR-006 |
| How does domain resolution work? | `02-architecture/multi-tenancy.md`, ADR-007 |
| How are queues configured? | `09-devops/redis.md`, `09-devops/supervisor.md`, ADR-004 |
| How do I deploy? | `09-devops/deployment.md`, `11-runbooks/deployment.md` |
| What is currently broken? | `00-overview/known-issues.md` |
