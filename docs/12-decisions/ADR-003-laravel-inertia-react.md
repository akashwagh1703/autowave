# ADR-003: Laravel + Inertia + React (JavaScript)

- **Status:** Accepted
- **Date:** 2026-09-27

## Context

We need a productive full-stack setup with server-side authority for authorization and validation, a rich
interactive UI for dashboards, and minimal duplication between backend and frontend.

## Decision

- Backend: Laravel 13 on PHP 8.3+.
- Frontend: React 19 via Inertia.js v3, built with Vite. **JavaScript/JSX only** — no TypeScript unless a
  later ADR approves it.
- Styling: Tailwind CSS v4 for layout; MUI v9 for complex components (data grids, dialogs, pickers).
- The official Laravel React starter kit was **not** used because it is TypeScript + shadcn/ui; Inertia was
  wired manually on the plain skeleton.
- External/public APIs, when needed, are versioned (`/v1/...`) separately from Inertia routes.

## Alternatives

- **Separate SPA + REST API** — more flexibility but duplicates routing/auth and adds API surface for every screen.
- **Blade + Livewire** — simpler, but less suited to rich dashboards and the team's React preference.
- **TypeScript** — better type safety; rejected for now per product owner preference.

## Consequences

- Controllers return Inertia responses; permissions and prices are computed server-side and passed as props.
- Tailwind and MUI coexist via CSS cascade layers (`mui` layer below `utilities`).
- Without TypeScript, prop contracts rely on conventions and tests.
