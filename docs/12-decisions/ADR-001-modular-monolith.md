# ADR-001: Modular Monolith

- **Status:** Accepted
- **Date:** 2026-09-27

## Context

AutoWave must support many business types and many capabilities (CRM, booking, commerce, automation,
website, messaging, AI) with a small team. Requirements will change quickly while product–market fit is found.

## Decision

Build a single Laravel application with a single Inertia/React frontend, one PostgreSQL database and one
Redis. Separate code internally by domain under `app/Domain/<Domain>/`. Domains communicate through
actions/services and domain events, not by reaching into each other's internals.

## Alternatives

- **Microservices** — independent scaling and deploys, but large operational overhead (network calls,
  distributed transactions, observability) that is unjustified at this stage.
- **Unstructured monolith** — fastest start, but business logic in controllers becomes unmaintainable.

## Consequences

- One deploy, one database transaction boundary, simple local development.
- Discipline required to keep domain boundaries; enforced via `AGENTS.md` and code review.
- Individual domains can be extracted later if real scaling needs appear.
