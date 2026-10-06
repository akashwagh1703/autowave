<?php

/*
|--------------------------------------------------------------------------
| WhatsApp assistant (ADR-021)
|--------------------------------------------------------------------------
|
| Answers a contact's WhatsApp messages with a menu built from what the
| business offers. It only ever replies to a message the contact just sent,
| so it stays inside WhatsApp's 24-hour window and needs no templates.
| Tenant setting `whatsapp_assistant` (Settings → WhatsApp assistant)
| overrides the defaults below.
|
*/

return [

    // Menu items in the order they are offered. Each shows only when the business has it.
    'items' => ['book', 'reserve', 'order', 'services', 'rates', 'courses', 'offers', 'faq', 'info', 'human'],

    'defaults' => [
        'enabled' => false,
        'show_image' => true,
        'pause_hours' => 12,
        'alert_team' => true,
    ],

    'welcome_max' => 600,
    'pause_hours' => ['min' => 1, 'max' => 72],

    // A conversation starts again from the welcome after this much silence.
    'session_minutes' => 30,

    // Messages older than this (a delayed webhook) get no automatic answer.
    'max_age_minutes' => 15,

    // Replies per conversation per minute; anything beyond is left for the team.
    'rate_limit' => 8,

    // After this many messages the assistant did not understand in a row, it hands over to the team.
    'misses_before_handover' => 2,

    // Typed words that open a menu item. Matched on whole words, only in short messages.
    'keyword_max_words' => 5,
    'keywords' => [
        'menu' => ['hi', 'hii', 'hiii', 'hello', 'hey', 'helo', 'hlo', 'namaste', 'namaskar', 'menu', 'options', 'option', 'help', 'start over', 'main menu', 'good morning', 'good afternoon', 'good evening', 'back'],
        'book' => ['book', 'booking', 'appointment', 'appointments', 'slot', 'slots'],
        'reserve' => ['reserve', 'reservation', 'table'],
        'order' => ['order', 'orders', 'buy', 'delivery', 'pickup'],
        'services' => ['service', 'services', 'price', 'prices', 'pricing', 'rate card', 'cost', 'charges'],
        'rates' => ['rate', 'rates', 'price', 'prices', 'pricing', 'charges'],
        'courses' => ['course', 'courses', 'class', 'classes', 'batch', 'batches', 'fees', 'fee', 'demo'],
        'offers' => ['offer', 'offers', 'discount', 'discounts', 'deal', 'deals', 'coupon'],
        'faq' => ['faq', 'faqs', 'questions'],
        'info' => ['timing', 'timings', 'time', 'hours', 'open', 'address', 'location', 'where', 'map', 'directions', 'website', 'site', 'contact', 'phone', 'number'],
        'human' => ['human', 'agent', 'person', 'staff', 'call', 'talk', 'executive', 'support', 'callback'],
    ],

    'queue' => env('MESSAGING_QUEUE', 'messaging'),

];
