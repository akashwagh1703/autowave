# API

> Status: no external API endpoints exist. App pages use Inertia (not a JSON API).

## Conventions for future `/v1` APIs

- Base: `api.autowave.in/v1/...` (resources: `/v1/leads`, `/v1/customers`, `/v1/appointments`, `/v1/orders`, `/v1/automations`).
- Methods: `GET` list/show, `POST` create, `PATCH` partial update, `DELETE` delete.
- Status codes: `200`, `201` (created), `204` (no content), `401`, `403`, `404` (also for other tenants' resources), `422` (validation), `429`.
- Validation errors: Laravel default `{ "message": "...", "errors": { "field": ["..."] } }`.
- Pagination: `?page=&per_page=` (max 100), response `data` + `meta` + `links` (Laravel API resources).
- Filtering/sorting: `?filter[status]=new&sort=-created_at`.
- Tenant scope comes from the API token; never from a request parameter.
- Maintain an OpenAPI spec alongside endpoints (`docs/07-api/openapi.yaml`) once the first endpoint exists.
