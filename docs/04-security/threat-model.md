# Threat Model (initial)

Living document — revisit at the end of every phase.

## Assets

Tenant business data (customers, leads, bookings, orders, conversations), user credentials, provider API
keys (AI, WhatsApp, payments), tenant websites/domains, platform admin access.

## Actors

Anonymous internet users, tenant customers (public website), tenant staff with limited roles, tenant owners,
malicious tenants, compromised provider webhooks, platform operators.

## Top threats and mitigations

| # | Threat | Mitigation |
|---|---|---|
| T1 | Cross-tenant data access (IDOR, missing scope) | TenantContext + global scopes + policies + mandatory isolation tests |
| T2 | Privilege escalation inside a tenant | Granular RBAC in policies; server-computed UI flags |
| T3 | Super Admin compromise | Separate admin domain, strong auth (2FA planned), audit logs |
| T4 | Credential stuffing / brute force | Rate limiting, lockouts, secure password hashing |
| T5 | Forged webhooks | Signature verification, idempotency, tenant mapping from provider account |
| T6 | Malicious uploads | MIME/extension validation, private storage, no inline SVG |
| T7 | Secret leakage | Secrets only in server env; never in Inertia props, JS bundles or logs |
| T8 | XSS via tenant website content | React escaping; sanitise any rich text; CSP (planned) |
| T9 | Double booking / race conditions | DB constraints + row locks inside transactions |
| T10 | Queue job loss | Redis AOF, `failed_jobs`, retries, monitoring |
| T11 | Data loss | Daily off-server PostgreSQL backups + tested restore |
| T12 | Domain takeover via dangling custom domain | Verification before activation; periodic re-verification |
