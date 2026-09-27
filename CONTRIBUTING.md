# Contributing to AutoWave

Read [`AGENTS.md`](AGENTS.md) first. It is binding for humans and AI agents.

## Workflow

1. Pick or create an issue with an ID (`AW-XXX`). Categories: Feature, Bug, Security, Technical Debt,
   Architecture, Documentation, DevOps.
2. Branch from `develop`: `feature/AW-XXX-short-name`, `fix/AW-XXX-short-name` (`hotfix/*` from `main`).
3. Plan: database, backend, frontend, security, tests, documentation, risks. Architectural changes need an ADR first.
4. Implement the scoped change only.
5. Run checks locally:
   ```bash
   php artisan test
   vendor/bin/pint --test
   npm run build
   ```
6. Review your own `git diff`.
7. Update documentation (see the sync table in `AGENTS.md` §9).
8. Commit with conventional commits and open a PR into `develop`.

## Task format

Major tasks use this template:

```text
TASK ID: AW-XXX
TITLE:
CONTEXT:
RELATED DOCUMENTATION:
GOAL:
REQUIREMENTS:
CONSTRAINTS:
DATABASE CHANGES:
BACKEND CHANGES:
FRONTEND CHANGES:
SECURITY:
TESTING:
DOCUMENTATION:
ACCEPTANCE CRITERIA:
OUT OF SCOPE:
```

## Commit messages

```text
feat(lead): add automatic lead assignment [AW-031]
fix(booking): prevent duplicate slot booking [AW-142]
docs(tenant): update tenant resolution architecture
test(rbac): add cross-tenant authorization tests
chore: bump dev tooling
security(auth): rate limit password reset
```

Types: `feat`, `fix`, `refactor`, `docs`, `test`, `chore`, `security`.

## Definition of done

A feature is done only when **all** of these hold:

- Implementation works
- Authorization works (Policies/Gates, server-side)
- Tenant isolation works and is tested
- Validation works (server-side)
- Tests pass (`php artisan test`)
- Loading / empty / error / permission-denied states exist in the UI
- Documentation is updated
- A migration exists if the schema changed
- The git diff has been reviewed
- `CHANGELOG.md` is updated for user-visible changes

## Dependencies

Before adding a package: check whether Laravel or an existing dependency already covers it, check
maintenance and security status and PHP/Laravel compatibility, then record the reason in
[`docs/02-architecture/dependencies.md`](docs/02-architecture/dependencies.md).
