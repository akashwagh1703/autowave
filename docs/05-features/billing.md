# Billing (plans and subscriptions)

- **Status:** ✅ Phase A: plans, trial, manual payments (UPI / bank), invoices, GST switch, plan limits and
  enforcement. ⏳ Phase B: Razorpay online payments. ⏳ Phase C: PDF invoices, coupons.
- **Last updated:** 2026-10-07

## Purpose

Businesses pay AutoWave a monthly or yearly subscription. The platform has no GST registration and no
payment gateway yet, so Phase A takes **manual payments**: the owner pays by UPI or bank transfer and reports
the UTR; Super Admin checks the bank statement and approves. GST and online payments are switches that can
be turned on later without changing how subscriptions work.

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
   never sends an amount.
3. The dialog shows the UPI ID with a generated QR (or the QR image Super Admin uploaded), a "Pay in UPI app"
   link on phones, and the bank details, each with copy buttons.
4. The owner enters the payment method, date (up to 60 days ago), the UTR / reference, an optional screenshot,
   and their GSTIN when GST is on. Rate-limited to 5 per hour.
5. The payment is "Waiting for approval" and can be withdrawn while pending. Super Admins get an email.
6. When approved, the owner gets an email with the invoice link; when rejected, the reason.

### Plan changes

- Paying during the trial or for the same plan is a **renewal**: the paid period starts when the current one
  ends, so no trial days are lost.
- **Upgrading** to a more expensive plan starts now; the unused days of the current paid plan are credited
  against the price.
- Downgrades and period changes start at renewal. While a plan is waiting to start, it can be renewed but not
  switched to a different plan or period.

## Super Admin

- **Payments** (`/billing/payments`): filter by status, search; view the screenshot (served with a CSP sandbox);
  approve, or reject with a reason (5–250 characters). Approving applies the period and issues the invoice.
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

## GST

- **Off** (default): documents are titled "Invoice" with no tax.
- **On**: "Tax Invoice" with 18% tax: CGST + SGST when the buyer's state (from their GSTIN, or the seller's
  state when there is none) matches the seller's, otherwise IGST. The seller's state code comes from the GSTIN.
- The invoice follows the tax charged on the payment, not the switch at approval time.

## Invoices

- Numbered `{prefix}/{financial year}/{0001}` (e.g. `AW/2026-27/0001`), gapless per financial year
  (April–March), issued under a lock when a payment is approved. Free periods get no invoice.
- Seller, buyer and lines are copied onto the invoice, so later edits to settings or plans don't change it.
- Owners open them at `/settings/billing/invoices/{id}` and print / save as PDF from the browser.

## Reminders (`billing:sweep`, hourly)

- Settles plan changes whose start date has passed.
- Emails owners 7, 3 and 1 days before the end (each once; a missed one is skipped, not sent late), when the
  period ends, and — only while enforcement is on — when the business becomes read-only and locked.
- Businesses with a payment waiting for approval are skipped.

## Database

`plans`, `subscriptions` (one per tenant), `billing_payments`, `billing_invoices`. See
[schema.md](../03-database/schema.md).

## Permissions

`billing.view` and `billing.manage`, held by the Owner role only. Super Admin routes are on the admin host
behind the platform-admin middleware.

## Security

- Amounts and tax are computed on the server from the plan; the client only picks plan and period.
- Gateway keys stay in `.env` and are never sent to the browser.
- Payment screenshots and the QR image are on the private disk and streamed through authorised routes.
- A UTR can be claimed only once (pending or approved) across all businesses.
- Every approval, rejection, recorded payment, plan edit, subscription change and settings change is audited.

## Testing

`tests/Feature/Billing`: lifecycle and pricing, manual payments, invoices and GST, enforcement and plan
limits, Super Admin settings and plans, and the sweep.

## Known limitations

- No online payments yet (Phase B: Razorpay Checkout and webhooks).
- Invoices are HTML (print to PDF); no coupons or proration on downgrades (Phase C).
- The member limit is shown on the billing page only; it gets enforced when team invitations are built.
