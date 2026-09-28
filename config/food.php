<?php

/*
|--------------------------------------------------------------------------
| Food engine (Phase 10, ADR-020)
|--------------------------------------------------------------------------
|
| Cafes and restaurants: the menu is the commerce product catalogue (with a
| food type and an "available now" switch), plus dining tables, table
| reservations, dine-in orders by table and a kitchen screen. Tenant
| overrides live in the `food` tenant setting (Settings → Reservations); these are
| the defaults.
|
*/

return [

    // Menu item food types (products.food_type); null means "not marked".
    'food_types' => [
        'veg' => 'Veg',
        'non_veg' => 'Non-veg',
        'egg' => 'Contains egg',
    ],

    // Where a reservation came from (reservations.source).
    'sources' => [
        'manual' => 'Added by the team',
        'website' => 'Website',
    ],

    /*
    | Reservations. Website requests start as pending unless `auto_confirm`
    | is on. Hours are the local times guests can book (last start = closes
    | minus nothing: the start time must be before `closes`).
    */
    'reservations' => [
        'online' => true,
        'auto_confirm' => false,
        'duration_minutes' => 90,
        'opens' => '11:00',
        'closes' => '22:00',
        'slot_interval' => 30,
        'max_party_size' => 12,
        'min_notice_minutes' => 60,
        'max_days_ahead' => 30,
    ],

    'duration_options' => [30, 45, 60, 90, 120, 150, 180],
    'slot_interval_options' => [15, 30, 60],
    'max_party_size_limit' => 100,

    // Reservation requests per visitor IP and business per hour.
    'online_per_hour' => 6,

    'limits' => [
        'tables' => 200,
    ],

    // The kitchen screen reloads its queue this often (seconds).
    'kitchen_refresh_seconds' => 20,

    'per_page' => 25,

];
