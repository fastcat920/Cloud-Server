<?php

return [
    'subscription' => [
        'enabled' => env('FASTCAT_SUBSCRIPTION_ENABLED', false),
        'flag' => env('FASTCAT_SUBSCRIPTION_FLAG', 'fastcat-v1'),
        'active_kid' => env('FASTCAT_ACTIVE_KID', ''),
        'current' => [
            'kid' => env('FASTCAT_KEY_CURRENT_ID', ''),
            'key' => env('FASTCAT_KEY_CURRENT', ''),
        ],
        'next' => [
            'kid' => env('FASTCAT_KEY_NEXT_ID', ''),
            'key' => env('FASTCAT_KEY_NEXT', ''),
        ],
    ],
];
