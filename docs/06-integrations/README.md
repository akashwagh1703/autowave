# Integrations

> Status: Meta WhatsApp Cloud API and Instagram messaging are implemented (Phase 8).

All external services sit behind an internal interface. Business modules never call vendor APIs directly.

| Area | Internal abstraction | Providers | Phase | Doc |
|---|---|---|---|---|
| WhatsApp | `MessagingService` → `MessagingProvider` → `MetaGraphClient` | Meta Cloud API (per-business app) | 8 ✅ | [meta-whatsapp.md](meta-whatsapp.md) |
| Instagram | same | Instagram API with Instagram Login | 8 ✅ | [instagram.md](instagram.md) |
| Email | `MessagingService` → `MailProvider` → Laravel Mail | SMTP / transactional provider | 5 ✅ | [messaging](../05-features/messaging.md) |
| AI | `AIService` → `AIProvider` interface | Gemini, OpenRouter | 9 (ADR-008) | — |
| Payments | `PaymentService` → gateway interface | TBD (e.g. Razorpay) — needs ADR | later | — |

For each integration, add a document here covering: credentials/env vars, webhook endpoints and signature
scheme, rate limits, error handling/retries, sandbox testing, and the normalized events it produces.
