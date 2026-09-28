<?php

/*
|--------------------------------------------------------------------------
| Education engine defaults (Phase 10, ADR-020)
|--------------------------------------------------------------------------
|
| Tenant overrides live in the `education` tenant setting (Settings → Education).
| A business type can ship its own with `configuration.education` in config/catalog.php.
|
*/

return [

    // Instalments offered on the admission form by default, one month apart.
    'default_instalments' => 1,
    'max_instalments' => 24,

    // `fee.due_soon` fires this many days before an unpaid instalment is due (0 = on the day).
    'reminder_days_before' => 3,
    'reminder_days_options' => [0, 1, 2, 3, 5, 7],

    'max_demo_days_ahead' => 90,
    'max_courses' => 200,
    'max_batches' => 500,

    'per_page' => 25,

];
