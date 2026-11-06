<?php

/*
|--------------------------------------------------------------------------
| Commerce engine (Phase 7, ADR-017)
|--------------------------------------------------------------------------
|
| Products, categories, stock, orders and payments. Tenant overrides live in
| the `commerce` tenant setting (Settings → Orders and shop); these are the
| defaults. A business type can ship its own defaults with
| `configuration.commerce` in config/catalog.php.
|
| Prices, totals and stock are always computed on the server; the browser only
| shows them.
|
*/

return [

    /*
    | How an order reaches the customer. `staff_only` methods are not offered on
    | the website. Online ordering offers pickup and/or delivery (tenant setting).
    | `engine` methods are only offered to tenants with that engine.
    */
    'fulfilment' => [
        'in_store' => ['label' => 'In store', 'staff_only' => true],
        'dine_in' => ['label' => 'Dine-in', 'staff_only' => true, 'engine' => 'food'],
        'pickup' => ['label' => 'Pickup'],
        'delivery' => ['label' => 'Delivery'],
    ],

    // Where an order came from (orders.source).
    'sources' => [
        'manual' => 'Added by the team',
        'website' => 'Website',
        'whatsapp' => 'WhatsApp',
    ],

    // Payments are recorded by hand; there is no payment gateway yet (AW-041).
    'payment_methods' => [
        'cash' => 'Cash',
        'upi' => 'UPI',
        'card' => 'Card',
        'bank_transfer' => 'Bank transfer',
        'other' => 'Other',
    ],

    /*
    | Online ordering from the public website. Tenant values live in the
    | `commerce` setting under `online`. Website orders start as pending unless
    | `auto_confirm` is on, so the team checks them first.
    */
    'online' => [
        'enabled' => true,
        'auto_confirm' => false,
        'pickup' => true,
        'delivery' => false,
        'delivery_fee' => 0,
        // Delivery is free from this order subtotal upwards (null: never free).
        'free_delivery_over' => null,
        // Smallest subtotal accepted online (null: no minimum).
        'min_order' => null,
        // Shown at checkout, e.g. "We deliver within 5 km."
        'delivery_note' => null,
    ],

    /*
    | Customer WhatsApp for orders (Settings → Orders → confirmation). Off by default
    | (AW-048). Flow: pending unpaid orders get a payment request (optional UPI QR) asking
    | the customer to pay and reply with a screenshot; after the business confirms, a
    | separate confirmation text is sent (no “show this to counter/delivery” QR).
    */
    'confirmation' => [
        'whatsapp' => false,
        'include_qr' => true,
        'qr_payload' => 'upi',
        'upi_id' => null,
        'upi_payee' => null,
        'payment_message' => "Hi {{customer.first_name}}, thanks for your order {{order.number}} at {{business.name}}.\n\n{{order.items}}\nAmount to pay: {{order.total}} ({{order.fulfilment}})\n\nPlease pay using this QR, then reply here with a screenshot of the payment. We'll review it on your order, record the payment, and confirm once it looks right.",
        'message' => "Hi {{customer.first_name}}, we've received your payment and confirmed order {{order.number}} at {{business.name}}.\n\n{{order.items}}\nTotal: {{order.total}} ({{order.fulfilment}})\n\nWe'll let you know when it's ready.",
    ],

    // Orders per visitor IP and business per hour.
    'online_per_hour' => 10,

    // Low-stock level for new tracked products; each product can change it.
    'low_stock_threshold' => 5,

    /*
    | Stock movement reasons. `manual` reasons can be chosen when adjusting stock;
    | the others are written by the system (orders, opening stock).
    */
    'stock_reasons' => [
        'opening' => ['label' => 'Opening stock'],
        'restock' => ['label' => 'New stock received', 'manual' => true],
        'adjustment' => ['label' => 'Stock count correction', 'manual' => true],
        'damaged' => ['label' => 'Damaged, expired or lost', 'manual' => true],
        'internal_use' => ['label' => 'Used in the business', 'manual' => true],
        'sale' => ['label' => 'Sold'],
        'cancellation' => ['label' => 'Order cancelled'],
    ],

    'limits' => [
        'items_per_order' => 50,
        'max_quantity' => 999,
        'max_price' => 9999999999.99,
        'max_stock' => 1000000,
        'products_in_order_form' => 500,
    ],

    'per_page' => 25,

];
