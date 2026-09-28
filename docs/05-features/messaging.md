# Messaging (outbound foundation)

- **Status:** 🚧 Phase 5: outbound pipeline with simulated WhatsApp and real email. Inbox and real
  WhatsApp come in a later phase.
- **Issue(s):** AW-025, AW-029, AW-031
- **Last updated:** 2026-09-29

## Purpose

One way for the platform to send a message to a customer or a team member, whatever the channel or
provider (master prompt §43). Automations are the first user.

## Rules

- **`MessagingService::queue([...])` is the only entry point.** It needs:
  - the channel, recipient, body and an **idempotency key**;
  - optionally a subject, the recipient's name, the lead, customer or member, and the automation run.
- **Idempotent:** the key is unique per tenant. Queuing the same key again returns the existing message,
  so a retried automation step never sends twice.
- **Delivery:**
  1. `SendOutboundMessage` runs on the `messaging` queue after the surrounding transaction commits.
  2. It claims the message (`queued → sending`) and calls the channel's provider.
  3. It marks the message `sent` with the provider's message id.
  4. Messages to a lead or customer are added to their timeline, with `via: automation`, the automation
     name, `simulated` and `message_id` in the metadata. Team notifications are not added to a timeline.
  5. The automation run log gets `message.sent`.
- **Failures:** the provider error is stored and the job retries (3 tries, backoff 60 s, 300 s). After the
  last try the message is `failed` and the run log gets `message.failed`. "Send again" on the run page
  re-queues it.
- **Recovery:** `messaging:dispatch-pending` (every minute) re-dispatches messages stuck in `queued` for
  10 minutes, or in `sending` for 15 minutes.

## Channels and providers (`config/messaging.php`)

| Channel | Default provider | Env | Behaviour |
|---|---|---|---|
| `whatsapp` | `log` | `MESSAGING_WHATSAPP_PROVIDER` | **Simulated.** Written to the log (ids and masked recipient only), stored as sent with `simulated = true`, id `sim-<uuid>`. |
| `email` | `mail` | `MESSAGING_EMAIL_PROVIDER` | Sent with the default Laravel mailer (`MAIL_MAILER`; `log` locally). |

A new provider implements `App\Domain\Messaging\Contracts\MessagingProvider::send(OutboundMessage): string`
and is registered under `messaging.providers`. It is then chosen per channel through env. Business code
does not change.

## Database

`outbound_messages`:

- channel, provider, simulated;
- recipient and recipient name;
- subject and body;
- status (`queued`, `sending`, `sent`, `failed`) and attempts;
- idempotency key and provider message id;
- links to the lead, customer, member and automation run (composite FKs);
- error, `queued_at`, `sent_at`, `failed_at`.

It is unique on `(tenant_id, idempotency_key)`.

## Security

- Recipients are masked in logs and on the run page. Provider credentials (Phase 7) stay in env or config
  and never reach the frontend.
- The tenant scope applies to every read. The retry route is tenant-bound (404 across tenants) and needs
  `automation.update`.

## Testing

Covered by `tests/Feature/Automation/AutomationEngineTest.php` (milestone, idempotency, timeline entry) and
`AutomationHttpTest.php` (retry).

## Known Limitations

- WhatsApp is simulated. There is no consent, quiet hours or template approval (AW-025).
- Email uses the platform sender (AW-029).
- A worker crash after a provider accepted a message can resend it (AW-031).
- No inbox, no inbound messages and no webhooks yet (`docs/11-runbooks/messaging-webhook.md` is a draft).
