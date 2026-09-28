# ADR-017: Commerce engine — server-priced orders, a locked stock ledger, manual payments

- **Status:** Accepted
- **Date:** 2026-10-01

## Context

Phase 7 adds products, categories, inventory, carts and orders (master prompt §29–31, §34). It also
finishes the website Products section, which Phase 6 built but kept hidden. Requirements that shape the
design:

- The same engine serves a salon selling hair care, a local store, and a café. Engines are toggled per
  business type (ADR-005): only types with the `commerce` engine get products and orders.
- Decisions taken with the product owner for this phase:
  - Staff create orders in the app **and** visitors order from the website cart.
  - Payments are **recorded by hand** (cash, UPI, card…). There is no payment gateway yet.
  - **No variants**: one price and one stock level per product.
  - **Stock tracking for every commerce business**, switched on per product. Orders take stock;
    adjustments, a movement history and low-stock alerts are included.
  - **Pickup and local delivery** (an address plus a flat fee), each switchable. Staff orders can also be
    handed over in the store.
- Overselling must be impossible, not just unlikely: two orders must never sell the same last item.
- A browser must never decide a price. Website carts live in the visitor's browser and can be tampered with.
- Automations (ADR-015) need stable order triggers.
- The shared dev database is PostgreSQL 11 (AW-006).

## Decision

1. **Tables** (all tenant-owned, composite FKs `(id, tenant_id)` as in ADR-013/014):
   - `product_categories`, `products` (soft deletes), `stock_movements`, `orders`, `order_items`,
     `order_payments`, plus `activities.order_id`.
   - Money is `decimal(12,2)`, handled as strings with bcmath in PHP.
   - Check constraints back the rules up:
     - `products_valid`: prices ≥ 0 and `stock_quantity ≥ 0`;
     - `orders_valid`: known statuses, `total = subtotal − discount + delivery_fee`, `discount ≤ subtotal`,
       `amount_paid ≤ total`;
     - `order_items_valid`: `line_total = unit_price × quantity`;
     - `stock_movements_valid`: non-zero change, balance ≥ 0;
     - `order_payments_valid`: amount > 0.
   - `products.image_media_id` references `media.id` alone (not composite), because PostgreSQL 11 cannot
     null one column of a composite key on delete. The application only links images of the same tenant.
2. **Prices come from the server, always.** `OrderPricing` re-prices every cart from the products:
   - on each website cart quote (`POST /cart/quote`);
   - again inside the order transaction (`PlaceOrder`).

   Prices, discounts, delivery fees, completion and payments sent by a website visitor are ignored. Items
   copy the product name, SKU and price at the time of the order, so later price changes do not rewrite
   history.
3. **Stock only changes through `StockLedger`.** Each change:
   - locks the product row (`SELECT … FOR UPDATE`);
   - checks the new balance stays between 0 and `commerce.limits.max_stock`;
   - updates `stock_quantity` and writes a `stock_movements` row with the reason and the new balance.

   Reasons are configured in `config/commerce.php`: `opening`, `sale` and `cancellation` are written by the
   system; `restock`, `adjustment`, `damaged` and `internal_use` can be chosen by hand. `PlaceOrder` takes
   stock in product-id order, so two concurrent orders lock rows in the same order and cannot deadlock.
   The check constraint is the final guard.
4. **`order_items.stock_deducted`** records whether a line took stock. Cancelling returns exactly that
   quantity and clears the flag, so stock is never returned twice. It also works for products that were
   deleted or stopped tracking stock after the order.
5. **Order lifecycle as an enum with explicit transitions** (`OrderStatus`):

   | From | Allowed to |
   |---|---|
   | pending | confirmed, ready, completed, cancelled |
   | confirmed | ready, completed, cancelled |
   | ready | completed, cancelled |
   | completed, cancelled | — (final) |

   - Staff orders start `confirmed`, or `completed` when handed over at once.
   - Website orders start `pending`, or `confirmed` when the tenant turns on auto-confirm.
   - "Ready" is shown as "Ready for pickup" or "Out for delivery" depending on the fulfilment.
   - Each change records a timeline activity and dispatches an event (`ShouldDispatchAfterCommit`):
     `OrderCreated`, `OrderConfirmed`, `OrderReady`, `OrderCompleted`, `OrderCancelled`, `OrderPaid`.
6. **Order numbers are sequential per tenant** (#1001, #1002…). `PlaceOrder` locks the tenant row while it
   takes `max(number) + 1`; `unique (tenant_id, number)` backs this up.
7. **Payments are rows, not a field.** `RecordOrderPayment` locks the order, refuses amounts above the
   balance or on cancelled orders, writes an `order_payments` row, then recomputes `amount_paid` and
   `payment_status` (unpaid / partial / paid) from the rows. A payment recorded by mistake can be removed
   (`RemoveOrderPayment`); both actions are on the timeline and in the audit log.
8. **Website ordering** (`OnlineShop`) is open only when all of these hold:
   - the tenant has the commerce engine;
   - online ordering is on, with pickup and/or delivery;
   - the Products section is enabled;
   - at least one product is active.

   The cart (product ids and quantities only) lives in the visitor's `localStorage`. Checkout posts to
   `POST /orders` through the same rules as the other public forms (ADR-016):
   - honeypot;
   - a per-IP, per-business rate limit (`commerce.online_per_hour`);
   - `Phone::normalize`;
   - customer reuse by phone number.

   Visitors see whether a product is in stock and how many they may order, never the exact stock count.
9. **Settings.** The `commerce` tenant setting holds `online` (enabled, auto_confirm, pickup, delivery,
   delivery_fee, free_delivery_over, min_order, delivery_note) over the `config/commerce.php` defaults. A
   business type can ship its own defaults with `configuration.commerce` (the local store offers delivery).
   `CommerceSettings` reads fresh on every call (no memo), so it is safe inside long-lived workers.
10. **Automations.** Subject `order` (entities order and customer), triggers `order.created`, `confirmed`,
    `ready`, `completed`, `cancelled` and `paid`, condition fields and template variables. Two default
    templates are added:
    - `new_online_order_alert` (active): emails the owners about website orders;
    - `order_ready` (paused): a WhatsApp message when an order is ready.

    `order.paid` runs are keyed by the latest payment id, so a payment removed and recorded again can run
    once more.
11. **Engine gating and permissions.** `engine:commerce` returns 404 without the engine. Routes use the
    existing permission keys:
    - `products.update` covers stock, image and categories;
    - `orders.create` covers the order form and customer lookup;
    - `orders.update` covers status, payments and notes.

## Alternatives

- **Trust the cart's prices and validate afterwards.** Every future path (API, imports) would have to
  remember the check. Re-pricing in one service is simpler and safe by default.
- **Reserve stock in the cart.** Abandoned carts would hold stock, and a cleanup job would be needed. Stock
  is taken when the order is placed instead; the quote only warns.
- **Decrement stock with `UPDATE … WHERE stock_quantity >= n` and no ledger.** This is safe but leaves no
  history. The ledger gives the movement list, and the lock gives a clear error message.
- **A PostgreSQL sequence per tenant for order numbers.** Sequences cannot be scoped per tenant without one
  sequence each. Locking the tenant row is cheap at this volume.
- **A single `paid` flag.** Partial payments (advance at booking, balance at pickup) are common for these
  businesses.
- **Integrate a payment gateway now.** This was deferred by the product owner (AW-041); the payment rows
  are the place a gateway will write to.

## Consequences

- Every order path (staff UI and website today; API and automations later) must go through `PlaceOrder`,
  and every stock change through `StockLedger`. The check constraints reject anything that does not.
- Staff orders cannot be edited after creation, apart from notes, status and payments. A wrong order is
  cancelled (which returns its stock) and placed again.
- Completed orders are final: returns and refunds are not supported yet (AW-043). Cancelling keeps
  recorded payments; refunds happen outside AutoWave.
- The receptionist role can create orders but not change their status or payments (`orders.update`).
  Tenants can grant it in Roles.
- The website cart relies on JavaScript and `localStorage`. The server treats it as a suggestion.
