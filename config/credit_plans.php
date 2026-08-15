<?php

return [
    '5-credits' => [
        'price_id' => env('STRIPE_PRICE_5_CREDIT'),
        'type' => 'one_time',
        'credits' => 5,
        'label' => '5 Credits',
        'amount_display' => '£69.99',
    ],
    'premium-monthly' => [
        'price_id' => env('STRIPE_PRICE_PREMIUM_MONTHLY'),
        'type' => 'subscription',
        'credits' => 20,
        'label' => 'Premium Membership',
        'amount_display' => '£19.99/month',
    ],
];
