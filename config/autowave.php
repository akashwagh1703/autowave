<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform hosts
    |--------------------------------------------------------------------------
    |
    | Every request is classified by its Host header (ADR-007). Platform hosts
    | serve AutoWave itself; every other host is looked up in the `domains`
    | table and served as that tenant's public website.
    |
    | Locally, *.localhost resolves to 127.0.0.1 in modern browsers, so
    | tenant sites work at http://{slug}.autowave.localhost:8000.
    |
    */

    'root_domain' => env('AUTOWAVE_ROOT_DOMAIN', 'autowave.localhost'),

    'hosts' => [
        'marketing' => env('AUTOWAVE_MARKETING_HOST', 'autowave.localhost'),
        'app' => env('AUTOWAVE_APP_HOST', 'app.autowave.localhost'),
        'admin' => env('AUTOWAVE_ADMIN_HOST', 'admin.autowave.localhost'),
    ],

    /*
    | Subdomains of the root domain that can never belong to a tenant.
    */
    'reserved_subdomains' => [
        'www', 'app', 'admin', 'api', 'mail', 'smtp', 'ftp', 'static', 'assets', 'cdn',
        'media', 'help', 'support', 'status', 'docs', 'blog', 'dev', 'staging', 'test',
        'autowave', 'dashboard', 'billing', 'auth', 'login', 'account', 'accounts',
    ],

    /*
    | Seconds a host → tenant lookup is cached. Domain changes flush the entry.
    */
    'domain_cache_ttl' => (int) env('AUTOWAVE_DOMAIN_CACHE_TTL', 600),

    /*
    | Self-service onboarding (Phase 2).
    */
    'onboarding' => [
        // Businesses one user may create through onboarding (abuse guard; admins can create more later).
        'max_businesses_per_user' => (int) env('AUTOWAVE_MAX_BUSINESSES_PER_USER', 3),
        // Preset brand colours offered in the wizard; any valid hex is accepted.
        'brand_colors' => ['#4f46e5', '#db2777', '#0d9488', '#ea580c', '#16a34a', '#0284c7', '#7c3aed', '#1f2937'],
    ],

    /*
    | The tenant AutoWave uses for its own marketing, CRM and sales (dogfooding).
    */
    'internal_tenant' => [
        'name' => 'AutoWave Internal',
        'slug' => 'autowave-internal',
        'business_type' => 'autowave_internal',
    ],

    /*
    | Initial platform administrator created by PlatformAdminSeeder.
    */
    'platform_admin' => [
        'name' => env('AUTOWAVE_ADMIN_NAME', 'AutoWave Admin'),
        'email' => env('AUTOWAVE_ADMIN_EMAIL', 'admin@autowave.in'),
        'password' => env('AUTOWAVE_ADMIN_PASSWORD'),
    ],

    /*
    | Marketing site policies (/privacy, /terms, /refunds). Company name, address, email, phone and GSTIN
    | come from Super Admin → Settings → Billing (seller). Bump `updated` whenever the policy text changes.
    */
    'legal' => [
        'updated' => '2026-10-05',
        // City whose courts handle disputes, e.g. "Pune, Maharashtra". Empty: the registered office's courts.
        'jurisdiction' => env('AUTOWAVE_LEGAL_JURISDICTION'),
        // Named Grievance Officer (DPDP Act); reachable at the seller email.
        'grievance_officer' => env('AUTOWAVE_GRIEVANCE_OFFICER'),
        // Working days to send an approved refund back.
        'refund_days' => 7,
        // Days after a payment within which a refund can be requested.
        'refund_request_days' => 30,
    ],

    // Defaults for Super Admin → Settings until a platform admin changes them.
    'platform_settings' => [
        // New sign-ups must confirm their email before using the app.
        'require_email_verification' => true,
    ],

];
