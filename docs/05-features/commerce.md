# Commerce: products, stock and orders

- **Status:** ✅ Phase 7
- **Last updated:** 2026-10-01

## Purpose

Businesses sell products from the app and from their website: a catalogue with categories and stock,
orders taken at the counter or by phone, orders placed by website visitors, and payments recorded by hand
(master prompt §29–31, §34). The decisions behind the design are in
`docs/12-decisions/ADR-017-commerce-engine.md`.

Commerce belongs to the **commerce engine**: beauty salon, restaurant/café and local store. Other business
types get 404 on every commerce route, and the website hides the Products section.

## Concepts

- **Product:** name, optional category, optional SKU, price, optional original ("compare at") price,
  description, one image, active flag.
  - Stock tracking is switched on per product. A tracked product has a stock count, a low-stock level (the
    tenant default is `commerce.low_stock_threshold`, 5) and a movement history.
  - Products are soft deleted. Past orders keep their copy of the name, SKU and price.
- **Category:** a flat list per business (up to 50). Deleting one leaves its products uncategorised.
- **Stock movement:** every change of a tracked product's stock, with a reason, the change, the new
  balance, who made it and, for sales and cancellations, the order.
- **Order:** a customer, items, how it reaches the customer (fulfilment), totals, a status, a payment
  status and payments.
  - Fulfilment: **in store** (staff orders only), **pickup** or **delivery** (an address plus a fee).
  - Source: **added by the team** or **website**.
  - Numbers are sequential per business: #1001, #1002…
- **Payment:** an amount received, with a method (cash, UPI, card, bank transfer, other), an optional
  reference and a date. The payment status (unpaid, partly paid, paid) is computed from the payments.

## User flow

1. **Products (`/products`):**
   - category buttons with counts, and a "manage categories" dialog (add, rename, delete);
   - filters: search (name, SKU, description), status, stock (low, out of stock), sort;
   - a low-stock banner linking to the low-stock filter;
   - bulk activate, deactivate and delete.
2. **Add product (`/products/create`):** details, price, image, and stock tracking with opening stock and a
   low-stock level. The opening stock is the first movement in the history.
3. **Edit product (`/products/{id}/edit`):**
   - details (the stock count is not edited here);
   - a stock card: "Adjust stock" (add, remove or set the count, with a reason and note) and the latest 50
     movements, linked to their orders;
   - image upload, replace and remove;
   - delete, with a warning when open orders include the product.
4. **Orders (`/orders`):**
   - tabs: open (default), all, pending, confirmed, ready, completed, cancelled;
   - search by order number, customer name or phone; filters: payment status, source, date range;
   - each row shows the number, customer, items, total, status and payment status.
5. **New order (`/orders/create`):**
   - customer: search existing customers, or enter name, phone and email (an existing customer with the same
     phone is reused). From a customer page the form arrives pre-filled;
   - products: search and add, then change quantities. Stock is shown for tracked products;
   - handover: in store, pickup or delivery (address, prefilled from the customer, and fee);
   - notes; "handed over now" completes the order at once;
   - summary with discount and total, and "payment received now" (amount, empty for the full total; method;
     reference).
6. **Order page (`/orders/{id}`):**
   - status actions: confirm, ready (shown as "Ready for pickup" or "Out for delivery"), completed
     ("Mark delivered" for delivery), cancel with an optional reason;
   - items and totals, paid amount and balance due;
   - payments: record a payment (amount, method, reference, date received) and remove one recorded by
     mistake;
   - customer card with call and WhatsApp buttons, delivery address, notes, and the order history.
7. **Customer page:** an orders card (latest 20) and a "New order" button. Order entries appear on the
   customer timeline.
8. **Order settings (`/settings/commerce`, linked from Orders and Settings):** online ordering on or off,
   auto-confirm website orders, minimum order, pickup, delivery, delivery fee, free delivery from a subtotal,
   and a delivery note shown at checkout.
9. **Website:** see "Online ordering" below.

## Rules

- **Prices come from the products.** Any price, discount, fee, completion or payment sent by a website
  visitor is ignored.
- Product names are unique per business among live products, and so are SKUs (both case-insensitive).
  SKUs use letters, numbers, spaces and `. _ / -`.
- The original price must be higher than the price.
- **Stock never goes below zero.** An order or adjustment that would oversell is refused with "Only 3 of
  Serum left in stock." or "Serum is out of stock."
- An order has 1–50 lines and 1–999 of each product. Duplicate lines are merged.
- Only active products can be ordered. Inactive and deleted products stay on past orders.
- Staff discounts cannot exceed the subtotal. Website orders never have a discount.
- Delivery needs an address. The delivery fee is typed by staff; on the website it comes from the settings
  (free from the configured subtotal).
- Lifecycle: pending → confirmed → ready → completed, and pending, confirmed or ready → cancelled. Steps can
  be skipped. Completed and cancelled are final.
- Staff orders start **confirmed**, or **completed** with "handed over now". Website orders start
  **pending**, or **confirmed** with auto-confirm.
- **Cancelling returns the stock** the order took, even for products deleted since. Recorded payments are
  kept (no refunds, AW-043).
- A payment must be greater than zero and at most the balance due. Cancelled orders take no payments.
  Payment dates cannot be in the future.
- Orders cannot be edited after they are placed, except notes, status and payments (AW-047).

## Online ordering (website)

The Products section of the website shows active products by category, with prices (optional, section
setting "Show prices"), the original price struck through, the image and "Out of stock" where needed.

When the shop is open (commerce engine, online ordering on, pickup and/or delivery offered, Products section
on, at least one active product):

1. Each product gets "Add to cart", then a quantity stepper capped at what can be ordered (the stock for
   tracked products, at most 999). The page does not show stock counts.
2. The header shows a cart button with the item count. The cart is kept in the browser
   (`localStorage`, key `aw-cart:{host}`) and holds only product ids and quantities.
3. The cart drawer asks the server for a quote (`POST /cart/quote`): server prices, stock problems per line,
   the minimum order, the delivery fee and the total. "Checkout" is disabled while there is a problem.
4. Checkout asks for name, phone, optional email, pickup or delivery (address for delivery) and notes.
   `POST /orders` places the order and the drawer shows the order number, the items and the total.
5. The owners get an email ("New website order #1001") from the default automation. The order shows on the
   Orders page as pending (or confirmed with auto-confirm).

Protection is the same as the other public forms (ADR-016): a hidden honeypot field, validation, and a rate
limit per visitor IP and business (`commerce.online_per_hour`, 10 per hour for orders; 60 quotes per
minute). An unpublished website accepts no orders.

## Configuration

`config/commerce.php`:

| Key | Meaning |
|---|---|
| `fulfilment` | Handover methods; `staff_only` ones (in store) are not offered online |
| `sources` | Order sources and their labels |
| `payment_methods` | Methods staff can record |
| `online` | Defaults for the tenant's online ordering settings |
| `online_per_hour` | Website orders per visitor IP and business per hour |
| `low_stock_threshold` | Default low-stock level |
| `stock_reasons` | Movement reasons; `manual` ones can be chosen when adjusting |
| `limits` | Items per order, max quantity, max price, max stock, products in the order form |
| `per_page` | List page size |

The tenant setting `commerce.online` overrides `online`. A business type can ship its own defaults with
`configuration.commerce` in `config/catalog.php` (the local store offers pickup and delivery).

## Database

`product_categories`, `products`, `stock_movements`, `orders`, `order_items`, `order_payments`,
`activities.order_id`. See `docs/03-database/schema.md`.

## Routes and permissions

All business-app routes return 404 when the tenant has no `commerce` engine.

| Route | Permission |
|---|---|
| `GET /products` | `products.view` |
| `GET /products/create`, `POST /products` | `products.create` |
| `GET /products/{product}/edit`, `PUT /products/{product}` | `products.update` |
| `POST /products/{product}/stock`, `POST`/`DELETE /products/{product}/image`, `/product-categories` | `products.update` |
| `DELETE /products/{product}` | `products.delete` |
| `POST /products/bulk` | `products.delete` to delete, otherwise `products.update` |
| `GET /orders`, `GET /orders/{order}` | `orders.view` |
| `GET /orders/create`, `POST /orders`, `GET /orders/customers` | `orders.create` |
| `PUT /orders/{order}`, `PATCH /orders/{order}/status`, `POST`/`DELETE /orders/{order}/payments…` | `orders.update` |
| `GET /settings/commerce` / `PUT /settings/commerce` | `settings.view` / `settings.update` |

Public website (inside `site.live`): `POST /cart/quote` (throttle `website-cart`) and `POST /orders`
(throttle `website-order`).

Default roles:

| Role | Access |
|---|---|
| Owner | Everything |
| Manager | Full products and orders; order settings are view-only |
| Receptionist | View and create orders (cannot change status or payments, AW-046); no product pages |
| Accountant | View products and orders; money widgets |
| Sales executive, Staff | No commerce access |

## Events (automation triggers)

Dispatched after commit from `app/Domain/Commerce/Events`:

| Event | Trigger |
|---|---|
| `OrderCreated` | `order.created` |
| `OrderConfirmed` | `order.confirmed` (also fired when an order is created confirmed or completed) |
| `OrderReady` | `order.ready` |
| `OrderCompleted` | `order.completed` |
| `OrderCancelled` | `order.cancelled` |
| `OrderPaid` | `order.paid` (when the payment status becomes paid) |

Condition fields: `order.status`, `order.source`, `order.fulfilment`, `order.payment_status`,
`order.total`. Template variables: `{{order.number}}` (#1001), `{{order.total}}`, `{{order.items}}`
("2 × Hair serum, 1 × Shampoo"), `{{order.fulfilment}}`, plus the customer and business variables.

Default templates:

- **Tell the team about website orders** (on): on `order.created` with source website, notifies the owners
  by email.
- **Tell customers their order is ready** (paused): on `order.ready`, sends the customer a WhatsApp message.

Both are created for businesses with the commerce engine and backfilled once for existing ones.

## Timeline and audit

- **Activities** (visible on the order and the customer): `order_placed`, `order_confirmed`,
  `order_ready`, `order_completed`, `order_cancelled` (the reason is the body), `payment_recorded`,
  `payment_removed`. A customer created by an order is recorded with `via: order` or `via: online_order`.
- **Audit log:** `product.created`, `product.deleted`, `product.stock_adjusted`,
  `product_category.deleted`, `products.bulk_*`, `order.payment_recorded`, `order.payment_removed`,
  `commerce.settings_updated`.

## Dashboard widgets

Shown when listed in the business type's `dashboard_widgets`:

- orders today (not cancelled; `orders.view`);
- low stock: active products at or below their low-stock level (`products.view`);
- repeat customers: 2+ completed orders (`orders.view`; businesses with the booking engine count visits
  instead).

Money widgets also need `reports.view`:

- revenue today: completed orders today. For a salon it is added to completed appointments ("Completed
  appointments and orders today").
- product sales: line totals of completed orders over 30 days.

## Security

- `BelongsToTenant` on every model, with composite FKs on every cross-row reference, including
  `order_items.product_id` and `activities.order_id`.
- Route binding happens inside the tenant, so another tenant's product, category, order and payment ids
  return 404. A payment id from another order of the same business also returns 404.
- Product images go through `ManageMedia` (type, size and dimension checks) into
  `tenant/{tenant_id}/products/`.
- Covered by `CommerceIsolationTest`: product, category, stock, image, order, status, payment and notes
  routes; lists and customer search; ordering with another tenant's products or customers; DB-level FKs;
  fail-closed queries without a tenant.

## Testing

- `tests/Feature/Commerce/ProductTest`: create with opening stock, rules, SKU reuse after delete, stock
  adjustments, never below zero, list filters, bulk actions and permissions, images, categories, engine 404s.
- `OrderTest`: server pricing, numbering per business, stock checks, rules, counter sale with payment,
  delivery, lifecycle, cancel restock (including deleted products), payments, removing payments, pages and
  permissions.
- `CommerceSettingsTest`: business type defaults, settings update and permissions, dashboard widgets,
  order automations with variables.
- `CommerceIsolationTest`: cross-tenant access.
- `tests/Feature/Website/OnlineShopTest`: products section, quote, website order, auto-confirm and
  returning customers, delivery fee, free delivery and minimum order, rules, closing the shop, honeypot and
  rate limit, owner alert.

As with booking, true parallel requests cannot run inside the transactional test harness. The stock lock
and check constraint are covered by the ledger tests and the database checks.

## Known limitations

No online payments (AW-041), no variants (AW-042), no returns or refunds (AW-043), no coupons or taxes
(AW-044), carts do not reserve stock (AW-045), receptionists cannot update orders (AW-046), orders cannot be
edited (AW-047), no customer order messages or tracking by default (AW-048), flat delivery fee only
(AW-049).
