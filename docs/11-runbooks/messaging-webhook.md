# Runbook: Messaging Webhooks

> Messaging is not implemented yet (Phase 8). Update with real endpoints when built.

## Symptoms

Incoming WhatsApp/Instagram messages don't create conversations/leads; outgoing message statuses not updating.

## Diagnose

1. Provider dashboard (Meta): webhook subscription active, callback URL correct, recent delivery errors.
2. App logs: search for the webhook route and signature failures.
3. Signature failures ⇒ app secret mismatch between provider and `.env` (rotated secret?).
4. 5xx responses ⇒ exception in the adapter; check logs and `failed_jobs` (processing is queued on `messaging`).
5. Tenant mapping: provider account ID (e.g. WhatsApp phone number ID) maps to the right tenant.

## Fix

- Correct secret/token in `.env`, `php artisan config:cache`, re-verify the webhook in the provider dashboard.
- Retry failed processing jobs: `php artisan queue:retry all` (processing is idempotent on provider event ID).
- If the provider disabled the webhook after repeated failures, re-subscribe after fixing.
