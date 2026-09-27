# Integrations

> Status: no external integrations are implemented.

All external services sit behind an internal interface. Business modules never call vendor APIs directly.

| Area | Internal abstraction | Planned providers | Phase |
|---|---|---|---|
| AI | `AIService` → `AIProvider` interface | Gemini, OpenRouter | 9 (ADR-008) |
| Messaging | `MessagingService` → provider interface | WhatsApp (Meta Cloud API), Instagram, Email; SMS later | 8 |
| Payments | `PaymentService` → gateway interface | TBD (e.g. Razorpay) — needs ADR | later |
| Email | Laravel Mail | SMTP / transactional provider | 1 |

For each integration, add a document here covering: credentials/env vars, webhook endpoints and signature
scheme, rate limits, error handling/retries, sandbox testing, and the normalized events it produces.
