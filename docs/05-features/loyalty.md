# Loyalty stamp cards

- **Status:** ✅ Active module (`loyalty`)
- **Last updated:** 2026-11-05

## Purpose

Repeat customers collect stamps and unlock a free reward (classic café punch card: buy 9, get the 10th
free). Walk-ins join by scanning a QR; stamps are awarded when an order or appointment is completed.

## Settings (`/settings/loyalty`)

- Switch loyalty on or off
- Stamps required for one reward (2–50)
- Reward name (e.g. Free coffee)
- Earn per product unit or per completed order
- Optional product allow-list (empty = every product)
- Optional: one stamp when an appointment is completed (booking businesses)
- Birthday: ask on join form, greet on the day or the 1st of the birth month, optional free stamp reward
- Join page heading/blurb and printable join QR (needs the business website domain)

## Birthdays

- Customers store `date_of_birth` (also editable on the customer form).
- Hourly schedule `customers:birthday-greetings` fires `customer.birthday` once per customer per year
  (idempotency + `birthday_greeted_on`).
- Paused automation **Wish customers a happy birthday** can send WhatsApp.
- Optional loyalty top-up grants enough stamps for one redeem (`birthday` ledger source).

## Flows

1. **Join (QR):** `GET/POST /loyalty/join` on the public site → create/find customer by phone (optional
   birthday) → open a `loyalty_accounts` row with a public token.
2. **Card:** `GET /loyalty/card/{token}` shows stamp progress for the customer to show at the counter.
3. **Earn:** on `OrderCompleted` (and optionally `AppointmentCompleted`), add stamps (idempotent per
   order/appointment). Auto-enrols the customer if they were not on the card yet.
4. **Redeem:** staff on the customer page → subtracts `stamps_required`, increments rewards redeemed.

## Permissions

- `loyalty.view` — see the card on the customer page
- `loyalty.manage` — enroll, add stamp, redeem
- Settings use `settings.view` / `settings.update`

## Presets

Enabled for beauty salon, photo studio, café and local store (depends on `customers`).

## Testing

`tests/Feature/Loyalty/LoyaltyStampCardTest.php`
