# WhatsApp assistant

- **Status:** ✅ Step 1 (menu, information, enquiries, hand-over, settings). Step 2 (booking and ordering
  inside the chat) and step 3 (AI answers) are planned.
- **Decision:** [ADR-021](../12-decisions/ADR-021-whatsapp-assistant.md) (builds on ADR-018, amends ADR-019)
- **Last updated:** 2026-10-06

## Purpose

When a customer writes to the business on WhatsApp, the assistant answers straight away with buttons
for what the business offers, so the customer can see services and prices, offers, classes, rates,
timings and location, get the booking or order link, ask a question, or reach a person, at any hour.
It only answers messages the customer sends; it never starts a conversation.

## User flow

1. Customer: "Hi". Assistant: the welcome (with the website's main photo when there is one) and up to
   three buttons, for example **Book now**, **Services & prices**, **More options**.
2. **More options** opens a list of everything switched on (up to 10 items).
3. Each item answers from live data:
   - **Book an appointment / Reserve a table / Order online:** the website link straight to the booking,
     reservation or products section, plus **Ask us** and **Main menu**. Without a live website it asks
     the customer to type their request for the team.
   - **Services & prices:** services (by category when there are many), then a service's price,
     duration and description with **Book this**, **Ask about this** and **Main menu**.
   - **Rates** (turfs and other rentals without services): hourly rate per resource.
   - **Courses** (coaching): courses, then fees, duration and batches with **Free demo class**.
   - **Offers** and **Common questions:** from the website's offers and FAQ sections.
   - **Timings & location:** address, opening hours, phone, e-mail, map link and website.
   - **Talk to a person:** hands over to the team.
4. **Ask us / Ask about this / Free demo class:** the customer types their question; it goes to the team
   (see hand-over), and the lead's interest is noted.

Customers can also type: a number picks from the last options offered, and short messages such as
"price", "timings", "offers", "book" or "talk to someone" open the matching item. "menu" or "hi" always
goes back to the start.

## Rules

- Off by default. WhatsApp only. Needs the messaging module.
- Replies only inside WhatsApp's 24-hour window, so it never uses templates.
- Never answers: opted-out contacts, STOP/START messages, messages older than 15 minutes (delayed
  webhooks), more than 8 replies per minute in one conversation. When a customer sends several messages
  quickly, only the newest is answered.
- After 30 minutes of silence the conversation starts again from the welcome.
- Photos, voice notes and other files in the middle of a chat get no automatic reply; the team sees them
  in the inbox.

### Hand-over to the team

The assistant goes quiet for **pause hours** (owner setting, 1–72, default 12) when:

- someone on the team replies from the inbox;
- the customer taps **Talk to a person** or sends a question after **Ask us**;
- the assistant did not understand two messages in a row (after the first it offers "See options" and
  "Talk to us").

In the last two cases the owners get an e-mail with a link to the conversation (unless the owner turned
team alerts off, or owner e-mail alerts are off in Settings → Messaging). A customer who asked for a
person can type "menu" to use the assistant again; a pause because staff replied only ends with time.

## Settings (Settings → WhatsApp assistant, `settings.view` / `settings.update`)

- **Reply to WhatsApp messages automatically** (on/off).
- **Welcome message:** `{name}` (customer's first name) and `{business}`; empty uses the default.
  **Show your website's main photo** (hero photo, else logo; JPEG or PNG).
- **Menu:** each item can be switched off. Items the business does not have are shown greyed with the
  reason (for example "Turn on online ordering in Order settings…"). **Talk to a person** is always offered.
- **When your team takes over:** pause hours and team e-mail alerts.
- A live preview of the welcome. A warning lists active automations that also send WhatsApp messages
  when a lead is created or a message arrives (for example "Welcome new leads on WhatsApp"), because the
  customer would get two replies.

Stored in the `whatsapp_assistant` tenant setting; defaults in `config/chatbot.php`. Changes are audited
(`chatbot.settings_updated`).

## Inbox

Assistant replies show the sender **Assistant**, the photo, and the buttons or list items offered as
chips. A customer's tap shows as **Tapped an option**. The timeline shows "via WhatsApp assistant".

## How it works

- `ConversationMessageReceived` → `QueueChatbotReply` → `ReplyWithChatbot` (messaging queue) →
  `ChatbotEngine::respond()` → `MessagingService::queue()` with `assistant = true`.
- `ChatbotContent` reads the business data (services, courses, rates, website sections, contact
  details, online booking and ordering switches). `ChatbotSession` keeps each conversation's place.
- Buttons, lists and photos are stored in `outbound_messages.interactive` and sent as WhatsApp
  interactive messages; providers without buttons get numbered text.

## Database

- `chatbot_sessions` (tenant-owned, one per conversation).
- `outbound_messages.interactive` (jsonb) and `outbound_messages.assistant` (boolean).

See [schema](../03-database/schema.md).

## Local testing

```bash
php artisan messaging:simulate-inbound abc-salon 9876543210 "Hi" --name="Priya"
php artisan messaging:simulate-inbound abc-salon 9876543210 "More options" --reply=aw.menu
php artisan messaging:simulate-inbound abc-salon 9876543210 "Services & prices" --reply=aw.services
```

`--reply` sends a button or list tap with that option id. Turn the assistant on first in Settings →
WhatsApp assistant.

## Testing

`tests/Feature/Chatbot/WhatsAppAssistantTest.php`: off by default, welcome buttons, Cloud API payloads
and tap ids, the photo uploaded once, long titles cut, services and booking link, typed numbers and
keywords, question hand-over with alert and pause, "Talk to a person" and resume, staff reply pause,
misses, STOP / opted-out / old messages, offers and FAQ, courses and demo, simulate command and inbox,
settings and permissions, overlapping automations, tenant isolation.

## Known limitations

- Booking, reservations and orders open the website; taking them inside the chat is step 2.
- Typed questions go to the team; AI answers are step 3.
- Instagram DMs are not answered by the assistant.
- Menu labels are in English only.
