# Integration: Instagram messaging

- **Status:** ✅ Phase 8 (receive and reply to DMs; manual connection)
- **Decision:** [ADR-018](../12-decisions/ADR-018-messaging-channels.md)
- **Code:** `app/Domain/Messaging/Meta/MetaGraphClient.php`, `Providers/InstagramProvider.php`,
  `Meta/MetaWebhookNormalizer.php`

## What the business needs

An Instagram **professional** (business or creator) account and a Meta app with the *Instagram API with
Instagram Login* product.

| Field in Settings → Messaging | Where to find it |
|---|---|
| Access token | App dashboard → Instagram → API setup with Instagram login → generate a token for the account (`instagram_business_basic`, `instagram_business_manage_messages`) |
| App secret | App dashboard → Instagram → API setup → "Instagram app secret" |
| Account id (optional) | Read from the token (`GET /me?fields=user_id`); only needed if Meta does not return it |

Then set the webhook in the same API setup page: callback URL and verify token from Settings →
Messaging, and subscribe to **messages** (and **message_reads** for read receipts).

## Configuration

| Key | Env | Default |
|---|---|---|
| `meta.instagram_url` | `META_INSTAGRAM_URL` | `https://graph.instagram.com` |
| `meta.graph_version` | `META_GRAPH_VERSION` | `v21.0` |
| `providers.instagram.window_hours` | — | 24 |
| `channels.instagram.provider` (fallback) | `MESSAGING_INSTAGRAM_PROVIDER` | `log` |

## Calls made

| Purpose | Request |
|---|---|
| Verify on connect | `GET /{version}/me?fields=user_id,username,name` |
| Reply | `POST /{version}/{account_id}/messages` with `recipient.id` and `message.text` |

## Webhooks

Same endpoint and signature check as WhatsApp (`X-Hub-Signature-256` with the Instagram app secret).
Entries whose `id` is not the connected account are ignored.

| Meta | AutoWave |
|---|---|
| `messaging[].message` with `text` | `InboundMessage` (handle = the sender's Instagram-scoped id) |
| `message.attachments` without text | `InboundMessage` with a placeholder |
| `message.is_echo` (our own reply), `is_deleted` | ignored |
| `messaging[].read.mid` | `StatusUpdate` read |

## Limits

- Replies only within 24 hours of the contact's last message; Instagram has no templates, so outside the
  window the inbox cannot send.
- Instagram contacts have no phone number: a new DM creates a lead with a name only.
- Instagram is not an automation action yet (AW-053).
