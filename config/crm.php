<?php

/*
|--------------------------------------------------------------------------
| CRM defaults (Phase 3)
|--------------------------------------------------------------------------
|
| Copied into each tenant's lead_stages / lead_sources when the tenant is
| created (ProvisionCrm). Tenants edit their own copies afterwards, so
| changing this file only affects new tenants.
|
| A business type can replace the stage or source list with
| `configuration.lead_stages` / `configuration.lead_sources` in config/catalog.php.
|
| Stage outcome: open (in progress) | won (converted to a customer) | lost.
| Every tenant always keeps at least one active stage of each outcome.
|
*/

return [

    'lead_stages' => [
        ['code' => 'new', 'name' => 'New', 'color' => '#6366f1', 'outcome' => 'open'],
        ['code' => 'contacted', 'name' => 'Contacted', 'color' => '#0ea5e9', 'outcome' => 'open'],
        ['code' => 'qualified', 'name' => 'Qualified', 'color' => '#14b8a6', 'outcome' => 'open'],
        ['code' => 'follow_up', 'name' => 'Follow-up', 'color' => '#f59e0b', 'outcome' => 'open'],
        ['code' => 'converted', 'name' => 'Converted', 'color' => '#16a34a', 'outcome' => 'won'],
        ['code' => 'lost', 'name' => 'Lost', 'color' => '#94a3b8', 'outcome' => 'lost'],
    ],

    'lead_sources' => [
        ['code' => 'manual', 'name' => 'Manual entry'],
        ['code' => 'website', 'name' => 'Website'],
        ['code' => 'whatsapp', 'name' => 'WhatsApp'],
        ['code' => 'instagram', 'name' => 'Instagram'],
        ['code' => 'facebook', 'name' => 'Facebook'],
        ['code' => 'google', 'name' => 'Google'],
        ['code' => 'qr', 'name' => 'QR code'],
        ['code' => 'landing_page', 'name' => 'Landing page'],
        ['code' => 'campaign', 'name' => 'Campaign'],
        ['code' => 'referral', 'name' => 'Referral'],
        ['code' => 'walk_in', 'name' => 'Walk-in'],
    ],

    // Source used when a lead is entered by hand without choosing one.
    'default_source' => 'manual',

    // Activity types a team member can log by hand. System types (created, stage_changed,
    // assigned, converted, lost, reactivated, updated) are written by the application.
    'loggable_activities' => [
        'note' => 'Note',
        'call' => 'Call',
        'whatsapp' => 'WhatsApp',
        'email' => 'Email',
        'meeting' => 'Meeting',
    ],

    // Logging one of these counts as contacting the lead (updates last_contacted_at).
    'contact_activities' => ['call', 'whatsapp', 'email', 'meeting'],

    // Country calling code added to local numbers when normalising phones for matching.
    'default_country_code' => env('AUTOWAVE_DEFAULT_COUNTRY_CODE', '91'),

    'per_page' => 25,

];
