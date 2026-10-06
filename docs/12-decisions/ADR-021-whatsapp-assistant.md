# ADR-021: WhatsApp assistant

- **Status:** Accepted
- **Date:** 2026-10-06
- **Builds on:** ADR-015 (automation), ADR-018 (messaging channels)
- **Amends:** ADR-019 (AI features), see "AI answers" below

## Context

Owners want a customer who writes "hi" on WhatsApp to be answered straight away, with buttons for what
the business offers: booking, ordering, services and prices, classes, turf rates, offers, common
questions, timings and location, the website, and a way to reach a person. It must only ever answer a
message the customer just sent, never start a conversation.

Decisions taken with the product owner:

- **Hybrid:** a rule-based menu handles choices, bookings and orders; AI answers typed questions later
  (step 3).
- Bookings and orders taken in chat follow the **same rules and statuses as the website**.
- **Hand-over:** the assistant goes quiet when staff reply or the customer taps "Talk to a person", and
  starts again after an owner-set number of hours (default 12).
- Built in steps: **step 1** (this ADR) is the menu, information, enquiries, hand-over and settings.
  Step 2 is booking, reservation, ordering and course demos inside the chat. Step 3 is AI answers.

## Decision

### Trigger and guards

- `ConversationMessageReceived` → `QueueChatbotReply` (listener) → `ReplyWithChatbot` (queued job,
  after commit, messaging queue). WhatsApp only; Instagram keeps today's behaviour.
- The listener skips: opted-out contacts, STOP/START keywords, the messaging module off, the assistant
  off (tenant setting `whatsapp_assistant`, **off by default**).
- The job stays quiet when the message is older than `chatbot.max_age_minutes` (delayed webhook), when
  a newer inbound message exists (its own job answers), past `chatbot.rate_limit` replies per minute per
  conversation, when the 24-hour window is closed, or while the session is paused.
- Because it only answers inside the 24-hour window it never needs templates.

### Sending

- Replies go through `MessagingService::queue()` like every other message, with purpose `system` (they
  do not clear unread counts and ignore quiet hours), `assistant = true`, and idempotency key
  `assistant:{messageId}` so a retried job cannot answer twice.
- New provider-neutral payload `outbound_messages.interactive` (`App\Domain\Messaging\Support\Interactive`):
  - `buttons`: up to 3 reply buttons (title ≤ 20 characters), optional header image;
  - `list`: up to 10 rows (title ≤ 24, description ≤ 72) behind one button;
  - `image`: an image with the text as caption.
  Text that is too long is cut with "…".
- `MetaWhatsAppProvider` sends these as Cloud API interactive messages. Images (the website hero photo,
  else the logo, JPEG or PNG) are uploaded once and the media id is cached for 25 days. Providers that
  cannot send buttons (Instagram, the log provider) send the text with numbered options.
- Inbound taps arrive as `meta.reply_id` (`button_reply.id`, `list_reply.id`, or template button
  payload). Option ids are `aw.<item>[.<id>]`, e.g. `aw.menu`, `aw.svc.12`, `aw.ask.book`.

### Conversation state

- `chatbot_sessions`: one row per conversation (tenant-owned, composite foreign key to conversations),
  with state `menu` or `question`, data (last options offered, interest, who paused), misses,
  `last_reply_at` and `paused_until`. Rows are locked while a reply is worked out.
- After `chatbot.session_minutes` of silence a conversation starts again from the welcome.
- A typed number picks from the last options offered; short messages are matched against keyword lists
  (`config/chatbot.php`), on whole words only.

### What the assistant offers

The menu is built from live business data, so it never goes stale. Each item shows only when the
business has it and the owner has not switched it off:

| Item | Shown when | Reply |
|---|---|---|
| Book / Reserve / Order | online booking, reservations or ordering is switched on | link to the website section, "Ask us", "Main menu" |
| Services & prices | active services | categories or services, then details |
| Rates | turf-style resources with an hourly rate | rate list |
| Courses | education engine with active courses | courses, details, "Free demo class" enquiry |
| Offers, Common questions | enabled website sections | list, answers |
| Timings & location | always | address, hours, phone, e-mail, map, website |
| Talk to a person | always | hand-over |

Without a live website, online actions fall back to "ask us" (an enquiry for the team). In step 2 these
become in-chat flows that use the same domain actions as the website.

### Hand-over

- Staff replying from the inbox (`sent_by_user_id`) keeps the assistant quiet for `pause_hours`.
- "Talk to a person", a typed question after "Ask us", or `chatbot.misses_before_handover` messages in a
  row that the assistant did not understand pause it (`paused_until`), note the interest on the lead
  (`UpdateLead`, timeline "via assistant") and e-mail the owners (`WebsiteActivityAlert`, kind
  `whatsapp_handover`, respecting `MessagingSettings::ownerAlerts()`) unless the owner turned alerts off.
- A customer who paused it themselves can type "menu" or tap an option to start again; a pause caused
  by staff only ends with time.

### Settings

Settings → WhatsApp assistant (`settings.view` / `settings.update`, messaging module): on/off, welcome
text (`{name}`, `{business}`), welcome photo, menu items, pause hours (1–72), team alert. A live
preview mirrors the reply. Active automations that also send WhatsApp on `lead.created` or
`message.received` are listed with a warning, because the customer would get two replies; they are not
changed automatically. Changes are audited (`chatbot.settings_updated`).

### AI answers (amends ADR-019)

ADR-019 rejected automatic replies by AI for V1. This ADR keeps that for step 1: every assistant reply
is built from rules and stored business data. Step 3 will allow AI to answer **typed questions only**,
grounded in the same facts as ADR-019, metered against the monthly cap, never confirming bookings,
prices not listed, payments or order status, and handing over when unsure. That step gets its own
section here when built.

## Alternatives

- **An automation template instead of an engine** — automations run steps for one event; a menu needs
  per-conversation state, typed-number choices and pauses, which would bend the automation model.
- **AI-only bot** — rejected: cost per message, and AI must not confirm bookings or prices.
- **WhatsApp Flows** — richer forms, but needs Meta approval per business and is not available on every
  client; reply buttons and lists work everywhere.

## Consequences

- Customers get an answer within seconds at any hour; the team sees every exchange in the inbox, with
  the options offered and "Tapped an option" markers.
- Businesses that also run the "Welcome new leads on WhatsApp" automation must pause one of the two.
- Step 2 adds a `whatsapp` source to bookings, reservations and orders; step 3 extends ADR-019.
