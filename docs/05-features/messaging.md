# Messaging

- **Status:** ✅ Phase 8. WhatsApp (Meta Cloud API) and Instagram DMs in a shared inbox, approved
  templates, opt-out and quiet hours. Email is outbound only.
- **Decision:** [ADR-018](../12-decisions/ADR-018-messaging-channels.md) (builds on ADR-015)
- **Issue(s):** AW-029, AW-031, AW-050 – AW-054
- **Last updated:** 2026-10-02

## Purpose

One way to talk to customers on the channels they use (master prompt §43–44):

- Business code sends through `MessagingService`, never through a provider.
- Messages from customers arrive through Meta webhooks and land in one inbox, linked to the lead or
  customer, and appear on their timeline.
- WhatsApp's rules (24-hour window, approved templates, opt-out) are enforced in one place,
  `MessagingCompliance`.

## Channels and providers (`config/messaging.php`)

| Channel | Connected provider | Fallback (env) | Conversations | Templates |
|---|---|---|---|---|
| `whatsapp` | `meta_whatsapp` (Settings → Messaging) | `log`, simulated (`MESSAGING_WHATSAPP_PROVIDER`) | yes | yes |
| `instagram` | `instagram` (Settings → Messaging) | `log`, simulated (`MESSAGING_INSTAGRAM_PROVIDER`) | yes | no |
| `email` | — | `mail` (`MESSAGING_EMAIL_PROVIDER`) | no | no |

`ChannelResolver` picks the provider per tenant when a message is queued. The provider name and the
`simulated` flag are stored on the message, so the timeline and inbox show what really happened.

## Connecting a channel (Settings → Messaging, `settings.update`)

The owner uses their own Meta app (manual connection; Embedded Signup is AW-050). See
[docs/06-integrations/meta-whatsapp.md](../06-integrations/meta-whatsapp.md) and
[instagram.md](../06-integrations/instagram.md) for where each value comes from.

1. **WhatsApp:** phone number id, WhatsApp Business Account id, access token and app secret.
   **Instagram:** access token and app secret (the account id is read from the token).
2. AutoWave checks the details against Meta before saving. A rejected token shows Meta's reason on the
   token field and nothing is saved.
3. The token and secret are stored encrypted. The page only shows "Saved — leave blank to keep"; they are
   never sent back to the browser. Leaving them blank on a later save keeps the stored values.
4. The page shows the **callback URL** and **verify token** to paste into the Meta app's webhook settings
   (only to users with `settings.update`). They stay the same across disconnect and reconnect.
5. A number or Instagram account can be connected to one AutoWave business only.

**Disconnect** forgets the token, secret and ids; messages fall back to the simulated provider.

## Inbox (`/inbox`, `conversations.view`)

- One conversation per contact and channel. Tabs: Open, Mine, Unassigned, Closed, All; filter by
  channel; search by name or number. The page polls every `inbox.poll_seconds` (10 s).
- Opening a conversation marks it read. The nav shows the number of open conversations with unread
  messages.
- **Reply** (`conversations.reply`): Enter sends. Each send carries a client id, so a double submit sends
  once. With a real provider, free text is only allowed within 24 hours of the contact's last message;
  outside it the composer offers **Send template** (WhatsApp) instead.
- **Attach a file** (WhatsApp only): the paperclip adds one photo, video, voice note or document; the text
  becomes its caption and may be empty. See [Files in conversations](#files-in-conversations).
- **Assign** (`conversations.assign`) to an active member who can see the inbox. **Close / Reopen**; a new
  message from the contact reopens a closed conversation.
- **Opt-out:** staff can mark a contact opted out or remove it.
- **Chat** buttons on customer and lead pages open (or create) the WhatsApp conversation for their phone.

## Inbound messages

Webhook → signature check → stored `messaging_webhook_calls` row → `ProcessWebhookCall` (queue) →
`MetaWebhookNormalizer` → `ReceiveInboundMessage`:

1. Find or create the conversation; store the message once per provider message id (Meta retries are
   harmless).
2. Update the preview, unread count and `last_inbound_at`; reopen it if closed.
3. `STOP`, `UNSUBSCRIBE`, … (whole message, any case) opts the contact out; `START`, … opts them back in
   (`messaging.opt_out_keywords` / `opt_in_keywords`).
4. A new conversation is linked to a customer with the same phone, else an open lead with the same
   phone, else a **new lead** (source WhatsApp or Instagram) when the Leads module is on.
5. The message goes on the lead or customer timeline ("Priya sent a WhatsApp message").

A photo, video, voice note, document or sticker is stored as `[Image] caption` (the caption is kept) and
its file is downloaded afterwards (below). Locations, contact cards and other types stay a placeholder
such as `[Location]`. Reactions are ignored.

## Files in conversations

Files use the `conversation_message` owner in `config/files.php` (one file per message, private, folder
`inbox/`, see [file security](../04-security/file-security.md)):

| Kind | Types | Max |
|---|---|---|
| Image | JPG, PNG, WebP | 5 MB |
| Document | PDF, Word, Excel, JPG, PNG | 10 MB |
| Video | MP4, WebM | 16 MB (WhatsApp's limit) |
| Voice note | OGG, MP3, M4A, AAC, AMR | 16 MB |

**Received:** `ReceiveInboundMessage` sets `meta.media = {status: pending, id | url, filename}` and
queues `DownloadInboundMedia` on the `media` queue (`MESSAGING_MEDIA_QUEUE`) after commit. The job:

1. WhatsApp: asks Graph for the media URL with the channel's token, then downloads it with the token.
   Instagram: downloads the signed CDN link from the webhook, without a token.
2. Downloads only over HTTPS from Meta's media hosts (`messaging.meta.media_hosts`, also checked on
   redirects) and stops at 16 MB (declared size, `Content-Length` and the body).
3. Checks the content like any upload (type from the bytes, size per kind, storage allowance) and
   attaches the file to the message: `meta.media.status = stored`.
4. A refused or failed file is `skipped` with the reason in `meta.media.error`; the bubble shows "File not
   saved: …" and keeps the placeholder text. Temporary errors retry (`messaging.tries`).

**Sent:** `ReplyToConversation::text()` with a file queues the message and attaches the file to its
conversation entry in one transaction, so `SendOutboundMessage` always finds it. `MetaWhatsAppProvider`
uploads the file to `/{phone-number-id}/media` and sends it by media id: JPG/PNG as image, MP4 as video,
audio as a voice note (or as a document when it has a caption, which voice notes cannot carry), anything
else as a document with its file name. Instagram refuses files ("Files can only be sent on WhatsApp for
now."): sending one needs a public URL.

Files open through `/attachments/{id}` after the `conversations.view` check; uploading needs
`conversations.reply`. The preview shows `[Document] caption` for both directions.

## Outbound messages

- `MessagingService::queue([...])` is the only entry point: channel, recipient, body and an
  **idempotency key**; optionally purpose, template, lead, customer, member, conversation, sender and
  automation run. The same key returns the first message.
- Every WhatsApp and Instagram message is appended to the contact's conversation in the same
  transaction, whether it came from an automation, the inbox or the system.
- **Delivery:** `SendOutboundMessage` on the `messaging` queue claims the message (`queued → sending`),
  calls the provider and marks it `sent` with the provider id. Then the timeline and the automation run
  log are updated.
- **Receipts:** Meta's status webhooks move the message forward only: `sent → delivered → read`. `failed`
  can arrive at any time and stores Meta's reason. The inbox shows ticks.
- **Failures:** temporary errors retry (3 tries, backoff 60 s, 300 s). Errors that retrying cannot fix
  (closed window, invalid number, bad template) fail the message at once.
- **Recovery:** `messaging:dispatch-pending` (every minute) re-queues messages stuck in `queued` or
  `sending`, and webhook calls left `pending`.

## Compliance (`MessagingCompliance`)

| Rule | Applies to | Effect |
|---|---|---|
| 24-hour window | Free text on a connected WhatsApp or Instagram channel | Inbox: the reply is refused with the reason. Automation: the step is skipped ("Use an approved template in this step."). Simulated channels have no window. |
| Opt-out | Automation messages and templates | The step is skipped / the template is refused. Replies inside the window stay allowed: the customer started that conversation. |
| Quiet hours | Automation messages (all channels in `quiet_hours.channels`) | Queued with `scheduled_for` = the end of quiet hours in the tenant's timezone; the run log says why. Replies and team notifications are not delayed. Off by default. |

## Templates

- **Sync templates** (Settings → Messaging) reads every template of the WhatsApp Business Account. Only
  the body text and its `{{n}}` variable count are kept; templates Meta no longer returns are removed.
- Only `APPROVED` templates can be sent.
- **Automations:** the *Send WhatsApp message* action has two modes, free text or an approved template
  with one field per variable. Fields accept automation variables such as `{{lead.first_name}}`; an empty
  value is sent as `-` because Meta rejects empty parameters. If the template is no longer approved when
  the step runs, it is skipped with a reason.
- **Inbox:** *Send template* shows a preview and asks for every variable.

## Preferences (Settings → Messaging)

Stored in the `messaging` tenant setting:

- quiet hours (on/off, start, end; may cross midnight);
- email sender name and reply-to address. The from address stays the platform's `MAIL_FROM_ADDRESS`
  (AW-029).

## Database

`messaging_channels`, `conversations`, `conversation_messages`, `message_templates`,
`messaging_webhook_calls`, plus `outbound_messages` (see [schema](../03-database/schema.md)). All are
tenant-owned with composite foreign keys.

## Permissions

| Permission | Manager | Receptionist | Sales Executive | Staff / Accountant |
|---|---|---|---|---|
| `conversations.view` | ✓ | ✓ | ✓ | — |
| `conversations.reply` | ✓ | ✓ | ✓ | — |
| `conversations.assign` | ✓ | — | — | — |

Owners have everything. Connecting channels, syncing templates and preferences need `settings.update`.
Every route is behind `module:messaging`.

## Local testing without Meta

```bash
php artisan messaging:simulate-inbound abc-salon 9876543210 "Hi, do you have a slot today?" --name="Priya"
php artisan messaging:simulate-inbound abc-salon 5566778899 "Price?" --channel=instagram
```

The command runs the same pipeline as a webhook (not in production). `DemoMessagingSeeder` creates a demo
inbox for ABC Salon locally.

## Testing

`tests/Feature/Messaging/`:

- `MetaWebhookTest`: verification, signatures, lead and customer linking, idempotency, opt-out keywords,
  receipts, Instagram, module gating, recovery and pruning.
- `MessageDeliveryTest`: provider payloads (`Http::fake`), permanent failures, quiet hours, the window
  and template mode in automations, opt-out.
- `MessagingSettingsTest`: connection, write-only secrets, template sync, preferences.
- `InboxHttpTest`: inbox, replies, templates, assignment, permissions, chat buttons, backfill, simulate
  command.
- `MessagingIsolationTest`: cross-tenant access, templates, members, webhooks and composite FKs.
- `InboxAttachmentsTest`: received WhatsApp and Instagram files, refused types, oversized files and
  foreign hosts, WhatsApp replies with a file, Instagram refusal.

## Known Limitations

- Manual connection only; no Embedded Signup (AW-050).
- Files cannot be sent on Instagram; locations and contact cards stay placeholders (AW-051).
- Everyone with `conversations.view` sees every conversation (AW-052).
- No "message received" automation trigger; Instagram is not an automation action (AW-053).
- No inbound email (AW-054).
- A worker crash after Meta accepted a message can resend it (AW-031).
