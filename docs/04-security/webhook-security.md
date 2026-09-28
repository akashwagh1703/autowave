# Webhook Security

> Status: Meta webhooks (WhatsApp and Instagram) are implemented (Phase 8, ADR-018). Payment webhooks
> come later and must follow the same rules.

## Pipeline

```text
Webhook → key lookup → signature verification → stored call → queue → normalizer → core domain → timeline / inbox
```

## Rules

- Verify the provider signature **before** parsing the body; compare with `hash_equals`.
- Store the raw payload; process idempotently on the provider's event or message id.
- Respond fast (2xx) and process asynchronously on a queue.
- Resolve the tenant from our own mapping, never from payload fields controlled by end users.
- Provider-specific payload shapes must not leak into core domain models.
- Rate limit webhook endpoints. They are outside the `web` middleware group: no session, no CSRF, no
  cookies.

## Meta implementation

| Control | How |
|---|---|
| Tenant resolution | The URL key (`/webhooks/meta/{key}`, 40 random characters, unique) selects one `messaging_channels` row. Keys of the wrong length return 404 before any query. |
| Authenticity | `X-Hub-Signature-256` = `sha256=` + HMAC-SHA256(raw body, that channel's app secret). Missing or wrong → 403, nothing stored. |
| Cross-tenant spoofing | A body signed with business B's secret fails on business A's URL. Entries for a phone number id / account id other than the connected one are ignored. |
| Verification handshake | `hub.verify_token` compared with `hash_equals`; the challenge is echoed as `text/plain`, truncated to 200 characters. |
| Size | Bodies over `messaging.webhooks.max_payload_kb` (512) → 413. |
| Rate limit | `throttle:meta-webhooks`: `messaging.webhooks.rate_limit` per minute per IP and key. |
| Idempotency | Inbound messages unique on `(tenant_id, channel, provider_message_id)`; receipts only move forward. |
| Error pages | `webhooks/*` responses bypass the Inertia error page (plain status code). |
| Retention | Stored bodies contain customer messages; pruned after 14 days. |
| Secrets | App secrets and tokens are `encrypted:array` in the database, hidden from serialisation, never sent to the browser or logged. |

Replay protection: Meta does not sign a timestamp, so an old signed body could be re-sent. Idempotency
on message ids makes a replay harmless.
