# OpenRouter

- **Status:** ✅ Phase 9
- **Decision:** [ADR-008](../12-decisions/ADR-008-ai-provider-abstraction.md), [ADR-019](../12-decisions/ADR-019-ai-features.md)
- **Last updated:** 2026-10-03

AutoWave uses [OpenRouter](https://openrouter.ai) as its only AI provider, with one platform API key.
Businesses do not need an account.

## Setup

1. Create an API key at <https://openrouter.ai/settings/keys>. Set a credit limit on the key.
2. In the server `.env` (never in Git, never in the frontend):

   ```dotenv
   AI_PROVIDER=openrouter
   OPENROUTER_API_KEY=sk-or-...
   OPENROUTER_MODEL=openai/gpt-4o-mini
   AI_MONTHLY_TOKENS=300000
   AI_QUEUE=ai
   ```

3. `php artisan config:cache`, and make sure a queue worker listens on the `ai` queue
   (see `docs/09-devops/queue-workers.md`).
4. Super Admin → AI usage shows "OpenRouter · configured" when the key is set.

Without a key, AI is shown as "not set up" and every AI button is disabled. For local development
without a key use `AI_PROVIDER=fake` (sample answers, no network).

## How it is called

- `POST {OPENROUTER_BASE_URL}/chat/completions` (default `https://openrouter.ai/api/v1`), OpenAI-compatible.
- Headers: `Authorization: Bearer <key>`, `HTTP-Referer: APP_URL`, `X-Title: APP_NAME`.
- Body: `model`, `messages`, `max_tokens`, `temperature` per feature; `response_format: json_object` for
  lead extraction; `tools` for the assistant; `usage: {include: true}` so the response includes the cost.
- Timeout 30 s. The key is never logged or stored in `ai_usage`.

## Errors

| Response | Treated as |
|---|---|
| Connection failure, 408, 429, 5xx, 200 with an `error` object | Temporary — the user sees "try again"; queued work retries |
| Other 4xx (bad key, bad model, bad request) | Permanent — not retried |

Every call, successful or failed, is one `ai_usage` row (tokens, cost, duration, error text).

## Changing the model

Set `OPENROUTER_MODEL`, or a per-feature `model` in `config/ai.php` `features`. The model must support
tool calling (assistant) and JSON mode (extraction).
