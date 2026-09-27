# Changelog

All notable user-visible changes are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Categories: Added, Changed, Fixed, Security, Deprecated, Removed.

## [Unreleased]

### Added

- Phase 1 platform foundation:
  - Host-based routing for marketing, business app, Super Admin and tenant websites.
  - Login, logout, remember me, registration, email verification and password reset (Laravel Fortify).
  - Separate Super Admin sign-in, dashboard and tenant list with suspend/activate.
  - Tenants, memberships, workspace switching and tenant-scoped data with fail-closed isolation.
  - Roles and permissions per business (Owner, Manager, Receptionist, Sales Executive, Staff, Accountant).
  - Business types, engines and modules catalogue with dependency checks.
  - Tenant subdomains and custom-domain resolution with caching.
  - Business dashboard, read-only settings page and placeholder public website.
  - Audit log for admin sign-in and tenant status changes.
  - Seeders for the catalogue, roles, first platform admin, AutoWave Internal tenant and local demo tenants.
- Phase 0 project foundation: Laravel 13, Inertia.js v3 + React 19, Vite, Tailwind CSS v4, MUI v9.
- PostgreSQL 17 and Redis 7 for local development via `docker-compose.yml`.
- Redis-backed cache and queue configuration.
- `php artisan autowave:health` command to verify database, Redis and cache connectivity.
- Placeholder AutoWave welcome page.
- GitHub Actions CI (Pint, PHPUnit on PostgreSQL, frontend build).
- Project documentation structure, `AGENTS.md`, Cursor rules and initial ADRs.

### Fixed

- MUI component styles were overridden by Tailwind's reset on pages using text fields (cascade layer order).

### Security

- Suspended users are blocked at login and signed out mid-session.
- Database-level guarantee that roles cannot be assigned across businesses.
- Tests refuse to run against any database not named `*_testing`.

- Security headers middleware (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`, HSTS over HTTPS).
