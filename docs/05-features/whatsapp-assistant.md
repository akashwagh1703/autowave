# WhatsApp assistant

- **Status:** ✅ Step 1 (menu, information, enquiries, hand-over, settings), step 2 (booking,
  reservations, ordering and demo classes inside the chat), step 3 (AI answers to typed questions,
  opt-in) and photo cards (products, services and courses as a swipeable carousel).
- **Decision:** [ADR-021](../12-decisions/ADR-021-whatsapp-assistant.md) (builds on ADR-018, amends ADR-019)
- **Last updated:** 2026-10-12

## Purpose

When a customer writes to the business on WhatsApp, the assistant answers straight away with buttons
for what the business offers, so the customer can book, reserve a table, order, see services and
prices, offers, classes, rates, timings and location, ask a question, or reach a person, at any hour.
Bookings and orders taken in the chat follow the same rules and statuses as the website. It only
answers messages the customer sends; it never starts a conversation.

## User flow

1. Customer: "Hi". Assistant: the welcome (with the website's main photo when there is one) and up to
   three buttons, for example **Book now**, **Services & prices**, **More options**.
2. **More options** opens a list of everything switched on (up to 10 items).
3. Each item answers from live data:
   - **Book an appointment / Book a slot:** the booking flow below.
   - **Reserve a table** (restaurants and cafés): the reservation flow below.
   - **Order online:** the ordering flow below.
   - **Services & prices:** services (by category when there are many), then a service's price,
     duration and description with **Book this** (starts booking that service), **Ask about this** and
     **Main menu**.
   - **Rates** (turfs and other rentals without services): hourly rate per resource.
   - **Courses** (coaching): courses, then fees, duration and batches with **Free demo class**.
   - **Offers** and **Common questions:** from the website's offers and FAQ sections.
   - **Timings & location:** address, opening hours, phone, e-mail, map link and website.
   - **Talk to a person:** hands over to the team.
4. **Ask us / Ask about this:** the customer types their question; it goes to the team (see hand-over),
   and the lead's interest is noted. With AI answers on, AI answers it first (below).

Customers can also type: a number picks from the last options offered, and short messages such as
"price", "timings", "offers", "book" or "talk to someone" open the matching item. "menu", "hi" or
"back" always goes back to the start.

### Photo cards

Products (ordering), services (**Services & prices** and booking) and courses come as swipeable cards,
each with its photo, name and price and two buttons, when:

- there are 2 to 10 to show (a category with up to 10 items; more than 10 stay a list with **More**),
- at least one of them has its own JPG or PNG photo (on its product, service or course page). Items
  without one show the business logo, else the website's main photo; with neither, the list is used.

| Where | Buttons on each card |
|---|---|
| Ordering | **Add to cart**, **Details** |
| Services & prices | **Details**, **Book this** (when booking is on) |
| Booking | **Book this**, **Ask about this** |
| Courses | **Details**, **Free demo class** |

A typed number picks the card in that position. Channels without carousels get the names as numbered
lines.

### AI answers to typed questions (optional)

With **Answer typed questions with AI** on (and AI available for the business), a typed message the
menu does not understand is answered by AI, for example "Do you have parking?" or "Is the facial
suitable for sensitive skin?". So is a question typed after **Ask us**, and a first message that is a
question ("How much is a haircut?"), which gets a short greeting too.

- The answer uses only the business's own information: services and prices, products, opening hours,
  address and contact details, website, turf rates, courses and batches, offers, common questions, and
  the owner's notes in Settings → AI.
- It comes with buttons: the matching option (for example **Book now** or **Timings & location**),
  **Talk to us** and **Main menu**, and the footer "Automatic answer".
- It never confirms a booking, a time, an order, a payment or a discount; for those it points to the
  buttons. It never asks for payment details.
- When AI is not sure (the information is not there, a complaint, a change to an existing booking), the
  customer gets "We have passed this to our team" and the chat is handed over as usual, with an e-mail
  to the owners.
- When AI is not available (switched off, monthly limit reached, provider down), the assistant replies
  as it would without AI.
- Taps, menu words, typed numbers and answers inside a booking or order never go to AI.

### Booking in the chat

1. **Service** (when the business uses services; by category when there are more than 10).
2. **Who / which one**, when more than one staff member or resource offers it ("Any available" first
   when the owner allows it). Turfs without services show each turf with its hourly rate.
3. **Day:** days with at least one free time ("Today", "Tomorrow", "Wed 8 Oct"), 9 at a time with
   **Later dates**.
4. **Time:** up to 10 times straight away, else **Morning / Afternoon / Evening** first.
5. **Please check your booking:** service and duration, staff, day and time, price, name, with
   **Confirm booking**, **Change time** and **Cancel**.
6. Booked with source **WhatsApp**: confirmed straight away when the owner auto-confirms online
   bookings, otherwise pending ("We will confirm it here shortly").

### Reserving a table

Day → time → **How many people** (1–9, or **10 or more** and type the number, up to the largest group
allowed) → confirm. The team assigns the table, as for website reservations.

### Ordering

1. Products (by category when there are many; "Not available now" when sold out). Tapping one shows
   the photo, price and description with **Add to cart**.
2. **How many** (up to the stock, the per-item limit and 10).
3. The cart (**Checkout**, **Add more**, **View cart** / **Empty cart**).
4. **Pickup or delivery** (only the methods switched on; delivery shows the fee). Delivery asks for
   the address, or offers the customer's saved address.
5. **Please check your order:** items, delivery fee, total, pickup or address, name → **Place order**.
   Prices, stock and minimum order are checked again on the server. Coupons are website-only for now.

### Free demo class (coaching)

From a course: **Free demo class** → batch (when more than one) → a day the batch meets in the next two
weeks (at least 2 hours ahead) → confirm. The demo is scheduled on the contact's enquiry at the batch's
start time (notes "Booked on WhatsApp") and the owners are e-mailed. Without a batch timetable or an
open enquiry, **Free demo class** asks the customer to type their request for the team instead.

### Names, double taps and changes

- The booking is made under the name the customer typed, else the customer's or enquiry's name, else
  the WhatsApp profile name. If none is usable the assistant asks "What name should we put this
  under?". The phone is the WhatsApp number. The chat is then linked to that customer in the inbox.
- Tapping a confirm button twice does not book twice. Older buttons still work, and everything is
  checked again when tapped: a time that was taken meanwhile shows the free times again.
- While the assistant waits for a typed answer (name, address, number of guests), "menu" or any button
  leaves the flow. Typing "cancel" opts the contact out of messages (STOP keywords), so flows offer a
  **Cancel** button instead.

## Rules

- Off by default. WhatsApp only. Needs the messaging module.
- Replies only inside WhatsApp's 24-hour window, so it never uses templates.
- Never answers: opted-out contacts, STOP/START messages, messages older than 15 minutes (delayed
  webhooks), more than 8 replies per minute in one conversation. When a customer sends several messages
  quickly, only the newest is answered.
- After 30 minutes of silence the conversation starts again from the welcome (and a cart is emptied).
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

### Owner e-mails for chat bookings

Bookings, reservations and orders made in the chat e-mail the owners like website ones ("booked on
WhatsApp", with a link to the record); demo classes too. Owner e-mail alerts in Settings → Messaging
switch them off.

## Settings (Settings → WhatsApp assistant, `settings.view` / `settings.update`)

- **Reply to WhatsApp messages automatically** (on/off).
- **Welcome message:** `{name}` (customer's first name) and `{business}`; empty uses the default.
  **Show your website's main photo** (hero photo, else logo; JPEG or PNG).
- **Menu:** each item can be switched off. Items the business does not have are shown greyed with the
  reason (for example "Turn on online ordering in Order settings…"). **Talk to a person** is always offered.
- **Answer typed questions with AI** (off by default; shown when the business has the AI module). If AI
  is switched off or over its monthly limit, the page says so with a link to Settings → AI. Answers
  count towards the monthly AI usage (feature "WhatsApp answers").
- **When your team takes over:** pause hours and team e-mail alerts.
- A live preview of the welcome. A warning lists active automations that also send WhatsApp messages
  when a lead is created or a message arrives (for example "Welcome new leads on WhatsApp"), because the
  customer would get two replies.

Booking, reservation and ordering rules come from the existing online settings (Booking settings,
Reservations, Order settings), the same as the website.

Stored in the `whatsapp_assistant` tenant setting; defaults in `config/chatbot.php`. Changes are audited
(`chatbot.settings_updated`).

## Inbox and records

Assistant replies show the sender **Assistant**, the photo, and the buttons or list items offered as
chips; photo cards show as a row of small cards. AI answers show the footer "Automatic answer". A customer's tap shows as **Tapped an option**.
The timeline shows "via WhatsApp assistant".
Appointments, orders and reservations made in the chat show the source **WhatsApp** in their lists and
timelines ("… on WhatsApp").

## How it works

- `ConversationMessageReceived` → `QueueChatbotReply` → `ReplyWithChatbot` (messaging queue) →
  `ChatbotEngine::respond()` → `MessagingService::queue()` with `assistant = true`.
- `ChatbotContent` reads the business data (services, courses, rates, website sections, contact
  details, online booking and ordering switches). `ChatbotSession` keeps each conversation's place.
- Flows (`App\Domain\Chatbot\Flows`) build each step; option ids carry the choices so far
  (`aw.bk.at.{service}.{resource}.{timestamp}`). The final step calls `OnlineBooking`,
  `OnlineReservations` or `OnlineShop` with source `whatsapp`, or `ScheduleDemo`, inside the reply's
  transaction.
- AI answers: the engine returns `['ai' => …]`; `ReplyWithChatbot` commits, asks AI through
  `ChatbotAI` → `AIService::answerCustomer()` (prompt `resources/prompts/whatsapp.md`, feature `chatbot`)
  with no lock held, then checks the chat again and calls `ChatbotEngine::answered()`.
- Buttons, lists, photos and cards are stored in `outbound_messages.interactive` and sent as WhatsApp
  interactive messages; providers without buttons get numbered text. Cards (`Interactive::carousel()`,
  kind `carousel`) go out as an interactive `carousel` with quick-reply buttons; their photos are sent
  by link (`APP_URL` + the media URL), so storage must be reachable from the internet. Taps come back
  as ordinary button replies.

## Database

- `chatbot_sessions` (tenant-owned, one per conversation).
- `outbound_messages.interactive` (jsonb) and `outbound_messages.assistant` (boolean).
- `services.image_media_id` and `courses.image_media_id` (nullable, from 2026-10-12), like
  `products.image_media_id`: the card photos.
- Source `whatsapp` on `appointments`, `orders` and `reservations` (the reservations check constraint
  allows it from 2026-10-11).

See [schema](../03-database/schema.md).

## Local testing

```bash
php artisan messaging:simulate-inbound abc-salon 9876543210 "Hi" --name="Priya"
php artisan messaging:simulate-inbound abc-salon 9876543210 "More options" --reply=aw.menu
php artisan messaging:simulate-inbound abc-salon 9876543210 "Book now" --reply=aw.book
php artisan messaging:simulate-inbound abc-salon 9876543210 "Order online" --reply=aw.order
```

`--reply` sends a button or list tap with that option id; the reply in the inbox shows the next option
ids to use. Turn the assistant on first in Settings → WhatsApp assistant.

## Testing

- `tests/Feature/Chatbot/WhatsAppAssistantTest.php`: off by default, welcome buttons, Cloud API
  payloads and tap ids, the photo uploaded once, long titles cut, services, typed numbers and keywords,
  question hand-over with alert and pause, "Talk to a person" and resume, staff reply pause, misses,
  STOP / opted-out / old messages, offers and FAQ, courses and demo, simulate command and inbox,
  settings and permissions, overlapping automations, tenant isolation.
- `tests/Feature/Chatbot/WhatsAppAssistantFlowsTest.php`: booking end to end (source, status, customer
  link, owner e-mail, double tap), auto-confirm, a time taken meanwhile, asking for the name, leaving a
  flow, another business's ids, table reservation with a large group, ordering for delivery and pickup,
  free demo class.
- `tests/Feature/Chatbot/WhatsAppAssistantAiTest.php`: off by default, a grounded answer with buttons
  and footer (prompt facts and rules, metering, inbox), first-message question with greeting, taps and
  keywords never sent to AI, not sure → hand-over and alert, "Ask us" answered by AI, fallback when the
  provider fails or the cap is reached, the setting and AI status.
- `tests/Feature/Chatbot/WhatsAppAssistantCardsTest.php`: product cards through the Cloud API (photo
  link, logo for items without a photo, inbox cards, typed number, tap), when the list stays, service
  cards in services and booking, course cards with the demo button, card limits and numbered fallback,
  uploading and removing service and course photos.

With `AI_PROVIDER=fake` (local, no key) AI answers are labelled sample text.

## Known limitations

- AI answers cannot look up a customer's own bookings or orders; those questions go to the team.
- Coupons, online payment and rescheduling or cancelling an existing booking in the chat are not
  offered yet; the customer can ask the team.
- Instagram DMs are not answered by the assistant.
- Menu labels are in English only.
