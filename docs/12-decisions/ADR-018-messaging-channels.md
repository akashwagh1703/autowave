# ADR-018: Messaging — per-tenant Meta channels, a normalised inbox, compliance in MessagingService

- **Status:** Accepted
- **Date:** 2026-10-02

## Context

Phase 8 connects real messaging channels (master prompt §43–44, §111). Phase 5 (ADR-015) already built the
outbound half: `MessagingService::queue()` stores an idempotent `outbound_messages` row and the `messaging`
queue delivers it through a provider. WhatsApp used the simulated `log` provider.

Decisions taken with the product owner for this phase:

- **Meta WhatsApp Cloud API directly**, no BSP (Twilio, Gupshup…) in between.
- **Manual connection**: the owner pastes the phone number id, WhatsApp Business Account id, access token
  and app secret from their own Meta app. Embedded Signup comes later.
- **Instagram DMs** are received and answered in the same inbox.
- **A full inbox**: one conversation per contact and channel, replies, assignment, open/closed, unread
  counts. Unknown senders become leads; messages appear on the lead and customer timeline.
- **Compliance**: approved templates (synced from Meta) for automations and for messages outside the
  24-hour window, STOP/START opt-out, and per-business quiet hours that delay automation messages.

Constraints:

- Provider payloads must not leak into the core (§44). Business code never calls a provider (§43).
- Webhooks arrive unauthenticated from the internet, may be retried and may arrive out of order.
- Tokens are secrets: stored encrypted, never sent to the browser, never logged.
- The shared dev database is PostgreSQL 11 (AW-006): a composite FK cannot null one column on delete.

## Decision

1. **Tables** (tenant-owned, composite FKs `(id, tenant_id)` as in ADR-013):
   - `messaging_channels`: one row per tenant and channel (`whatsapp`, `instagram`). It holds:
     - `external_id` (phone number id or Instagram account id) and `business_account_id` (WABA id);
     - `credentials`, an `encrypted:array` cast (`access_token`, `app_secret`, `verify_token`);
     - `webhook_key`, a random 40-character key that is unique across tenants.

     Disconnecting clears the credentials but keeps the row, so the webhook URL and verify token pasted
     into Meta stay valid on reconnect. `unique (channel, external_id)` stops two tenants from claiming
     the same number.
   - `conversations`: one per `(tenant, channel, contact_handle)`. The handle is the normalised phone
     (`+91…`) for WhatsApp and the Instagram-scoped user id for Instagram. The row holds the linked lead
     and customer, the assignee, open/closed, `unread_count`, the last message preview and times,
     `last_inbound_at` (for the 24-hour window) and `opted_out_at`.
   - `conversation_messages`: the thread. Inbound rows carry the provider message id, which is unique per
     `(tenant, channel)`; this makes webhook retries harmless. Outbound rows point at their
     `outbound_messages` row, and the delivery status is read from there.
   - `message_templates`: approved-or-not WhatsApp templates synced from Meta. Each has a name, language,
     category, status, body text and variable count, with `unique (tenant_id, channel, name, language)`.
   - `messaging_webhook_calls`: every verified webhook body, processed on the queue and pruned after
     `messaging.webhooks.retention_days`.
   - `outbound_messages` gains `conversation_id`, `sent_by_user_id`, `template` (jsonb),
     `scheduled_for`, `delivered_at` and `read_at`. `MessageStatus` gains `delivered` and `read`.
2. **Channel resolution per tenant.** `ChannelResolver` picks the provider when a message is queued:
   - `meta_whatsapp` when the tenant has a connected WhatsApp channel;
   - `instagram` when it has a connected Instagram channel;
   - otherwise the configured fallback (`log`, simulated).

   Email keeps `mail`. The provider name and the `simulated` flag are stored on the message, so what the
   timeline says is what happened.
3. **Providers** implement `MessagingProvider::send()` and talk to Meta only through `MetaGraphClient`
   (Graph API version, base URLs and timeouts come from config). The providers are:
   - `MetaWhatsAppProvider`: text or template messages. The outbound message id travels as
     `biz_opaque_callback_data`.
   - `InstagramProvider`: text replies through the Instagram API with Instagram Login.

   Meta errors that retrying cannot fix (4xx: window closed, invalid number, bad template) throw
   `PermanentDeliveryFailure`, and the job fails the message at once instead of retrying.
4. **Webhook pipeline** (§44): `GET|POST /webhooks/meta/{webhookKey}` on the app host. These routes have
   no session, no CSRF and a rate limit.
   - GET echoes `hub.challenge` when `hub.verify_token` matches the channel's token.
   - POST checks `X-Hub-Signature-256` (HMAC-SHA256 of the raw body with the channel's app secret,
     compared with `hash_equals`). Unknown keys return 404 and bad signatures 403; neither is stored.
   - A verified body is stored as a `messaging_webhook_calls` row and answered 200 at once.
     `ProcessWebhookCall` then runs on the `messaging` queue, inside the call's tenant.
   - `MetaWebhookNormalizer` turns the payload into provider-neutral `InboundMessage` and `StatusUpdate`
     objects. Entries for another phone number or account id are ignored. Nothing downstream sees the
     Meta shape.
   - `messaging:dispatch-pending` also re-queues calls left pending by a queue outage.
5. **Inbound messages** (`ReceiveInboundMessage`), in one transaction:
   - find or create the conversation;
   - insert the message (a duplicate provider id ends processing);
   - update the preview, unread count and `last_inbound_at`, and reopen a closed conversation;
   - apply opt-out keywords.

   A new conversation is linked in this order:
   1. a customer with the same phone;
   2. else an open lead with the same phone;
   3. else, when the Leads module is on, a new lead with source `whatsapp` or `instagram`.

   Linked messages are written to the timeline (activity type = channel, `direction: inbound`).
   `ConversationMessageReceived` is dispatched after commit for later automation triggers.
6. **Status updates** only move forward: `sent → delivered → read`. `failed` can arrive at any time and
   is logged on the automation run. The message is found by `(tenant_id, provider_message_id)`.
7. **Every outbound WhatsApp and Instagram message is part of a conversation.** `MessagingService::queue()`
   finds or creates the conversation for the recipient and appends an outbound row in the same
   transaction, so automation messages, inbox replies and templates form one thread.
8. **Compliance lives in one place**, `MessagingCompliance`. The automation step and the inbox both ask
   it before queueing:
   - **24-hour window.** With a real provider, free text needs an inbound message within
     `window_hours`. Outside the window, WhatsApp needs an approved template; Instagram cannot be
     messaged at all. Simulated channels skip the check.
   - **Opt-out.** An inbound message that matches `opt_out_keywords` (STOP, UNSUBSCRIBE…) sets
     `opted_out_at`; one that matches `opt_in_keywords` (START…) clears it. Staff can also toggle it.
     An opted-out contact receives no automation messages and no templates: the step is skipped with a
     reason. Manual replies inside the window stay allowed, because the customer started that
     conversation.
   - **Quiet hours** (tenant setting, off by default, tenant timezone, may cross midnight). An automation
     message queued inside quiet hours gets `scheduled_for` = the end of quiet hours and is dispatched
     with that delay. `deliver()` never sends before `scheduled_for`. Replies and team notifications
     are not delayed.
9. **Templates.** "Sync templates" reads `GET /{waba_id}/message_templates`. Every page is upserted and
   templates Meta no longer returns are deleted. The automation WhatsApp action gets a *template* mode:
   template name and language, plus one parameter per `{{n}}`. Parameters may use automation variables.
   The rendered body is stored on the message for the timeline.
10. **Permissions.** A new group `conversations`: `view`, `reply` and `assign`.
    - Manager: all three.
    - Receptionist and Sales Executive: `view` and `reply`.
    - Existing tenants get the group through `TenantBackfillSeeder::NEW_PERMISSION_GROUPS`.

    Connecting channels and quiet hours use `settings.update`. Every route is behind `module:messaging`.
    Credentials and the webhook key are write-only in the UI: the browser sees whether a token is set,
    never its value. The callback URL and verify token are shown only to `settings.update` users.
11. **Email sender.** The `messaging` tenant setting also holds the email `from_name` and `reply_to`. The
    from address stays the platform's `MAIL_FROM_ADDRESS` (AW-029 narrowed).

## Alternatives

- **A BSP (Twilio, Gupshup, Interakt).** This gives easier onboarding but adds a middleman, extra fees and
  another payload shape. The provider interface keeps this possible later.
- **One platform Meta app with Embedded Signup.** This is the right long-term flow, but it needs Meta app
  review and Tech Provider onboarding. Manual connection works today with the business's own app.
- **Process webhooks inside the request.** Meta retries slow endpoints, and processing may create leads and
  run automations. Storing the call and processing on the queue keeps the response fast and replayable.
- **Inbound idempotency by payload hash.** Meta re-sends the same message id with a different envelope.
  The provider message id is the stable key.
- **Store the thread only in `activities`.** The timeline is a summary across lead and customer; the inbox
  needs per-conversation ordering, unread counts and delivery ticks. Both are written: activities for
  history, `conversation_messages` for the thread.

## Consequences

- Every outbound path must go through `MessagingService`, and every compliance decision through
  `MessagingCompliance`. A new sender that skips them would break opt-out and quiet hours.
- Webhook processing is at-least-once. Inbound messages are idempotent by provider id; status updates are
  idempotent because they only move forward.
- Only text is handled. Media, reactions and interactive messages are shown as a placeholder
  ("[Image]"), and their content is not downloaded (AW-051).
- Everyone with `conversations.view` sees every conversation. Per-assignee visibility comes later (AW-052).
- Instagram contacts have no phone number, so Instagram leads carry only a name until staff add one.
