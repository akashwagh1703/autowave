# ADR-008: AI Provider Abstraction

- **Status:** Accepted
- **Date:** 2026-09-27

## Context

AI helps with lead extraction, replies, summaries and copy generation. Providers and prices change quickly.
AI output is probabilistic and must never be treated as business truth.

## Decision

- Business code calls an `AIService` with use-case methods (`extractLead`, `generateReply`,
  `summarizeConversation`, `generateWebsiteCopy`, `generateMarketingCopy`, `analyzeConversation`).
- `AIService` delegates to a provider interface; implementations `GeminiProvider`, `OpenRouterProvider`, future providers.
- AI calls run on the `ai` queue where latency allows; usage is metered per tenant.
- Rules/database first; AI only when no known answer exists. The platform works fully when AI is unavailable.
- AI never decides price, inventory, availability, appointment truth, payment status or order status.

## Alternatives

- **Direct vendor SDK calls in modules** — fast, but vendor lock-in and scattered prompt logic.

## Consequences

- Swapping providers is a config change. Prompt templates live in one place.
- Requires usage tracking and plan limits (billing model).
