# Food: menu, tables, reservations, dine-in and the kitchen screen

- **Status:** ✅ Phase 10
- **Decision:** [ADR-020](../12-decisions/ADR-020-additional-verticals.md)
- **Last updated:** 2026-10-04

## Purpose

Run a cafe or restaurant on the same order pipeline as a shop: a menu with veg / non-veg marks and
"sold out today", table reservations (from the team or the website), dine-in orders by table, and a
kitchen screen (KOT) that tells the floor when food is ready.

The `food` engine is on for the **Cafe & Restaurant** business type, together with `commerce`.

## Concepts

| Concept | Meaning |
|---|---|
| Menu item | A commerce **product** with an optional food type (veg, non-veg, contains egg) and an "available now" switch (`is_available`). |
| Dining table | A named table with seats and an optional area ("Garden"). |
| Reservation | A guest (customer), party size, time, duration, optional table, status and source (team, website or [WhatsApp](whatsapp-assistant.md)). |
| Dine-in order | A commerce order with fulfilment `dine_in`, an optional table and an optional customer (walk-ins). |
| Kitchen ticket | The queued items of a confirmed order. Each item is `queued` or `ready`. |

## User flow

1. **Products** is the menu: mark items veg / non-veg / egg, and switch "available" off when an item
   runs out (bulk action available). Unavailable items stay on the website menu, marked sold out.
2. **Tables → Add table.**
3. **Reservations:** today's list and a date picker. Add a reservation (existing or new guest), assign a
   table, then confirm, seat (up to an hour early), complete, mark no-show or cancel with a reason.
4. **Website:** guests pick party size, date and time, and leave name and phone. Requests arrive as
   pending (or confirmed with auto-confirm) and appear in Reservations.
5. **Orders → New order → Dine-in:** pick a table (or none for a counter walk-in) and items. The order is
   confirmed and its items go to the kitchen. **Add items** on an open dine-in order sends the new lines
   to the kitchen too.
6. **Kitchen:** tickets oldest first, refreshed every `food.kitchen_refresh_seconds`. **Ready** marks
   the ticket's items ready and moves a confirmed order to Ready ("Ready to serve"). Complete the order
   and record payment on the order page.

## Rules

- Reservations: party size 1 to `max_party_size`; the team can book any time (not in the past), the
  website only within the configured hours, notice and horizon. The database refuses two live
  reservations (pending, confirmed, seated) overlapping on one table; back-to-back is fine.
- Tables must be active and of this business. A table with an open order cannot be deleted.
- Dine-in orders need no customer; other fulfilments still do. Only dine-in orders can have a table.
- Items can be added only to open (not completed or cancelled) dine-in orders. Prices come from the
  products; stock is taken as for a new order; the discount is kept (AW-060).
- Unavailable or inactive items cannot be ordered.
- Marking a ticket ready twice is refused (`order` error).

## Configuration

`config/food.php` holds the defaults. Tenants override reservations in **Settings → Reservation
settings** (tenant setting `food`):

| Setting | Default | Meaning |
|---|---|---|
| `online` | on | Take reservation requests on the website. |
| `auto_confirm` | off | Confirm website requests straight away. |
| `duration_minutes` | 90 | How long a table is held. |
| `opens` / `closes` | 11:00 / 22:00 | First and last (exclusive) website start times, local. |
| `slot_interval` | 30 | Minutes between website start times. |
| `max_party_size` | 12 | Largest party the website accepts. |
| `min_notice_minutes` | 60 | Earliest website booking from now. |
| `max_days_ahead` | 30 | Website booking horizon. |

Website requests are rate-limited to `food.online_per_hour` per visitor IP and business, with a
honeypot field.

## Database

`dining_tables`, `reservations` (exclusion constraint `reservations_no_overlap`), plus
`products.food_type` / `is_available`, `orders.dining_table_id` and `order_items.kitchen_status` /
`notes` / `added_at`. See [schema.md](../03-database/schema.md).

## Routes and permissions

| Route | Permission |
|---|---|
| `GET /tables` | `reservations.view` |
| `POST/PUT/DELETE /tables…` | `reservations.manage` |
| `GET /reservations`, `GET /reservations/{reservation}` | `reservations.view` |
| `POST/PUT /reservations…`, `PATCH …/status`, `GET /reservations/customers` | `reservations.manage` |
| `GET /kitchen` | `orders.view` |
| `POST /kitchen/{order}/ready` | `orders.update` |
| `POST /orders/{order}/items` | `orders.update` |
| `GET/PUT /settings/food` | `settings.view` / `settings.update` |
| Website `GET /reservations/slots`, `POST /reservations` | public, throttled |

All app routes need the `food` engine (the kitchen also `commerce`). Template roles: Manager and
Receptionist manage reservations; Sales Executive and Staff view them. The Staff role cannot open the
kitchen screen by default (AW-062).

## Events (automation triggers)

| Trigger | Default template |
|---|---|
| `reservation.created` | "Tell the team about website reservations" — e-mails owners (active) |
| `reservation.confirmed` | "Confirm reservations on WhatsApp" (paused) |
| `reservation.cancelled` | — |
| `order.ready` | "Tell customers their order is ready" (paused) |

Variables: `{{reservation.date}}`, `{{reservation.time}}`, `{{reservation.party_size}}`,
`{{reservation.table}}`. Condition fields: reservation status, source, party size.

## Timeline and audit

`reservation_created`, `reservation_table_assigned` and status changes on the guest's timeline;
`order_items_added` on the order's customer. All changes are audited.

## Dashboard widgets

`reservations_today` (live and completed reservations today), `kitchen_queue` (orders with queued
items), plus the commerce widgets. The assistant has a `reservations` tool.

## Security

- Tenant-scoped tables with composite foreign keys (reservations → tables and customers, orders →
  tables). Cross-tenant ids return 404 or a validation error.
- The website never sees tables or other guests; it only gets start times.

## Testing

`tests/Feature/Food/FoodTest.php`, `FoodIsolationTest.php`, plus
`tests/Feature/Tenancy/VerticalProvisioningTest.php` and `tests/Feature/AI/VerticalAssistantToolsTest.php`.

## Known limitations

- Website requests do not check table availability (AW-063).
- No per-item kitchen notes in the UI (AW-061).
- No split bills or table transfers.
- Older cafe websites do not get the `reservation` section automatically (AW-059).
