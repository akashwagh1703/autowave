# ADR-019: AI features (Phase 9)

- **Status:** Accepted
- **Date:** 2026-10-03
- **Builds on:** ADR-008 (AI provider abstraction), ADR-015 (automation), ADR-018 (messaging)

## Context

Phase 9 adds lead extraction, reply generation, summaries and an AI assistant (master prompt §40–42).
Decisions taken with the product owner:

- Provider: **OpenRouter only**, with one platform API key in `.env`.
- Features: inbox reply drafts, summaries (conversation, lead, customer), lead extraction from incoming
  messages, an owner assistant that answers from business data, writing help (website, automation and
  marketing text), and automation AI actions with a "message received" trigger (AW-053).
- **AI never sends anything by itself.** It drafts; a person presses Send.
- Extracted lead details **fill empty lead fields only**; everything else is a suggestion to accept.
- Every call is **metered per business**, with a configurable **monthly cap** and a Super Admin view.

## Decision

### Architecture

- Business code calls `App\Domain\AI\Services\AIService` (use cases: `suggestReply`,
  `summarizeConversation`, `summarizeRecord`, `extractLeadDetails`, `generateCopy`, `ask`). Nothing
  else talks to a provider.
- `AIService` builds prompts and hands a `ChatRequest` to `AIGateway`, which checks availability,
  calls the provider and records usage. Providers implement `AIProvider::chat()`:
  - `OpenRouterProvider`: OpenAI-compatible chat completions (`/chat/completions`), JSON mode for
    extraction, tool calls for the assistant, `usage.include` for the cost in USD.
  - `FakeProvider`: deterministic answers built from the request, no network. Used by tests and local
    development without a key. Its output is labelled as sample text.
- The provider is `config('ai.provider')` (`AI_PROVIDER`). Swapping or adding a provider is a new class
  plus a config entry.
- Prompt templates live in `resources/prompts/*.md` (plain `{{placeholder}}` substitution) so they can
  be reviewed and changed without touching code.

### Availability ("the platform works without AI")

AI is available for a business when all of these hold, otherwise every AI button is hidden or disabled
with the reason, and automations skip AI steps:

1. the `ai` module is enabled (catalogue module, on for every business type, backfilled once);
2. the business has not switched AI off (Settings → AI, tenant setting `ai.enabled`);
3. the provider is configured (OpenRouter needs `OPENROUTER_API_KEY`);
4. the business is under its monthly token cap.

Provider errors never break a request: the user sees "AI did not respond, try again", the call is
recorded as failed, and queued work retries.

### Grounding and business rules (§41)

- AI receives facts from the database: business profile, opening hours, active services with prices
  and durations, active products with prices and stock status, and optional "notes for AI" written by
  the owner. It is told to use only those facts and never to confirm bookings, availability, prices
  not listed, payments or order status.
- AI never writes price, stock, availability, appointment, payment or order data. Lead extraction may
  set lead name, email, interest and estimated value — lead fields that staff edit freely — and only
  when they are empty.

### Cost strategy (§42)

- Rules first: e-mail addresses are extracted by pattern without AI; messages that are too short, or
  only an opt-out keyword, are never sent to AI.
- Summaries are cached per record and reused until a new message or activity arrives.
- Automatic extraction is debounced per conversation (one unique job, `ai.extraction.delay_seconds`
  after the first message of a burst), reads each set of messages once (results are keyed by the
  newest message and enquiry ids), and stops after `ai.extraction.max_auto_runs` automatic runs per
  lead. It runs on the `ai` queue.
- Context is bounded: the last `ai.context.messages` messages, `ai.context.activities` timeline
  entries, message bodies truncated.

### Usage metering and cap

- `ai_usage`: one row per provider call — tenant, user, feature, provider, model, tokens, cost (USD,
  when the provider reports it), duration, status, error.
- The cap is in tokens per calendar month (UTC): `ai.limits.monthly_tokens`, overridable per business
  by a platform admin (tenant setting `ai_quota`, never editable by the business). The check runs
  before a call, so the last call of the month may overshoot slightly.
- Super Admin → AI usage: this month per business (requests, tokens, cost, cap, failures) and
  platform totals; the dashboard shows the monthly total (§48 "AI Usage").

### Assistant

- A chat on the Assistant page. The server is stateless: the browser sends the recent turns (bounded).
- The model may call read-only tools (`business_overview`, `appointments`, `leads`, `customers`,
  `orders`, `products`, `services`). Tools are offered only when the user holds the permission and the
  module/engine is on, run inside the tenant scope, and return names, dates, counts and amounts — never
  phone numbers or e-mail addresses. At most `ai.context.assistant_tool_rounds` tool rounds per
  question.

### Lead extraction

- Sources: the lead's conversations (inbound text) and website enquiry messages.
- Output fields: name, email, interest, budget (→ estimated value), preferred time, short summary.
- Empty lead fields are filled (`UpdateLead`, timeline entry "updated by AI"); a name that is only a
  phone number or the "WhatsApp user …" placeholder counts as empty. Other differing values are stored
  as suggestions (`ai_results`) that staff apply or dismiss on the lead page.
- Runs automatically after inbound messages (setting `ai.auto_extract`, default on), on demand, or as
  an automation action.

### Automations

- New subject `conversation` (entities conversation, lead, customer) and trigger `message.received`
  (not fired for opt-out/opt-in keywords). Fields: channel, assigned, message text. Variables: channel,
  message text.
- Actions: `ai_extract_lead` (fill lead details), `ai_draft_reply` (a draft waiting in the inbox — never
  sent), `ai_summarize` (summary added to the timeline as a note). They are skipped (not failed) when AI
  is unavailable; provider errors retry like any step. Results are keyed by the step's idempotency key.

### Permissions

New group `ai`: `ai.use` (drafts, summaries, extraction, writing help) and `ai.assistant` (the business
assistant). Each feature also needs the underlying permission (for example `conversations.reply` for a
reply draft, `leads.update` to apply suggestions, `website.manage` for website text). Manager gets
both; receptionist and sales executive get `ai.use`. Backfilled once for existing tenants.

## Alternatives

- **Per-business API keys** — rejected for V1; one platform key keeps setup at zero for owners.
- **Auto-reply to customers** — rejected for V1 (AI must not speak for the business unreviewed).
- **Storing assistant chats** — not needed yet; stateless keeps customer data out of another table.
- **Requests as the cap unit** — tokens track cost better across features of very different sizes.

## Consequences

- Conversation text and business data are sent to OpenRouter and the chosen model provider. Owners can
  switch AI off; tool results exclude contact details. Documented in `docs/04-security/ai-data.md`.
- A new provider is a class implementing `AIProvider` and a `config/ai.php` entry.
- Known gaps are tracked as AW-055 onwards.
