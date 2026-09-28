# AI data handling

- **Status:** ✅ Phase 9
- **Decision:** [ADR-019](../12-decisions/ADR-019-ai-features.md)
- **Last updated:** 2026-10-03

## What is sent to the AI provider

| Feature | Data sent to OpenRouter (and the model provider) |
|---|---|
| Reply draft | Business facts, the contact's display name, the last `ai.context.messages` messages of the conversation |
| Conversation summary | Contact name, channel, the last messages |
| Lead/customer summary | Record name, stage/source/interest/value (lead) or city/tags (customer), recent timeline entries |
| Lead extraction | Inbound text of the lead's conversations and website enquiries; business name, type and catalogue |
| Assistant | The user's name, recent chat turns and tool results |
| Writing help | Business facts, the text being improved and the instructions |

Business facts are the profile, opening hours, active services and products with prices, and the
owner's "notes for AI".

## Minimisation

- Assistant tools return names (customers by first name in appointment and order lists), dates, counts
  and amounts. They never return phone numbers, e-mail addresses or addresses.
- Timeline entries and messages are truncated (`ai.context.message_chars`); only a bounded number is sent.
- Opt-out keywords, non-text messages and very short texts are not sent for extraction.
- Message text typed by customers is **not redacted** (AW-055).

## Controls

- The owner can switch AI off (Settings → AI). Nothing is sent while it is off.
- A platform admin can set a business's allowance to 0.
- Only users with `ai.use` can trigger AI; the assistant needs `ai.assistant`. Every feature also checks
  the underlying permission, and tools run in the tenant scope.

## Secrets

- The OpenRouter key lives only in the server `.env` (`OPENROUTER_API_KEY`). It is sent in the
  `Authorization` header, never in URLs, logs, `ai_usage` rows or Inertia props. A test asserts the shared
  `ai` prop exposes no provider details.

## Storage

- `ai_results`: summaries, reply drafts and extraction results (tenant-scoped, `BelongsToTenant`).
- `ai_usage`: one row per call, without prompts or answers (only tokens, cost, status, a truncated error).
- Assistant chats are not stored on the server.

## AI never acts alone

AI output is a draft or a suggestion. It never sends a message, and never writes prices, stock,
availability, appointments, payments or orders. Extraction fills only empty lead fields
(name, email, interest, estimated value); other differences need a person to apply them.
