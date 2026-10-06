# AI

- **Status:** ✅ Phase 9. Reply drafts, summaries, lead details from messages, the business assistant,
  writing help and AI automation actions, metered per business with a monthly cap.
- **Decision:** [ADR-019](../12-decisions/ADR-019-ai-features.md) (builds on ADR-008, ADR-015, ADR-018)
- **Issue(s):** AW-053 (partly resolved), AW-055 – AW-058
- **Last updated:** 2026-10-03

## Purpose

AI saves staff typing and reading time (master prompt §40–42). It drafts, summarises and suggests;
**it never changes prices, stock, bookings, payments or orders.** A person reviews every draft and
presses Send. The one exception to reviewing is opt-in: the [WhatsApp assistant](whatsapp-assistant.md)
can answer customers' typed questions with AI (ADR-021 step 3, feature `chatbot`, "WhatsApp answers" in
usage), from the business facts only, handing over to the team when unsure.

The platform works fully without AI. When AI is unavailable, AI buttons are disabled with the reason,
and automations skip AI steps.

## Availability

AI can be used by a business when all of these hold (checked in this order by `AIGateway`):

| Check | Reason shown when it fails |
|---|---|
| The `ai` module is on (catalogue, every business type) | AI is not part of this business's plan. |
| Settings → AI → "Use AI helpers" is on | AI is switched off in Settings → AI. |
| The provider is configured (`OPENROUTER_API_KEY`) | AI is not set up on this server yet. |
| This month's tokens are below the cap | This month's AI allowance is used up. It resets on the 1st. |

The shared Inertia prop `ai` (`{available, reason, message}`) tells the frontend (hook `useAi`). It is
`null` when the module is off or the user lacks `ai.use`. It never contains provider names, models or keys.

A user also needs `ai.use` (and `ai.assistant` for the assistant) plus the permission of the underlying
feature.

## Features

### Inbox (`module:messaging`)

- **Suggest reply** (✨ button in the composer, `conversations.reply`): writes a reply from the
  conversation, the business facts and the tone. With text in the box, it improves that text instead.
  The result goes into the composer; nothing is sent.
- **Summary** (✨ in the conversation header, `conversations.view`): a short summary, cached until a new
  message arrives. "Refresh" regenerates it.
- **Drafts from automations**: an `ai_draft_reply` action leaves a draft above the composer
  ("Use" / "Dismiss"). It disappears once someone replies or dismisses it.

### Leads and customers

- **Summary card** on lead and customer pages (`leads.view` / `customers.view`), from the record details
  and its last `ai.context.activities` timeline entries. Cached until the timeline changes.
- **Fill details from messages** on the lead page (`leads.update`): reads what the lead wrote on
  WhatsApp/Instagram and in website enquiries.
  - Empty fields (name, email, interest, estimated value) are filled. A name that is just the phone
    number or "WhatsApp user …1234" counts as empty. The timeline shows "(filled in by AI)".
  - Different values for filled fields become **suggestions**: tick and "Apply selected" (timeline:
    "(AI suggestion)") or "Dismiss".
  - E-mail addresses are found by pattern without AI; fewer than `ai.extraction.min_chars` characters
    of text never go to AI. The same messages are read only once.
- **Automatic extraction** (Settings → AI → "Fill lead details automatically", default on): after a lead
  writes, one job runs `ai.extraction.delay_seconds` later on the `ai` queue, at most
  `ai.extraction.max_auto_runs` times per lead. Opt-out keywords and non-text messages are ignored.

### Assistant (`/assistant`, `ai.use`)

- **Ask** tab (`ai.assistant`: owner and manager): questions about the business ("How many appointments
  tomorrow?", "Which leads came in this week?"). The model can call read-only tools, each offered only
  when the user has its permission and the module/engine is on:

  | Tool | Needs |
  |---|---|
  | `business_overview` | — (figures respect the user's permissions) |
  | `appointments` | booking engine, `appointments.view` |
  | `leads` | leads module, `leads.view` |
  | `customers` | customers module, `customers.view` |
  | `orders` | commerce engine, `orders.view` |
  | `products` | commerce engine, `products.view` |
  | `services` | service engine, `services.view` |

  Tools return names, dates, counts and amounts — never phone numbers or e-mail addresses — and at most
  `ai.context.assistant_rows` rows. The chat lives in the browser; the last `ai.context.assistant_turns`
  turns are sent with each question.
- **Write** tab: marketing text (WhatsApp promotion, offer, Instagram caption, festival greeting, reply to
  a review).

### Writing help

`POST /ai/write` with a kind from `config/ai.php` `copy_kinds`. Each kind has a maximum length and may
need a permission on top of `ai.use`:

| Kind | Where | Extra permission |
|---|---|---|
| `website_field` | Website section editor, fields marked `'ai' => true` in `config/website.php` | `website.manage` |
| `automation_message` | Automation builder: WhatsApp, email and notification messages | `automation.create` or `automation.update` |
| marketing kinds | Assistant → Write | — |

Automation messages may use only the placeholders of the chosen trigger; the model is told which.

### Automations

- Trigger **Message received** (`message.received`, group Messages, subject `conversation`): fired for
  every inbound WhatsApp/Instagram message except opt-out/opt-in keywords. Conditions: channel, assigned,
  message text. Variables: `{{conversation.channel}}`, `{{message.text}}`, plus lead/customer ones.
- Actions (group AI, need the `ai` module):
  - **Fill lead details with AI** (`ai_extract_lead`) — same rules as the button.
  - **Draft a reply with AI** (`ai_draft_reply`, optional instructions) — a draft in the inbox, never sent.
    Skipped if the contact opted out or someone already replied.
  - **Add an AI summary** (`ai_summarize`) — a timeline note "AI summary: …".
- AI steps are *skipped* (not failed) when AI is unavailable; temporary provider errors retry like any
  step. Each step is idempotent per run step.

## Settings → AI (`settings.view`; saving needs `settings.update`)

- Use AI helpers (on/off), fill lead details automatically, tone (friendly, professional, short),
  notes for AI (up to `ai.notes_max` characters — opening days, policies; shown to the model as facts).
- This month's usage: tokens used of the allowance, requests, per feature, reset date.
- Changes are audit-logged (`ai.settings_updated`).

## Super Admin → AI usage (`/ai-usage`)

- Month filter and search. Totals (requests, tokens, cost in USD, failed calls, businesses) and per
  feature; per business: requests, failures, tokens, cost, allowance and percent used.
- **Set allowance** per business (tokens per month, or "Use default"). Stored as tenant setting
  `ai_quota`, which no business screen can write. Audit-logged (`ai.limit_updated`).
- The admin dashboard shows this month's AI requests, tokens and cost.

## Configuration (`config/ai.php`)

| Key | Default | Meaning |
|---|---|---|
| `provider` (`AI_PROVIDER`) | `openrouter` | `openrouter` or `fake` (sample answers, no network) |
| `openrouter.api_key` (`OPENROUTER_API_KEY`) | — | Platform key; server-side only |
| `openrouter.model` (`OPENROUTER_MODEL`) | `openai/gpt-4o-mini` | Default model |
| `openrouter.timeout` (`OPENROUTER_TIMEOUT`) | 30 | Seconds to wait for an answer |
| `openrouter.reasoning` (`OPENROUTER_REASONING`) | — | `off`, `low`, `medium`, `high`; empty = model default |
| `job_timeout` | `OPENROUTER_TIMEOUT` + 30, at least 60 | Timeout of queued jobs that call AI; must be below the queue's `retry_after` |
| `features.*` | — | Per feature: label, `max_tokens`, `temperature`, optional `model` |
| `limits.monthly_tokens` (`AI_MONTHLY_TOKENS`) | 300000 | Monthly allowance for a business without a plan; with a plan its `ai_tokens` limit applies ([billing.md](billing.md)) |
| `limits.per_minute` | 20 | AI requests per user per minute (`throttle:ai`) |
| `context.*` | — | How much history, timeline and data is sent |
| `extraction.*` | 120 s, 5 runs, 15 chars | Automatic extraction |
| `defaults.*` (`AI_AUTO_EXTRACT`) | on | Business settings before the owner changes them |
| `tones`, `notes_max`, `copy_kinds`, `instructions_max` | — | Writing |
| `queue` (`AI_QUEUE`) | `ai` | Queue for automatic extraction |

Prompts are in `resources/prompts/*.md`.

## Code map

- `app/Domain/AI/Services/AIService.php` — use cases; `AIGateway.php` — availability, provider call,
  metering.
- `app/Domain/AI/Providers/` — `OpenRouterProvider`, `FakeProvider`.
- `app/Domain/AI/Support/` — `AISettings`, `AIUsageMeter`, `PromptLibrary`, `BusinessFacts`, `Transcript`.
- `app/Domain/AI/Actions/` — `ExtractLeadDetails`, `ReviewLeadSuggestions`.
- `app/Domain/AI/Assistant/AssistantTools.php`, `Jobs/ExtractLeadFromConversation.php`,
  `Listeners/QueueLeadExtraction.php`.
- `app/Domain/Automation/Actions/Steps/Ai*Step.php`.
- HTTP: `App\AiController`, `App\AssistantController`, `App\AiSettingsController`,
  `Admin\AiUsageController`, `AiPresenter`.
- Frontend: `resources/js/modules/ai/*`, `pages/business/assistant/Index.jsx`,
  `pages/business/settings/Ai.jsx`, `pages/admin/ai/Usage.jsx`.

## Tests

`tests/Feature/AI/*` (46 tests): OpenRouter payload, tool calls and error classes (`Http::fake`),
availability order, metering and cap, endpoints, permissions, module off, rate limit, extraction fill
and suggestions, automatic extraction, assistant tools and data minimisation, automation trigger and
actions, settings, Super Admin usage and caps, module backfill, tenant isolation.
