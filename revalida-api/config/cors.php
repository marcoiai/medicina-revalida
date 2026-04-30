<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:5173'),
        env('FRONTEND_URL_ALT', 'http://127.0.0.1:5173'),
    ],

    'allowed_origins_patterns' => [
        '#^https?://(localhost|127\.0\.0\.1|0\.0\.0\.0)(:\d+)?$#',
        '#^https?://192\.168\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        '#^https?://10\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        '#^https://[a-z0-9-]+\.ngrok-free\.(app|dev|pizza)$#',
        '#^https://[a-z0-9-]+\.ngrok\.(app|dev|pizza)$#',
        '#^https://[a-z0-9-]+\.ngrok\.io$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
