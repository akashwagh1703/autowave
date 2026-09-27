# Product Overview

**AutoWave** — Multi-Tenant Local Business Operating & Automation Platform.

> Build, manage and automate a local business from one platform.

## Who it is for

Local businesses — salons, clinics, turfs, coaching centres, cafes, restaurants, local stores — whose
owners are often non-technical and frequently mobile-first.

## What a business gets

- A public website (`business-slug.autowave.in`, later a custom domain) driven by its own business data
- Customer management and CRM with a unified customer timeline
- Lead capture from website, WhatsApp, Instagram, QR, campaigns and manual entry
- Services, products and packages (unified catalog)
- Bookings / appointments / slots and orders
- Messaging and marketing
- Automation (trigger → condition → wait → action)
- AI assistance (optional, never the source of truth)
- Analytics, including transparent "potential revenue" signals

## Core principle

**Configure, don't hard-code.** All business types run on one application. A business type is a
preset of engines, modules, dashboard widgets, website sections, automations and settings.

## Key concepts

- **Tenant** — one business workspace with isolated data.
- **Business Type** — a versioned preset (e.g. Beauty & Salon v1.0).
- **Engine** — a major capability: Service, Booking, Commerce, Education, Food.
- **Module** — a reusable capability: CRM, Leads, Messaging, Website, Automation, Payments...

See the [glossary](glossary.md).

## Platform surfaces

| Surface | Domain | Audience |
|---|---|---|
| Marketing site | `autowave.in` / `www.autowave.in` | Prospects (served by the AutoWave Internal tenant) |
| Business app | `app.autowave.in` | Business owners and staff |
| Super Admin | `admin.autowave.in` | AutoWave platform operators |
| Tenant websites | `{slug}.autowave.in`, custom domains | The business's customers |
| API | `api.autowave.in` | Integrations (versioned `/v1`) |

## Dogfooding

AutoWave runs its own marketing, lead capture, CRM, demo booking and sales pipeline as tenant
**AutoWave Internal**.

## V1 scope

Authentication, multi-tenancy, RBAC, onboarding, business templates, module registry, domain resolution,
customers, leads, services, products, packages, booking, website, forms, basic automation, messaging
abstraction, basic AI abstraction, Super Admin. First complete template: **Beauty & Salon**.

Explicitly excluded from V1: ERP, payroll, accounting, advanced POS, fleet, native mobile apps, Wix-style
builder, microservices, complex AI agents, all verticals at once, franchise management.

The full specification is in [`../01-product/master-prompt.md`](../01-product/master-prompt.md).
