# ADR-010: Fortify for business-app authentication; separate Super Admin login

- **Status:** Accepted
- **Date:** 2026-09-27

## Context

Phase 1 needs login, logout, registration, email verification, password reset, remember me and account
status. Business users log in on `app.autowave.in`; platform administrators on `admin.autowave.in`.
The UI is React (Inertia), so we need a headless backend.

## Decision

- **Laravel Fortify** (headless) provides business-app auth. Its routes are bound to the app host only
  (`config/fortify.php` → `domain`). Views are Inertia pages registered in `FortifyServiceProvider`.
- Enabled features: registration, password reset, email verification. Two-factor and passkeys are
  disabled in V1 (the library supports them for later).
- `Fortify::authenticateUsing` rejects suspended accounts and records `last_login_at`.
  `EnsureAccountIsActive` (`active` middleware) logs out users suspended mid-session.
- **Super Admin** uses a small custom controller (`Admin\AuthController`) on the admin host:
  only `is_platform_admin` + active users can sign in; failures are generic (`auth.failed`) and audited;
  separate rate limiter (`admin-login`).
- Both use the `web` guard, but sessions are host-only cookies (`SESSION_DOMAIN=null`), so an app
  session never authenticates the admin host and vice versa.
- `users.is_platform_admin` and `users.status` are never mass-assignable.

## Alternatives

- **Breeze/Jetstream starter kit** — generates UI we would rewrite in MUI; ties us to its structure.
- **Hand-written auth controllers** — more code to secure and maintain than Fortify's audited actions.
- **Separate `admins` table / guard** — doubles user management; the platform flag plus host separation
  is sufficient and lets internal staff also be tenant members (AutoWave Internal tenant).

## Consequences

- Adding 2FA/passkeys later is a Fortify feature flag plus migrations and UI.
- Admin auth must stay in step with Fortify's security properties (throttling, session regeneration).
- Registration creates only a user; business creation is Phase 2 (one-click onboarding).
