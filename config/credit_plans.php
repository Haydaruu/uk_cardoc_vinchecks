<?php

return [
    
    //Credit Plans
    '1-credit' => [
        'price_id' => env('STRIPE_PRICE_1_CREDIT'),
        'type' => 'one_time',
        'credits' => 1,
        'label' => '1 Credit',
        'amount_display' => '£19.99',
    ],
    '3-credits' => [
        'price_id' => env('STRIPE_PRICE_3_CREDIT'),
        'type' => 'one_time',
        'credits' => 3,
        'label' => '3 Credits',
        'amount_display' => '£53.99',
    ],
    '5-credits' => [
        'price_id' => env('STRIPE_PRICE_5_CREDIT'),
        'type' => 'one_time',
        'credits' => 5,
        'label' => '5 Credits',
        'amount_display' => '£69.99',
    ],

    // Subscription Plans
    'premium-monthly' => [
        'price_id' => env('STRIPE_PRICE_PREMIUM_MONTHLY'),
        'type' => 'subscription',
        'credits' => 15,
        'label' => 'Premium',
        'amount_display' => '£39.99/month',
    ],

    'premium-plus-monthly' => [
        'price_id' => env('STRIPE_PRICE_PREMIUM_PLUS_MONTHLY'),
        'type' => 'subscription',
        'credits' => 25,
        'label' => 'Premium Plus',
        'amount_display' => '£59.99/month',
    ],

    'premium-max-monthly' => [
        'price_id' => env('STRIPE_PRICE_PREMIUM_MAX_MONTHLY'),
        'type' => 'subscription',
        'credits' => 35,
        'label' => 'Premium Max',
        'amount_display' => '£89.99/month',
    ],
];
