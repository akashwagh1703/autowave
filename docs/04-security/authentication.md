# Authentication

> Status: **not implemented** (Phase 1). The `users` table and Laravel's session guard exist from the skeleton.

## Planned V1 scope

Login, logout, registration, email verification, password reset, session management, remember me, account
status (active/suspended).

## Design

- Laravel session guard (`web`) with database sessions; cookies `HttpOnly`, `SameSite=Lax`, `Secure` in production.
- Passwords hashed with bcrypt (`BCRYPT_ROUNDS=12`).
- Rate limiting on login, registration, password reset and verification resend.
- Account status checked at login and on every request (suspended users are logged out).
- After login, the user selects/gets their active tenant membership (see multi-tenancy docs).
- Super Admin authentication is separate from tenant membership (platform role flag), served only on `admin.autowave.in`.

## Future (do not build yet)

Google login, OTP, passkeys — the design keeps authentication pluggable.
