# Design System

## Principles

- For non-technical local business owners: plain language, few steps, one clear primary action per screen.
- Mobile-first and responsive (phone, tablet, laptop, desktop); PWA-friendly.
- Dashboards answer: what happened, what needs attention, what should I do, what did AutoWave automate,
  how much business was generated/recovered.

## Tokens

Source: `resources/js/theme/tokens.js` (JS/MUI) mirrored in `resources/css/app.css` `@theme` (Tailwind).
**Change both together.**

| Token | Value | Tailwind | MUI |
|---|---|---|---|
| brand-50 | `#eef2ff` | `bg-brand-50` | — |
| brand-100 | `#e0e7ff` | `bg-brand-100` | — |
| brand-500 | `#6366f1` | `text-brand-500` | `primary.light` |
| brand-600 | `#4f46e5` | `bg-brand-600` | `primary.main` |
| brand-700 | `#4338ca` | `text-brand-700` | `primary.dark` |
| brand-900 | `#312e81` | `text-brand-900` | — |
| accent-500 | `#14b8a6` | `bg-accent-500` | `secondary.light` |
| accent-600 | `#0d9488` | `bg-accent-600` | `secondary.main` |
| success / warning / error / info | `#16a34a` / `#d97706` / `#dc2626` / `#0284c7` | Tailwind defaults | `success` / `warning` / `error` / `info` |
| radius control / card | 8px / 12px | `rounded-lg` / `rounded-card` | `shape.borderRadius` / `MuiCard` |
| font | Inter (fonts.bunny.net) | `font-sans` | `typography.fontFamily` |

## Tailwind vs MUI

| Use Tailwind for | Use MUI for |
|---|---|
| Layout, grid, flex, spacing | DataGrid, Dialog, Drawer, Autocomplete |
| Responsive breakpoints | Date/time pickers, advanced selects |
| Typography utilities, colors | Complex form controls, Snackbar, Menus |

MUI styles are emitted into the `mui` CSS layer (below `utilities`), so Tailwind classes on MUI components
(`<Card className="h-full">`) win without `!important`. MUI `CssBaseline` is not used.

## Component inventory

| Component | Location | Notes |
|---|---|---|
| `PublicLayout` | `resources/js/layouts/PublicLayout.jsx` | Marketing/public shell |
| `FeatureCard` | `resources/js/components/FeatureCard.jsx` | Icon + title + description card |

Planned: buttons/inputs wrappers, page header, data table, empty state, loading skeleton, error state,
permission-denied state, confirm dialog, drawer form, calendar, stat card, charts.

## Required page states

Loading, success, empty, error, permission denied, validation errors.
