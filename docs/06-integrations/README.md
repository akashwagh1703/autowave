# Integrations

> Status: Meta WhatsApp Cloud API and Instagram messaging (Phase 8) and OpenRouter AI (Phase 9) are implemented.

All external services sit behind an internal interface. Business modules never call vendor APIs directly.

| Area | Internal abstraction | Providers | Phase | Doc |
|---|---|---|---|---|
| WhatsApp | `MessagingService` → `MessagingProvider` → `MetaGraphClient` | Meta Cloud API (per-business app) | 8 ✅ | [meta-whatsapp.md](meta-whatsapp.md) |
| Instagram | same | Instagram API with Instagram Login | 8 ✅ | [instagram.md](instagram.md) |
| Email | `MessagingService` → `MailProvider` → Laravel Mail | SMTP / transactional provider | 5 ✅ | [messaging](../05-features/messaging.md) |
| AI | `AIService` → `AIGateway` → `AIProvider` | OpenRouter (platform key); `fake` for tests | 9 ✅ (ADR-008, ADR-019) | [openrouter.md](openrouter.md) |
| File storage | Laravel `Storage` disk `media` (`WEBSITE_MEDIA_DISK`) | MinIO (S3-compatible); local `public` disk | ✅ images | [minio.md](minio.md) |
| Payments | `PaymentService` → gateway interface | TBD (e.g. Razorpay) — needs ADR | later | — |

For each integration, add a document here covering: credentials/env vars, webhook endpoints and signature
scheme, rate limits, error handling/retries, sandbox testing, and the normalized events it produces.
