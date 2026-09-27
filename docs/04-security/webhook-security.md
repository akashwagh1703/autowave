# Webhook Security

> Status: no webhooks exist yet (Phase 8 messaging, payments later).

## Pipeline

```text
Webhook → Signature verification → Provider adapter → Normalized event → Core domain → Automation
```

## Rules

- Verify the provider signature (e.g. Meta `X-Hub-Signature-256` HMAC with app secret) **before** parsing the body; use `hash_equals`.
- Reject requests outside the provider's timestamp tolerance where supported (replay protection).
- Store the raw payload + provider event ID; process idempotently (unique on provider event ID).
- Respond fast (2xx) and process asynchronously on a queue.
- Resolve the tenant from the provider account mapping (e.g. WhatsApp phone number ID → tenant), never from payload fields controlled by end users.
- Provider-specific payload shapes must not leak into core domain models.
- Rate limit webhook endpoints; exclude them from CSRF only individually.
