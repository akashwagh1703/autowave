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

    'max_windows_per_day' => 4,

    'per_page' => 25,

];
