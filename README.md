# AutoWave

**Multi-Tenant Local Business Operating & Automation Platform** — build, manage and automate a local
business from one platform.

One Laravel application serves many businesses (tenants). Each tenant gets its own data, users, roles,
enabled engines/modules, website, branding, domain, automations and dashboard.

> **Status:** Phase 0 (project foundation) complete. No business features are implemented yet.
> See [`docs/00-overview/current-state.md`](docs/00-overview/current-state.md).

## Stack

Laravel 13 · PHP 8.4 · PostgreSQL 17 · Redis 7 · Inertia.js v3 · React 19 (JSX) · Vite 7 · Tailwind CSS v4 · MUI v9

## Quick start (local)

Prerequisites: PHP 8.3+ (with `pdo_pgsql`, `mbstring`, `intl`, `zip`), Composer 2, Node 22+, Docker Desktop.

```bash
cp .env.example .env              # Windows: copy .env.example .env
docker compose up -d              # PostgreSQL + Redis
composer install
php artisan key:generate
php artisan migrate
npm install
npm run build                     # or: npm run dev
php artisan autowave:health       # verifies PostgreSQL, Redis, cache
composer dev                      # server + queue worker + logs + Vite
```

Open http://localhost:8000.

Full guide (including Windows notes and port conflicts): [`docs/09-devops/local-development.md`](docs/09-devops/local-development.md).

## Common commands

| Task | Command |
|---|---|
| Run tests (PostgreSQL `autowave_testing`) | `php artisan test` |
| Code style check / fix | `vendor/bin/pint --test` / `vendor/bin/pint` |
| Frontend dev server / build | `npm run dev` / `npm run build` |
| Queue worker | `php artisan queue:work redis` |
| Scheduler (local) | `php artisan schedule:work` |
| Infrastructure check | `php artisan autowave:health` |

## Documentation

| Where | What |
|---|---|
| [`AGENTS.md`](AGENTS.md) | Project constitution: rules for developers and AI agents |
| [`docs/01-product/master-prompt.md`](docs/01-product/master-prompt.md) | Full product and engineering specification |
| [`docs/00-overview/`](docs/00-overview/) | Current state, implementation status, known issues, roadmap, glossary |
| [`docs/02-architecture/`](docs/02-architecture/) | Architecture, multi-tenancy, frontend |
| [`docs/12-decisions/`](docs/12-decisions/) | Architecture Decision Records |
| [`docs/09-devops/`](docs/09-devops/) · [`docs/11-runbooks/`](docs/11-runbooks/) | Setup, deployment, operations |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Workflow, branches, commits, definition of done |
| [`CHANGELOG.md`](CHANGELOG.md) | User-visible changes |
