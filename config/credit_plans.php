<?php

return [

    // Credit Plans
    '1-credit' => [
        'price_id' => env('STRIPE_PRICE_1_CREDIT'),
        'type' => 'one_time',
        'credits' => 1,
        'label' => '1 Credit',
        'amount_minor' => 2800,
        'currency' => 'GBP',
        'amount_display' => '£28.00',
    ],
    '3-credits' => [
        'price_id' => env('STRIPE_PRICE_3_CREDIT'),
        'type' => 'one_time',
        'credits' => 3,
        'label' => '3 Credits',
        'amount_minor' => 7499,
        'currency' => 'GBP',
        'amount_display' => '£74.99',
    ],
    '5-credits' => [
        'price_id' => env('STRIPE_PRICE_5_CREDIT'),
        'type' => 'one_time',
        'credits' => 5,
        'label' => '5 Credits',
        'amount_minor' => 11999,
        'currency' => 'GBP',
        'amount_display' => '£119.99',
    ],

    // Subscription Plans
    'premium-monthly' => [
        'price_id' => env('STRIPE_PRICE_PREMIUM_MONTHLY'),
        'paypal_plan_id' => env('PAYPAL_PLAN_PREMIUM_MONTHLY'),
        'type' => 'subscription',
        'credits' => 2,
        'label' => 'Premium',
        'amount_minor' => 4499,
        'currency' => 'GBP',
        'amount_display' => '£44.99/month',
    ],

    'premium-plus-monthly' => [
        'price_id' => env('STRIPE_PRICE_PREMIUM_PLUS_MONTHLY'),
        'paypal_plan_id' => env('PAYPAL_PLAN_PREMIUM_PLUS_MONTHLY'),
        'type' => 'subscription',
        'credits' => 3,
        'label' => 'Premium Plus',
        'amount_minor' => 6499,
        'currency' => 'GBP',
        'amount_display' => '£64.99/month',
    ],

    'premium-max-monthly' => [
        'price_id' => env('STRIPE_PRICE_PREMIUM_MAX_MONTHLY'),
        'paypal_plan_id' => env('PAYPAL_PLAN_PREMIUM_MAX_MONTHLY'),
        'type' => 'subscription',
        'credits' => 5,
        'label' => 'Premium Max',
        'amount_minor' => 10999,
        'currency' => 'GBP',
        'amount_display' => '£109.99/month',
    ],
];
