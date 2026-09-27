# Frontend Architecture

## Stack

Inertia.js v3 (server-driven routing) + React 19 in **JavaScript/JSX** + Vite 7 + Tailwind CSS v4 + MUI v9.
No client-side router and no separate SPA API for app pages: Laravel controllers return
`Inertia::render('Page', $props)`.

## Bootstrapping

- `resources/views/app.blade.php` — root template; loads `app.css`, `app.jsx` and the current page chunk.
- `resources/js/app.jsx` — `createInertiaApp`, resolves `pages/**/*.jsx` lazily, wraps in `AppProviders`.
- `resources/js/app/AppProviders.jsx` — `StyledEngineProvider enableCssLayer` + MUI `ThemeProvider`.
- Import alias `@/` → `resources/js/` (configured in `vite.config.js`).

## Shared props

Set in `app/Http/Middleware/HandleInertiaRequests.php`:

| Prop | Content |
|---|---|
| `app.name` | Application name |
| `auth.user` | `{id, name, email}` or `null` |
| `flash.success`, `flash.error` | One-time session messages (lazy) |

Planned: `tenant` (name, branding, enabled modules), `can` (server-computed permission flags), `menu`
(resolved sidebar). Never share secrets.

## Styling

- Tailwind for layout and spacing; MUI for complex controls. See [`../08-ui/design-system.md`](../08-ui/design-system.md).
- CSS layer order `theme, base, mui, components, utilities` so utilities win over MUI styles.

## Conventions

See `.cursor/rules/react.mdc` and `.cursor/rules/ui.mdc`.
