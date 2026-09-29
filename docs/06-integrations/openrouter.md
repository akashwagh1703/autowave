# OpenRouter

- **Status:** ✅ Phase 9
- **Decision:** [ADR-008](../12-decisions/ADR-008-ai-provider-abstraction.md), [ADR-019](../12-decisions/ADR-019-ai-features.md)
- **Last updated:** 2026-09-29

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
  lead extraction; `tools` for the assistant; `usage: {include: true}` so the response includes the cost;
  `reasoning` only when `OPENROUTER_REASONING` is set (`off` → `{enabled: false}`, `low|medium|high` →
  `{effort: ...}`).
- Timeout `OPENROUTER_TIMEOUT` (default 30 s). The key is never logged or stored in `ai_usage`.

## Errors

| Response | Treated as |
|---|---|
| Connection failure, 408, 429, 5xx, 200 with an `error` object | Temporary — the user sees "try again"; queued work retries |
| Other 4xx (bad key, bad model, bad request) | Permanent — not retried |

Every call, successful or failed, is one `ai_usage` row (tokens, cost, duration, error text).

## Changing the model

Set `OPENROUTER_MODEL`, or a per-feature `model` in `config/ai.php` `features`. The model must support
tool calling (assistant) and JSON mode (extraction). Models without JSON mode still work for extraction:
OpenRouter drops the unsupported parameter and the answer is parsed leniently.

## Slow and free models

Free (`:free`) models are rate limited (about 50 requests a day on a key without credits, 20 a minute) and
queue behind paid traffic. Measured on 2026-09-29 with `nvidia/nemotron-3.5-lightning:free`: about 90 s
for a one-line reply with default reasoning, still about 95 s with `OPENROUTER_REASONING=off`, and
`low` or `minimal` reasoning can leak the model's thinking into the reply. Turning reasoning off saves
tokens, not time.

A timeout above 30 s has to be matched in three places, or the request is cut off before OpenRouter
answers:

| Where | Setting | Covers |
|---|---|---|
| `.env` | `OPENROUTER_TIMEOUT=120` | The HTTP call |
| `.env` | `REDIS_QUEUE_RETRY_AFTER` larger than the AI job timeout (`OPENROUTER_TIMEOUT + 30`, at least 60) — e.g. `200` | Lead extraction and automation AI steps; `autowave:health` fails otherwise, because a job still running at `retry_after` is run a second time |
| Nginx site | `fastcgi_read_timeout 150s;` in the PHP location | Reply drafts, summaries, writing help and the assistant, which wait in the web request |

Each waiting web request holds one PHP-FPM worker (4 on production), so a few slow AI requests at once
can make the whole app wait. The assistant may call the model up to four times per question
(`context.assistant_tool_rounds` + 1). For real use, a paid fast model such as `openai/gpt-4o-mini`
answers in a few seconds and needs none of this.
