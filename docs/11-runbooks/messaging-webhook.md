# Runbook: Messaging Webhooks (Meta WhatsApp and Instagram)

- **Endpoint:** `GET|POST https://app.<domain>/webhooks/meta/{webhookKey}` (one key per business and
  channel; shown in Settings → Messaging)
- **Processing:** `ProcessWebhookCall` on the `messaging` queue
- **Last updated:** 2026-10-02

## Symptoms

- Customer messages do not appear in the inbox, or no lead is created.
- Sent messages stay on one grey tick (no delivered/read), or show "failed".
- Meta's webhook dashboard reports delivery failures, or Meta disabled the subscription.

## Diagnose

1. **Is Meta calling us?** Settings → Messaging shows "Last webhook" per channel
   (`messaging_channels.last_webhook_at`). Meta's app dashboard → Webhooks shows recent errors.
2. **What did we answer?**
   - `404`: the key in the callback URL does not match any channel (copied wrong, or a different
     environment).
   - `403` on POST: signature mismatch. The app secret saved in AutoWave is not the app's current secret
     (rotated?). `403` on GET: the verify token does not match.
   - `413`: body larger than `messaging.webhooks.max_payload_kb`.
   - `429`: the rate limit (`messaging.webhooks.rate_limit` per IP and key).
   - `200` but nothing appears: see step 3.
3. **Stored calls** (`messaging_webhook_calls`, per tenant):

   ```sql
   select id, status, attempts, error, created_at, processed_at
   from messaging_webhook_calls where tenant_id = :tenant order by id desc limit 20;
   ```

   - `pending` for minutes: the `messaging` queue worker is down (see
     [queue-failure](queue-failure.md)). `messaging:dispatch-pending` re-queues calls pending for
     `webhooks.stuck_minutes` (10).
   - `failed`: `error` holds the exception; also check `storage/logs` and `failed_jobs`.
   - `processed` but no message: the entry was for another phone number id / account id than the one
     connected (the business connected a different number), or the event type is ignored (reactions,
     echoes).
4. **Messaging module off:** calls for a business without the Messaging module are answered 200 and
   dropped.

## Fix

- **Wrong secret or token:** the owner re-enters it in Settings → Messaging (blank fields keep the saved
  values). No deploy or cache clear needed; credentials are per business in the database.
- **Re-verify** the callback in Meta after changing the URL or verify token.
- **Replay stored calls:** processing is idempotent (inbound messages by provider message id, receipts
  only move forward), so re-running a call is safe:

  ```bash
  php artisan tinker --execute="App\Domain\Messaging\Jobs\ProcessWebhookCall::dispatch(<call id>);"
  ```

  Failed jobs can also be retried with `php artisan queue:retry all`.
- **Meta disabled the webhook** after repeated failures: fix the cause, then re-subscribe the
  **messages** field in the app dashboard.

## Local testing

```bash
php artisan messaging:simulate-inbound abc-salon 9876543210 "Hello" --name="Test"
```

To test the real endpoint locally, expose `app.autowave.localhost:8000` with a tunnel and use the tunnel
URL + `/webhooks/meta/{key}` as the callback.

## Retention

`messaging:prune-webhooks` (daily 03:15) deletes processed and failed calls older than
`messaging.webhooks.retention_days` (14). Messages stay in `conversation_messages`.
