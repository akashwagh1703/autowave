# Integration: Meta WhatsApp Cloud API

- **Status:** ✅ Phase 8 (manual connection per business)
- **Decision:** [ADR-018](../12-decisions/ADR-018-messaging-channels.md)
- **Code:** `app/Domain/Messaging/Meta/MetaGraphClient.php`, `Providers/MetaWhatsAppProvider.php`,
  `Meta/MetaWebhookNormalizer.php`, `Http/Controllers/Webhooks/MetaWebhookController.php`

## What the business needs

Each business uses **its own** Meta app and WhatsApp Business Account (no platform app yet; AW-050).

| Field in Settings → Messaging | Where to find it in Meta |
|---|---|
| Phone number id | App dashboard → WhatsApp → API setup → "Phone number ID" (not the phone number) |
| WhatsApp Business Account id | Same page → "WhatsApp Business Account ID" |
| Access token | A **System User** permanent token with `whatsapp_business_messaging` and `whatsapp_business_management` (Business settings → System users). The temporary 24-hour token works for testing only. |
| App secret | App settings → Basic → App secret |

Then, in the app's WhatsApp → Configuration:

1. Callback URL = the value shown in Settings → Messaging (`https://app.<domain>/webhooks/meta/<key>`).
2. Verify token = the value shown next to it.
3. Subscribe to the **messages** field.

## Configuration (`config/messaging.php`)

| Key | Env | Default |
|---|---|---|
| `meta.graph_version` | `META_GRAPH_VERSION` | `v21.0` |
| `meta.graph_url` | `META_GRAPH_URL` | `https://graph.facebook.com` |
| `meta.timeout` | — | 15 s |
| `meta.template_pages` | — | 10 pages of 100 templates |
| `providers.meta_whatsapp.window_hours` | — | 24 |
| `webhooks.rate_limit` | — | 600 requests/minute per IP and key |
| `webhooks.max_payload_kb` | — | 512 |

No platform-level secrets: every token lives encrypted in the tenant's `messaging_channels.credentials`.

## Calls made

| Purpose | Request |
|---|---|
| Verify on connect | `GET /{version}/{phone_number_id}?fields=display_phone_number,verified_name` |
| Send | `POST /{version}/{phone_number_id}/messages` with `type: text` or `type: template` (body parameters), `biz_opaque_callback_data` = our message id |
| Sync templates | `GET /{version}/{waba_id}/message_templates` (follows `paging.next` on the Graph host only) |

The token is sent as `Authorization: Bearer …`, never in a URL or log.

## Webhooks

- `GET /webhooks/meta/{key}`: echoes `hub.challenge` when `hub.mode=subscribe` and `hub.verify_token`
  matches (`hash_equals`). Otherwise 403.
- `POST /webhooks/meta/{key}`: `X-Hub-Signature-256` must equal `sha256=` + HMAC-SHA256 of the raw body
  with the app secret. Unknown key → 404; bad signature → 403; body over the limit → 413; bad JSON →
  400. A verified body is stored and answered `200 EVENT_RECEIVED`.
- Processed on the `messaging` queue. Entries whose `metadata.phone_number_id` is not the connected
  number are ignored.

Normalised events:

| Meta | AutoWave |
|---|---|
| `messages[]` type `text`, `button`, `interactive` | `InboundMessage` with the text |
| `image`, `video`, `document` (+caption), `audio`, `sticker`, `location`, `contacts` | `InboundMessage` with a placeholder (`[Image] caption`) |
| `reaction` | ignored |
| `statuses[]` `sent`, `delivered`, `read`, `failed` (+ error title and details) | `StatusUpdate` |

## Errors

- HTTP 4xx other than 429 and Meta's rate-limit codes → `PermanentDeliveryFailure`: the message fails at
  once with Meta's message (e.g. "Re-engagement message — More than 24 hours have passed").
- 5xx, 429, timeouts → retried by the job (3 tries, backoff 60 s, 300 s).

## Sandbox testing

- Meta's test number and the temporary token work; add your phone as a test recipient in API setup.
- Without Meta at all: `php artisan messaging:simulate-inbound {tenant} {phone} "text"`.
- Tests use `Http::fake` and signed webhook bodies (`tests/Feature/Messaging`).
