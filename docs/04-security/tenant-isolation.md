# Tenant Isolation (Security)

Tenant isolation is a **release-blocking** security requirement.

A tenant must never read, update or delete another tenant's data, files, conversations, reports or website
configuration — regardless of what the client sends.

## Controls

| Threat | Control |
|---|---|
| Client sends another tenant's `tenant_id` | `tenant_id` is never read from input; set from `TenantContext` |
| Guessing IDs of other tenants' records | Global scope + scoped route model binding ⇒ 404 |
| Raw query forgets tenant filter | Raw queries require review; prefer Eloquent |
| Queued job runs without context | Jobs carry `tenant_id` and re-establish context; fail closed if missing |
| File path traversal / guessing | `tenant/{tenant_id}/...` paths; private files via authorized routes / signed URLs |
| Cache poisoning across tenants | Cache keys prefixed with tenant ID |
| Custom domain pointing to wrong tenant | Unique verified domain → exactly one tenant |
| User removed from tenant keeps access | Membership status checked on every request |

## Required tests

See [`../02-architecture/multi-tenancy.md`](../02-architecture/multi-tenancy.md#mandatory-isolation-tests).
