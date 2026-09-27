# Changelog

All notable user-visible changes are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Categories: Added, Changed, Fixed, Security, Deprecated, Removed.

## [Unreleased]

### Added

- Phase 0 project foundation: Laravel 13, Inertia.js v3 + React 19, Vite, Tailwind CSS v4, MUI v9.
- PostgreSQL 17 and Redis 7 for local development via `docker-compose.yml`.
- Redis-backed cache and queue configuration.
- `php artisan autowave:health` command to verify database, Redis and cache connectivity.
- Placeholder AutoWave welcome page.
- GitHub Actions CI (Pint, PHPUnit on PostgreSQL, frontend build).
- Project documentation structure, `AGENTS.md`, Cursor rules and initial ADRs.

### Security

- Security headers middleware (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`, HSTS over HTTPS).
