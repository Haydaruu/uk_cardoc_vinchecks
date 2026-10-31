<?php

return [
    'allow_user_seeding' => env('ALLOW_DEMO_USER_SEEDING', false),

    'admin' => [
        'email' => env('DEMO_ADMIN_EMAIL'),
        'password' => env('DEMO_ADMIN_PASSWORD'),
    ],

    'super_admin' => [
        'email' => env('DEMO_SUPER_ADMIN_EMAIL'),
        'password' => env('DEMO_SUPER_ADMIN_PASSWORD'),
    ],
];
