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

    //Subscription Plans
    'premium-monthly' => [
        'price_id' => env('STRIPE_PRICE_PREMIUM_MONTHLY'),
        'type' => 'subscription',
        'credits' => 20,
        'label' => 'Premium Membership',
        'amount_display' => '£19.99/month',
    ],
];
