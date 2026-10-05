# Runbook: Enable email, AI and WhatsApp/Instagram in production

- **Last updated:** 2026-10-05
- **Starting point:** production launched with `MAIL_MAILER=log`, `AI_PROVIDER=fake` and the `log`
  (simulated) messaging fallback, so nothing leaves the server until each part is set up.

## Editing the production `.env`

```bash
sudo -u autowave nano /var/www/autowave-platform/shared/.env
# after saving:
cd /var/www/autowave-platform/current
sudo -u autowave php8.4 artisan optimize
sudo -u autowave php8.4 artisan queue:restart
```

Config is cached, so a change has no effect until `optimize`; the worker keeps the old config until
`queue:restart`. Never paste secrets into chat, tickets or Git; never `cat` the file on a shared screen.

## 1. Email

Used for password-reset emails (until email is set up, "Forgot password" only writes the email to the
log), billing and demo-request alerts, and the Email channel in automations (`MESSAGING_EMAIL_PROVIDER=mail`).

### Recommended: Resend over HTTPS

DigitalOcean blocks SMTP ports and Brevo rejected the SMTP login (2026-10-05), so production uses Resend's
API on port 443 (`resend/resend-php`, Laravel's `resend` mailer).

1. Sign up at <https://resend.com>, open **Domains → Add domain**, enter `autowave.co.in`, region any.
2. Add the records Resend shows in **DigitalOcean → Networking → Domains → autowave.co.in**: the DKIM TXT
   `resend._domainkey`, and the MX + SPF TXT on the `send` subdomain. They don't touch the Zoho MX or the
   root SPF. Click **Verify** until the domain shows **Verified**.
3. **API Keys → Create API key**, permission *Sending access*, domain `autowave.co.in`. It starts with `re_`.
4. In `.env`:

   ```dotenv
   MAIL_MAILER=resend
   RESEND_API_KEY=re_...
   MAIL_FROM_ADDRESS="hello@autowave.co.in"
   MAIL_FROM_NAME="AutoWave"
   ```

5. `optimize` + `queue:restart`, then send the test in step 5 below.

The root domain must have **one** SPF record (`v=spf1 include:zoho.in ~all`, plus any other sender) and
**one** `_dmarc` record; duplicates make SPF / DMARC fail.

### Alternative: SMTP provider

1. Pick a transactional provider (Brevo, Amazon SES, Mailgun, Postmark, Zoho ZeptoMail…) and add
   `autowave.co.in` as a sending domain.
2. Add the provider's DNS records in **DigitalOcean → Networking → Domains → autowave.co.in**: SPF (TXT on
   `@`), DKIM (TXT or CNAME as given), and DMARC, for example TXT `_dmarc` =
   `v=DMARC1; p=quarantine; rua=mailto:you@yourdomain`. (The GoDaddy DMARC record did not move with the
   nameservers.)
3. **DigitalOcean may block outbound SMTP ports** (25, 465, 587) on droplets. Test first:

   ```bash
   timeout 5 bash -c '</dev/tcp/smtp-relay.brevo.com/587' && echo open || echo blocked
   ```

   If blocked, use the provider's port **2525** (most support it) or ask DigitalOcean support to unblock.
4. Set in `.env`:

   ```dotenv
   MAIL_MAILER=smtp
   MAIL_HOST=smtp-relay.brevo.com        # your provider's host
   MAIL_PORT=587                         # or 2525; for 465 also set MAIL_SCHEME=smtps
   MAIL_USERNAME=...
   MAIL_PASSWORD=...
   MAIL_FROM_ADDRESS="hello@autowave.co.in"
   MAIL_FROM_NAME="AutoWave"
   ```

5. `optimize` + `queue:restart`, then send a test:

   ```bash
   sudo -u autowave php8.4 artisan tinker --execute="Illuminate\Support\Facades\Mail::raw('AutoWave SMTP test', fn (\$m) => \$m->to('you@example.com')->subject('SMTP test'));"
   ```

   Then try "Forgot password" on <https://app.autowave.co.in/login>. Failures appear in
   `storage/logs/laravel-*.log`.

## 2. AI (OpenRouter)

Details: [openrouter.md](../06-integrations/openrouter.md).

1. Create a **separate production key** at <https://openrouter.ai/settings/keys> (not the development key)
   and set a credit limit on it.
2. Set in `.env`:

   ```dotenv
   AI_PROVIDER=openrouter
   OPENROUTER_API_KEY=sk-or-...
   OPENROUTER_MODEL=openai/gpt-4o-mini
   ```

   A free `:free` model (no credits) instead needs longer timeouts in `.env` and Nginx, and answers take
   1–2 minutes — see [Slow and free models](../06-integrations/openrouter.md#slow-and-free-models):

   ```dotenv
   OPENROUTER_MODEL=nvidia/nemotron-3.5-lightning:free
   OPENROUTER_TIMEOUT=120
   OPENROUTER_REASONING=off
   REDIS_QUEUE_RETRY_AFTER=200
   ```

   and `fastcgi_read_timeout 150s;` in the PHP `location` of `/etc/nginx/sites-available/autowave-platform`,
   then `sudo nginx -t && sudo systemctl reload nginx`.

3. `optimize` + `queue:restart` (the worker already listens on the `ai` queue), then
   `php artisan autowave:health` (fails if `REDIS_QUEUE_RETRY_AFTER` is too small).
4. Check: Super Admin → AI usage shows "OpenRouter · configured"; in a business, AI reply drafts and the
   assistant answer with real text (not "sample answer").
5. Customer messages are sent to OpenRouter without redaction (AW-055); mention it in the privacy policy.

## 3. WhatsApp and Instagram (Meta)

**No server change is needed.** Each business connects its own Meta app in **Settings → Messaging**; the
tokens and app secret are stored encrypted in the database. The platform keeps
`MESSAGING_WHATSAPP_PROVIDER=log` / `MESSAGING_INSTAGRAM_PROVIDER=log` as the fallback for businesses that
have not connected a channel.

For each business (full field guide: [meta-whatsapp.md](../06-integrations/meta-whatsapp.md),
[instagram.md](../06-integrations/instagram.md)):

1. In Meta: a Meta app with WhatsApp (and/or Instagram API with Instagram login), a WhatsApp Business
   Account and phone number, and a **System User permanent token** (the 24-hour token is for testing).
2. In AutoWave → Settings → Messaging: enter phone number id, WABA id, access token and app secret.
   AutoWave verifies them with Meta on save.
3. Copy the **callback URL** (`https://app.autowave.co.in/webhooks/meta/<key>`) and **verify token** shown
   there into Meta → WhatsApp → Configuration, verify, and subscribe to **messages** (Instagram: also
   **message_reads**).
4. Switch the Meta app to **Live** mode; apps in development mode only receive test webhooks. Live mode
   needs a privacy policy URL (the marketing site has none yet, AW-068 — the business can use its own).
5. Test: send a WhatsApp message to the business number → it appears in the Inbox; reply from the Inbox.
   Troubleshooting: [messaging-webhook.md](messaging-webhook.md).

## Check after any change

```bash
cd /var/www/autowave-platform/current
sudo -u autowave php8.4 artisan autowave:health
sudo -u autowave php8.4 artisan queue:failed | head
tail -n 50 storage/logs/laravel-$(date +%F).log 2>/dev/null
```
