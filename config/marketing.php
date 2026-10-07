<?php

/*
| Marketing site (autowave.co.in): search titles and descriptions per page, the industry pages and the
| sales WhatsApp number. Page copy lives in resources/js/pages/marketing and modules/marketing.
*/

return [

    // Sales WhatsApp number for "Chat on WhatsApp" (e.g. +919876543210). Empty: the seller phone from Super Admin → Settings → Billing.
    'whatsapp' => env('AUTOWAVE_SALES_WHATSAPP'),

    'whatsapp_message' => 'Hi, I would like to know more about AutoWave for my business.',

    'pages' => [
        'home' => [
            'title' => 'Website, online bookings and WhatsApp for local businesses',
            'description' => 'Get a website for your business, take bookings and orders online, and let WhatsApp reply to customers for you. Made for salons, clinics, turfs, coaching centres, cafes and local stores. Free 14-day trial.',
        ],
        'pricing' => [
            'title' => 'Pricing',
            'description' => 'Simple monthly and yearly plans for your website, bookings, CRM and WhatsApp. Start with a free 14-day trial, no payment details needed.',
        ],
        'demo' => [
            'title' => 'Book a free demo',
            'description' => 'See how AutoWave can run your website, bookings, leads and WhatsApp follow-ups. Book a free 20-minute demo for your business.',
        ],
        'contact' => [
            'title' => 'Contact us',
            'description' => 'Get help with AutoWave, plans and payments, or your data. We usually reply within one working day.',
        ],
        'privacy' => [
            'title' => 'Privacy policy',
            'description' => 'How AutoWave collects, uses and protects personal data under the Digital Personal Data Protection Act, 2023.',
        ],
        'terms' => [
            'title' => 'Terms of service',
            'description' => 'The terms for using AutoWave, the business website, CRM and automation platform.',
        ],
        'refunds' => [
            'title' => 'Refund and cancellation policy',
            'description' => 'How cancellations, refunds and plan changes work on AutoWave.',
        ],
    ],

    // /for/{slug}; the page content for each slug is in resources/js/modules/marketing/industries.js.
    // `sample`: a live example website for "See a sample website" (full https URL; empty hides the link).
    'industries' => [
        'salons' => [
            'name' => 'Salons & spas',
            'title' => 'Salon software with online booking and WhatsApp reminders',
            'description' => 'A beautiful salon website with online appointments, staff calendars, client history, offers and WhatsApp reminders. Free 14-day trial.',
            'sample' => env('AUTOWAVE_SAMPLE_SALONS'),
        ],
        'clinics' => [
            'name' => 'Clinics',
            'title' => 'Clinic website and appointment booking software',
            'description' => 'Let patients book appointments online, send reminders on WhatsApp and keep every enquiry in one place. Free 14-day trial.',
            'sample' => env('AUTOWAVE_SAMPLE_CLINICS'),
        ],
        'turfs' => [
            'name' => 'Turfs & sports venues',
            'title' => 'Turf booking software with online slots',
            'description' => 'Show live slot availability, take bookings around the clock and stop double bookings for your turf, court or sports venue. Free 14-day trial.',
            'sample' => env('AUTOWAVE_SAMPLE_TURFS'),
        ],
        'coaching' => [
            'name' => 'Coaching centres',
            'title' => 'Coaching centre software for enquiries, demos and admissions',
            'description' => 'Capture enquiries, schedule demo classes, follow up on WhatsApp and turn more students into admissions. Free 14-day trial.',
            'sample' => env('AUTOWAVE_SAMPLE_COACHING'),
        ],
        'cafes' => [
            'name' => 'Cafes & restaurants',
            'title' => 'Cafe and restaurant website with online menu, orders and table reservations',
            'description' => 'An online menu customers can order from, table reservations, offers and a kitchen view for your cafe or restaurant. Free 14-day trial.',
            'sample' => env('AUTOWAVE_SAMPLE_CAFES'),
        ],
        'stores' => [
            'name' => 'Local stores',
            'title' => 'Online store for local shops with WhatsApp orders',
            'description' => 'Put your shop online with a product catalogue, pickup and delivery orders, stock tracking and repeat-customer offers. Free 14-day trial.',
            'sample' => env('AUTOWAVE_SAMPLE_STORES'),
        ],
    ],

];
