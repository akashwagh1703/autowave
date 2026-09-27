# API Security

> Status: no external API exists yet.

## Current controls (web)

- CSRF protection on all web (Inertia) routes via Laravel's `web` middleware group.
- `SecurityHeaders` middleware on every response: `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `Permissions-Policy: camera=(), microphone=(), geolocation=()`, and HSTS on HTTPS.

## Planned for `/v1` APIs

- Token authentication (Laravel Sanctum, pending ADR) scoped to one tenant.
- Rate limiting per token and per IP (`RateLimiter::for('api', ...)`).
- Form Request validation, consistent JSON error format, pagination/filter/sort conventions (`docs/07-api/`).
- No sensitive data in URLs or logs. OpenAPI documentation for every public endpoint.
