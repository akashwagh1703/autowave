# Authentication

> Status: **implemented in Phase 1** (ADR-010).

## Business app (`app.autowave.in`) — Laravel Fortify

| Feature | Route | Notes |
|---|---|---|
| Login / logout | `GET/POST /login`, `POST /logout` | Remember me supported; throttle `login` (5/min per email+IP) |
| Registration | `GET/POST /register` | Creates a user only; business setup is Phase 2 |
| Email verification | `/email/verify`, signed verify link, resend | `verified` middleware on the app (`EnsureEmailIsVerifiedWhenRequired`); a platform admin can switch it off in Super Admin → Settings |
| Password reset | `/forgot-password`, `/reset-password/{token}` | Standard broker, 60-minute tokens |

- With **Require email confirmation** off (`platform_settings.require_email_verification`, default on):
  new sign-ups are marked verified at registration and get no email, and the `verified` check is skipped
  for accounts still unconfirmed. Switching it back on sends those accounts to `/email/verify` again. Anyone
  can then register with an address they do not own, so it is meant for testing or while email is down.
- Custom `authenticateUsing`: case-insensitive email, rejects **suspended** accounts, records `last_login_at`.
- `active` middleware logs out users suspended after login.
- Fortify routes exist only on the app host; `/login` on any other host is 404.
- Pages: `resources/js/pages/auth/*.jsx`.

## Super Admin (`admin.autowave.in`)

- `Admin\AuthController`: only `is_platform_admin` and active users. Failures return the generic
  `auth.failed` message and are audited (`admin.login_failed`); success audited (`admin.login`).
- Throttle `admin-login` (5/min per email+IP). Session regenerated on login, invalidated on logout.
- First admin is created by `PlatformAdminSeeder` from `AUTOWAVE_ADMIN_*`; with no password set, a random
  one is generated and printed once. A lost password is replaced with
  `php artisan autowave:admin-password [email]` (new random password, printed once).

## Sessions and cookies

- `web` guard, database sessions, `HttpOnly`, `SameSite=Lax`, `Secure` in production.
- `SESSION_DOMAIN` stays unset (host-only cookies): app and admin sessions are independent.

## Account data

- `users.status` (`active|suspended`), `users.is_platform_admin`, `users.last_login_at`.
- Neither `status` nor `is_platform_admin` is mass-assignable (tested).

## Future (do not build yet)

Two-factor, passkeys (Fortify-supported), Google login, OTP.
