<?php

/*
|--------------------------------------------------------------------------
| Service + booking defaults (Phase 4)
|--------------------------------------------------------------------------
|
| Tenant overrides live in the `booking` tenant setting (Settings → Booking).
| A business type can ship its own defaults with `configuration.booking` in
| config/catalog.php (e.g. hourly slots for turfs); it is copied into the
| tenant setting when the tenant is created.
|
| Working hours use ISO weekdays (1 = Monday … 7 = Sunday) and local
| wall-clock times in the tenant's timezone.
|
*/

return [

    // Minutes between offered start times.
    'slot_interval' => 15,
    'slot_intervals' => [5, 10, 15, 20, 30, 45, 60, 90, 120],

    // New appointments made by the team start as confirmed; otherwise as pending.
    'auto_confirm' => true,

    // Copied to a new staff member / resource when no hours are given.
    'default_hours' => [
        ['weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '20:00'],
        ['weekday' => 2, 'starts_at' => '10:00', 'ends_at' => '20:00'],
        ['weekday' => 3, 'starts_at' => '10:00', 'ends_at' => '20:00'],
        ['weekday' => 4, 'starts_at' => '10:00', 'ends_at' => '20:00'],
        ['weekday' => 5, 'starts_at' => '10:00', 'ends_at' => '20:00'],
        ['weekday' => 6, 'starts_at' => '10:00', 'ends_at' => '20:00'],
    ],

    // What a bookable resource is called when the business type does not say (booking_resource_label).
    'default_resource_label' => 'Staff',

    'duration' => ['min' => 5, 'max' => 720],

    /*
    | Prices of bookings without a service (Phase 10, e.g. a turf slot): an hourly rate per
    | resource plus up to `max_rates` extra rates (peak hours, weekends). The first rate covering
    | a minute wins; otherwise the hourly rate applies.
    */
    'pricing' => [
        'max_rates' => 6,
        'max_rate' => 999999.99,
    ],

    'max_windows_per_day' => 4,

    /*
    | Online booking from the public website (Phase 6). Tenant values live in the
    | `booking` setting under `online`; these are the defaults. Website bookings start
    | as pending unless `auto_confirm` is on, so the team checks them first.
    */
    'online' => [
        'enabled' => true,
        'auto_confirm' => false,
        'min_notice_minutes' => 60,
        'max_days_ahead' => 30,
        // Visitors may choose "Any available" instead of a specific staff member or resource.
        'allow_any_resource' => true,
    ],
    'online_notice_options' => [0, 30, 60, 120, 240, 720, 1440, 2880],
    'online_days_ahead_options' => [7, 14, 30, 60, 90],
    // Bookings per visitor IP and business per hour.
    'online_per_hour' => 10,

    // Where an appointment came from (appointments.source).
    'sources' => [
        'manual' => 'Added by the team',
        'website' => 'Online booking',
        'whatsapp' => 'WhatsApp',
    ],

    'per_page' => 25,

];
