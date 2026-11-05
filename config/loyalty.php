<?php

/*
|--------------------------------------------------------------------------
| Loyalty stamp cards
|--------------------------------------------------------------------------
|
| Tenant overrides live in the `loyalty` tenant setting (Settings → Loyalty).
| Walk-in customers join via a public QR on the business website; stamps are
| earned when an order (or appointment) is completed, and redeemed by staff.
|
*/

return [

    'enabled' => true,

    // Stamps needed for one free reward (e.g. 10 = buy 9, get the 10th free).
    'stamps_required' => 10,

    /*
    | How stamps are counted on a completed order:
    | - order: one stamp per qualifying order
    | - item: one stamp per unit of a qualifying product
    */
    'earn_per' => 'item',

    // Empty list = every product counts. Non-empty = only these product ids.
    'product_ids' => [],

    // Booking businesses: one stamp when an appointment is completed.
    'earn_appointments' => false,

    // Shown to staff and on the customer's card (e.g. "Free coffee").
    'reward_label' => 'Free item',

    'join_heading' => 'Join our rewards',
    'join_blurb' => 'Enter your name and phone to collect stamps. Show your card when you visit.',

    // Ask for birthday on the public join form.
    'collect_birthday' => true,

    /*
    | When to fire birthday greetings / optional free reward:
    | - day: on the birthday itself
    | - month: on the 1st of the birth month (once per year)
    */
    'birthday_mode' => 'day',

    // Auto top-up stamps so one reward can be redeemed (Settings → Loyalty).
    'birthday_grant_reward' => false,

    'birthday_reward_label' => 'Birthday treat',

];
