# Glossary

Use these terms consistently in code, UI copy and documentation.

| Term | Definition |
|---|---|
| **Tenant** | One business account on AutoWave with fully isolated data. Most tables carry `tenant_id`. |
| **Workspace** | The tenant's business application experience (dashboard, sidebar, settings) at `app.autowave.in`. |
| **Business Type** | A versioned preset (e.g. "Beauty & Salon v1.0") that recommends engines, modules, dashboard widgets, website sections, default automations and settings. Applied at onboarding; not a code branch. |
| **Engine** | A major business capability built on a generic model: Service, Booking, Commerce, Education, Food. A tenant can have several. |
| **Module** | A reusable capability that works across business types: CRM, Leads, Customers, Messaging, Marketing, Automation, Website, Forms, QR, Reviews, Loyalty, Membership, Payments, Analytics, Inventory. Registered in the module registry, enabled per tenant, versioned, may depend on other modules. |
| **Feature (flag)** | A named switch (e.g. `AI_ASSISTANT`, `CUSTOM_DOMAIN`) enabled globally, per plan or per tenant. |
| **Extension** | Tenant-specific functionality that cannot be met by configuration, custom fields, workflows or reusable modules. Last resort; requires an ADR. |
| **Custom Field** | A tenant-defined field on an entity (e.g. "Preferred Stylist" on Customer). |
| **Workflow** | A configurable sequence of business steps (e.g. lead stages). |
| **Automation** | A tenant-defined rule: Trigger → Condition → Wait → Action, executed asynchronously and logged. |
| **Automation Run** | One execution of an automation for one subject (e.g. one lead), with status, attempts and logs. |
| **Lead** | A potential customer captured from any source, moving through configurable stages until converted or lost. |
| **Customer** | A person/business that has transacted or been converted; owns the unified timeline. |
| **Resource** | A bookable thing in the Booking Engine: staff member, turf, doctor, room, table. |
| **Catalog Item** | A SERVICE, PRODUCT or PACKAGE a tenant sells. |
| **Domain** | A hostname mapped to exactly one tenant: `{slug}.autowave.in` or a verified custom domain. |
| **Provider** | An external integration behind an interface (AI: Gemini/OpenRouter; Messaging: WhatsApp/Instagram/Email; Payments). |
| **Tenant Context** | The server-side service holding the current tenant, its settings, branding, domain, enabled engines and modules for the request/job. |
| **Super Admin** | AutoWave platform operator using `admin.autowave.in`; separate from tenant roles. |
| **AutoWave Internal** | The tenant AutoWave itself uses for marketing, lead capture, CRM and sales (dogfooding). |
| **Potential Revenue** | Transparent estimate of revenue at stake (unconverted leads, no-shows, due repeat customers). Never reported as recovered without verified revenue data. |
