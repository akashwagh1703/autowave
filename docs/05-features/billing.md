# Billing (plans and subscriptions)

- **Status:** ✅ Phase A: plans, trial, manual payments (UPI / bank), invoices, GST switch, plan limits and
  enforcement. ✅ Phase B: Razorpay online payments (off until keys are set). ✅ Phase C: PDF invoices, coupons.
- **Last updated:** 2026-10-08

## Purpose

Businesses pay AutoWave a monthly or yearly subscription. **Manual payments** always work: the owner pays by
UPI or bank transfer and reports the UTR; Super Admin checks the bank statement and approves. **Online
payments** (Razorpay) settle automatically once a gateway account exists. GST, manual and online are switches
that can be turned on or off without changing how subscriptions work.

## Plans

Seeded once from `config/billing.php` (`PlanSeeder`), then edited in **Super Admin → Plans**. The seeder never
overwrites an existing plan. Amounts are stored in paise.

| Plan | Monthly | Yearly | Members | Storage | AI tokens / month | Active automations | Instagram |
|---|---|---|---|---|---|---|---|
| Free trial (14 days, hidden) | ₹0 | ₹0 | 5 | 1 GB | 200,000 | 20 | Yes |
| Starter | ₹499 | ₹4,990 | 2 | 1 GB | 100,000 | 5 | No |
| Growth | ₹1,499 | ₹14,990 | 5 | 5 GB | 500,000 | Unlimited | Yes |
| Business | ₹3,999 | ₹39,990 | 15 | 20 GB | 2,000,000 | Unlimited | Yes |

- Every new business starts a 14-day trial (`CreateTenant` → `SubscriptionLifecycle::startTrial`).
  `TenantBackfillSeeder` gives existing businesses a fresh trial.
- The internal AutoWave business is on the Business plan and never expires or gets reminders.
- Limits: storage and AI caps use the plan value unless Super Admin set a per-business override
  (`StorageAllowance::defaultMb`, `AIUsageMeter::defaultCap`). Turning on an automation over the plan limit is
  refused (`ToggleAutomation::ensureCanActivate`). Connecting Instagram needs a plan that includes it.

## Subscription states

`Entitlements::state()` returns a `SubscriptionState`, computed from `subscriptions.ends_at`:

| State | When | Access |
|---|---|---|
| Active / trial | before `ends_at` | Full |
| Due | up to 7 days after `ends_at` | Full, with a banner |
| Read-only | day 8–30 | View only: writes are refused (`EnsureSubscriptionAllows`) |
| Locked | after day 30 | Only Billing opens; the public website returns 503 |

- **Enforcement is a switch** (Super Admin → Settings → Billing → "Enforce plans"), off by default. While off,
  businesses past their end date stay in "due": banners and reminders, but no restrictions.
- A payment waiting for approval keeps full access for 3 days after it was submitted.
- Automations and AI stop running for read-only and locked businesses (`Entitlements::canOperate`).

## Owner flow (app host, `/settings/billing`)

1. The page shows the current plan, days left, usage against limits, the plans (monthly / yearly toggle) and
   past payments with their invoices.
2. **Choose plan → Pay**: the dialog asks the server for a quote (`GET /settings/billing/quote`); the browser
   never sends an amount. The owner can apply a coupon code; the quote shows the discount.
3. Tabs: **Pay online** (when online is on, see below), **UPI** (the UPI ID with a generated QR or the QR image
   Super Admin uploaded, and a "Pay in UPI app" link on phones) and **Bank transfer**, each with copy buttons.
4. The owner enters the payment method, date (up to 60 days ago), the UTR / reference, an optional screenshot,
   and their GSTIN when GST is on. Rate-limited to 5 per hour.
5. The payment is "Waiting for approval" and can be withdrawn while pending. Super Admins get an email.
6. When approved, the owner gets an email with the invoice PDF attached; when rejected, the reason.

### Online payments (Razorpay)

1. `POST /settings/billing/checkout` saves the payment as **initiated** with the server's amount and creates a
   Razorpay order for exactly that (receipt `AW-PAY-{id}`). The response has the order id and the public key
   id only; the key secret and webhook secret never leave the server.
2. The browser loads Razorpay Checkout (`checkout.razorpay.com/v1/checkout.js`) and the owner pays.
3. The plan is applied by whichever arrives first:
   - the Checkout success callback, `POST /settings/billing/checkout/{payment}/confirm`, after the HMAC
     signature (key secret over `order_id|payment_id`) checks out; or
   - a signed webhook to `POST /webhooks/billing/razorpay` (HMAC of the raw body with the webhook secret),
     processed on the queue (`ProcessGatewayEvent`, retried up to 6 times).
4. Either way `OnlineCheckout::complete` fetches the payment from the Razorpay API and requires it for this
   order, amount and currency; an authorized payment is captured first. It then settles once under a row lock:
   the period is applied, the invoice issued and the owner emailed. Later callbacks and webhooks change nothing.
   A mismatch is not applied; it is logged and audited as `billing.online_payment_mismatch`.
5. Unfinished checkouts are marked **expired** after 24 hours (`billing:sweep`) or when the business starts
   another payment. A payment that still arrives for an expired checkout is applied.
6. Online needs at least ₹1 (`online_minimum`); smaller amounts are paid by UPI or bank. Starting checkouts is
   rate-limited to 20 per hour per user.

Online payments go straight from initiated to approved, so they never occupy the "one pending payment" slot.

### Coupons

- Created in **Super Admin → Coupons** (`/billing/coupons`): code (3–30 letters, numbers, dashes; stored in
  capitals), percent (1–100) or fixed rupees, optional plans and periods, total uses, start and end dates (whole
  days in India time), "once per business", "new customers only" (no paid payment yet) and active.
- The discount comes off the price after any upgrade credit, and before GST. Fixed amounts are capped at the
  price. The invoice shows the plan price and a "Discount (coupon CODE)" line.
- Uses count initiated, pending and approved payments; a rejected, withdrawn or expired payment gives its use
  back. The coupon row is locked while a payment is created, so two businesses can't take the last use.
- A coupon that covers the whole price shows **Activate plan** instead of payment (`POST
  /settings/billing/activate`): an approved payment with method `coupon`, total 0 and no invoice.
- A coupon that has been used can be switched off but not deleted.

### Plan changes

- Paying during the trial or for the same plan is a **renewal**: the paid period starts when the current one
  ends, so no trial days are lost.
- **Upgrading** to a more expensive plan starts now; the unused days of the current paid plan are credited
  against the price.
- Downgrades and period changes start at renewal. While a plan is waiting to start, it can be renewed but not
  switched to a different plan or period.

## Super Admin

- **Payments** (`/billing/payments`): filter by status (including "Checkout open" and "Not completed" for online
  checkouts), search by business, UTR, Razorpay payment / order id or coupon; view the screenshot (served with a
  CSP sandbox); approve, or reject with a reason (5–250 characters). Approving applies the period and issues the
  invoice. Online payments need no approval.
- **Coupons** (`/billing/coupons`): see above.
- **Businesses**: the Plan column shows the state; **Record payment** (cash, cheque, UPI, bank transfer,
  complimentary; optional amount override) is approved immediately; **Change plan** sets the plan, period and
  end date (end of that day in India time, or "never ends") with a reason for the audit log.
- **Plans** (`/billing/plans`): prices, description, visibility and limits. The trial stays free and hidden.
- **Settings → Billing**: enforcement, payment method switches, seller details, GST, UPI ID and payee, QR
  image, bank details and instructions. Any change to the UPI or bank details emails every Super Admin
  (`PaymentDetailsChanged`), so a hijacked admin account can't quietly redirect payments.

## Switching payment methods

- Subscriptions don't depend on how they were paid, so manual and online can be switched at any time.
- At least one method must stay on. Online can only be turned on when `BILLING_GATEWAY` is set and its keys
  are configured; manual needs a UPI ID or a bank account number.
- Only one pending payment per business (a partial unique index); turning manual off doesn't affect payments
  already waiting, which Super Admin can still approve.
- Turning online off hides the online tab at once. A checkout already paid is still applied by its webhook
  as long as the gateway keys stay in `.env`; remove the keys only after open checkouts have expired (24 hours).

### Turning on Razorpay

Razorpay reviews the website before activating an account. The marketing site has the pages it looks for:
`/pricing` (plans from the database), `/terms`, `/privacy`, `/refunds` (refund, cancellation and service
delivery) and `/contact`. They take the company name, address, email and phone from Super Admin → Settings
→ Billing (seller), so fill those in first; `AUTOWAVE_LEGAL_JURISDICTION` and `AUTOWAVE_GRIEVANCE_OFFICER`
complete the Terms and Privacy pages.

1. In `.env` on the server: `BILLING_GATEWAY=razorpay`, `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`,
   `RAZORPAY_WEBHOOK_SECRET` (any long random string), then `php artisan config:cache` and restart the worker.
2. Razorpay Dashboard → Settings → Webhooks → add `https://app.autowave.co.in/webhooks/billing/razorpay` with
   the same secret and the events `payment.captured`, `payment.authorized`, `payment.failed` and `order.paid`.
   Super Admin → Settings → Billing shows this URL.
3. Super Admin → Settings → Billing → turn on **Online payments**. Test with Razorpay test keys first.

## GST

- **Off** (default): documents are titled "Invoice" with no tax.
- **On**: "Tax Invoice" with 18% tax: CGST + SGST when the buyer's state (from their GSTIN, or the seller's
  state when there is none) matches the seller's, otherwise IGST. The seller's state code comes from the GSTIN.
- The invoice follows the tax charged on the payment, not the switch at approval time.

## Invoices

- Numbered `{prefix}/{financial year}/{0001}` (e.g. `AW/2026-27/0001`), gapless per financial year
  (April–March), issued under a lock when a payment is approved. Free periods get no invoice.
- Seller, buyer and lines are copied onto the invoice, so later edits to settings or plans don't change it.
- Owners open them at `/settings/billing/invoices/{id}`, and download a PDF from `/settings/billing/invoices/{id}/pdf`
  (Super Admin: `/billing/invoices/{id}/pdf`). The PDF (dompdf, `resources/views/billing/invoice-pdf.blade.php`)
  is rendered on request with remote resources and PHP disabled, and is attached to the approval email.

## Reminders (`billing:sweep`, hourly)

- Marks online checkouts older than 24 hours as expired.
- Settles plan changes whose start date has passed.
- Emails owners 7, 3 and 1 days before the end (each once; a missed one is skipped, not sent late), when the
  period ends, and — only while enforcement is on — when the business becomes read-only and locked.
- Businesses with a payment waiting for approval are skipped.

## Database

`plans`, `subscriptions` (one per tenant), `billing_payments`, `billing_invoices`, `billing_coupons`. See
[schema.md](../03-database/schema.md).

## Permissions

`billing.view` and `billing.manage`, held by the Owner role only. Super Admin routes are on the admin host
behind the platform-admin middleware.

## Security

- Amounts, discounts and tax are computed on the server from the plan; the client only picks plan, period and
  coupon code.
- The Razorpay key secret and webhook secret stay in `.env`; only the key id (public by design) is sent, at
  checkout. An online payment is applied only after the signature checks out and the payment fetched from
  the Razorpay API matches the order, amount and currency.
- Webhooks: no session or CSRF, 256 KB body limit, HMAC checked with `hash_equals`, 404 for any gateway other
  than the configured one.
- Invoice PDFs are served with `Cache-Control: no-store` and only to the business's owners and Super Admin.
- Payment screenshots and the QR image are on the private disk and streamed through authorised routes.
- A UTR can be claimed only once (pending or approved) across all businesses.
- Every approval, rejection, recorded payment, plan edit, subscription change and settings change is audited.

## Testing

`tests/Feature/Billing`: lifecycle and pricing, manual payments, invoices and GST, enforcement and plan
limits, Super Admin settings and plans, the sweep, online checkout and webhooks (`OnlineCheckoutTest`, Razorpay
faked with `Http::fake`), and coupons and PDFs (`CouponAndInvoicePdfTest`).

## Known limitations

- No refunds or automatic recurring charges (each period is paid on its own); refunds are done in the Razorpay
  dashboard and recorded by hand.
- No proration on downgrades.
- The member limit is shown on the billing page only; it gets enforced when team invitations are built.
