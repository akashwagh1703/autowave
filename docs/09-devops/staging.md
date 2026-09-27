# Staging

> Draft — no staging server exists yet (AW-004).

- Separate small VPS (or the same VPS with a separate database, Redis DB index and Unix user) mirroring production.
- Domains: `staging.autowave.in` (app), `admin.staging.autowave.in`, `*.staging.autowave.in` (tenant sites).
- `APP_ENV=staging`, `APP_DEBUG=false`, mail to a sandbox (e.g. log or Mailpit), provider sandboxes/test numbers only.
- Deployed automatically from `develop` once CI deploy is set up; production deploys from `main`.
- Never copy production personal data into staging without anonymisation.
