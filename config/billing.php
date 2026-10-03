<?php

/*
|--------------------------------------------------------------------------
| Billing (plans, subscriptions, payments)
|--------------------------------------------------------------------------
|
| Amounts are in paise (₹1 = 100). Plans are seeded from `plans` once and then
| edited in Super Admin → Plans; the seeder never overwrites an existing plan.
| Payment details, GST and the payment method switches are platform settings
| (Super Admin → Settings → Billing), defaults below. See docs/05-features/billing.md.
|
*/

return [

    'currency' => 'INR',

    // Every new business starts on this plan for `days`, no payment asked.
    'trial' => [
        'plan' => 'trial',
        'days' => (int) env('BILLING_TRIAL_DAYS', 14),
    ],

    // After the paid period ends: full access for `grace_days`, then read-only until `lock_after_days`,
    // then locked (only Billing; website offline). A payment waiting for approval keeps full access for
    // `pending_access_days` after it was submitted.
    'grace_days' => 7,
    'lock_after_days' => 30,
    'pending_access_days' => 3,

    // Emails before the end of a trial or paid period.
    'reminder_days' => [7, 3, 1],

    'periods' => [
        'monthly' => ['label' => 'Monthly', 'months' => 1, 'days' => 30],
        'yearly' => ['label' => 'Yearly', 'months' => 12, 'days' => 365],
    ],

    // Online payments: `none` until a gateway account exists. Keys stay in .env, never in the browser.
    'gateway' => env('BILLING_GATEWAY', 'none'),
    'gateways' => [
        'razorpay' => [
            'key_id' => env('RAZORPAY_KEY_ID'),
            'key_secret' => env('RAZORPAY_KEY_SECRET'),
            'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        ],
    ],

    'gst' => [
        'rate' => 18,
    ],

    // Invoice numbers: {prefix}/{financial year}/{0001}, gapless per financial year (April–March).
    'invoice_prefix' => env('BILLING_INVOICE_PREFIX', 'AW'),

    // Payment screenshots (owner) and the UPI QR image (Super Admin), on config('files.disks.private').
    'proof' => [
        'max_kb' => 5120,
        'mimetypes' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
        'mimes' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
    ],
    'qr' => [
        'max_kb' => 2048,
        'min_dimension' => 100,
        'max_dimension' => 4000,
    ],

    'methods' => [
        'upi' => 'UPI',
        'bank_transfer' => 'Bank transfer',
        'cash' => 'Cash',
        'cheque' => 'Cheque',
        'online' => 'Online',
        'complimentary' => 'Complimentary',
    ],

    // Methods an owner can report; the rest are recorded by Super Admin.
    'owner_methods' => ['upi', 'bank_transfer'],

    // Defaults for Super Admin → Settings → Billing (platform setting `billing`).
    'settings' => [
        'enforce' => false,
        'manual_enabled' => true,
        'online_enabled' => false,
        'seller' => ['name' => 'AutoWave', 'address' => null, 'email' => null, 'phone' => null],
        'gst' => ['enabled' => false, 'gstin' => null, 'state_code' => null],
        'upi' => ['id' => null, 'payee' => null],
        'bank' => ['account_name' => null, 'account_number' => null, 'ifsc' => null, 'bank_name' => null],
        'instructions' => null,
        'qr' => null,
    ],

    /*
    | Plans seeded once. Limits: members, storage_mb, ai_tokens (per month), automations (active at once;
    | null = unlimited), instagram (inbox channel). `public` plans are offered to owners; the trial is not.
    */
    'plans' => [
        'trial' => [
            'name' => 'Free trial',
            'description' => 'Everything in Growth while you try AutoWave.',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'public' => false,
            'sort_order' => 0,
            'limits' => ['members' => 5, 'storage_mb' => 1024, 'ai_tokens' => 200000, 'automations' => 20, 'instagram' => true],
        ],
        'starter' => [
            'name' => 'Starter',
            'description' => 'Website, CRM, bookings and the WhatsApp inbox for a small team.',
            'price_monthly' => 49900,
            'price_yearly' => 499000,
            'public' => true,
            'sort_order' => 1,
            'limits' => ['members' => 2, 'storage_mb' => 1024, 'ai_tokens' => 100000, 'automations' => 5, 'instagram' => false],
        ],
        'growth' => [
            'name' => 'Growth',
            'description' => 'Unlimited automations, Instagram and more AI for a growing business.',
            'price_monthly' => 149900,
            'price_yearly' => 1499000,
            'public' => true,
            'sort_order' => 2,
            'limits' => ['members' => 5, 'storage_mb' => 5120, 'ai_tokens' => 500000, 'automations' => null, 'instagram' => true],
        ],
        'business' => [
            'name' => 'Business',
            'description' => 'A larger team, 20 GB of storage and the most AI.',
            'price_monthly' => 399900,
            'price_yearly' => 3999000,
            'public' => true,
            'sort_order' => 3,
            'limits' => ['members' => 15, 'storage_mb' => 20480, 'ai_tokens' => 2000000, 'automations' => null, 'instagram' => true],
        ],
    ],

    // The internal AutoWave business: this plan, never expires.
    'internal_plan' => 'business',

];
