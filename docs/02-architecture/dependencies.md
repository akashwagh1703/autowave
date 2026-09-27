# Dependencies

Every non-skeleton dependency and why it exists. Update when adding or removing packages.

## PHP (composer)

| Package | Why | Added |
|---|---|---|
| `laravel/framework` ^13 | Core framework (skeleton) | Phase 0 |
| `laravel/tinker` | REPL (skeleton) | Phase 0 |
| `inertiajs/inertia-laravel` ^3.4 | Server adapter for Inertia (ADR-003) | Phase 0 |
| `predis/predis` ^3.6 | Pure-PHP Redis client; phpredis is unavailable on Windows dev (ADR-009) | Phase 0 |
| `laravel/fortify` ^1.40 | Headless auth backend for the business app (ADR-010). Pulls in `laravel/passkeys` and 2FA libraries, which stay disabled | Phase 1 |
| dev: `laravel/pint`, `phpunit/phpunit`, `mockery/mockery`, `fakerphp/faker`, `nunomaduro/collision`, `laravel/pail` | Skeleton dev tooling | Phase 0 |

## JavaScript (npm)

| Package | Why | Added |
|---|---|---|
| `react`, `react-dom` ^19 | UI library (ADR-003) | Phase 0 |
| `@inertiajs/react` ^3 | Inertia client adapter | Phase 0 |
| `@mui/material`, `@mui/icons-material` ^9 | Complex UI controls and icons | Phase 0 |
| `@emotion/react`, `@emotion/styled` | Required styling engine for MUI | Phase 0 |
| dev: `vite` ^7, `laravel-vite-plugin` ^2, `@vitejs/plugin-react` ^5.2 | Build tooling (plugin-react 5.2 is the latest supporting Vite 7) | Phase 0 |
| dev: `tailwindcss`, `@tailwindcss/vite` ^4 | Utility CSS (skeleton) | Phase 0 |
| dev: `axios`, `concurrently` | Skeleton; `concurrently` powers `composer dev` | Phase 0 |

## Planned (not installed)

| Package | When |
|---|---|
| `react-hook-form`, `zod` | First complex forms (Phase 1/2) |
| `@mui/x-data-grid`, `@mui/x-date-pickers` | First data tables / booking UI |
| `laravel/horizon` | Production queue management (AW-001) |
| ESLint + Prettier | AW-003 |
