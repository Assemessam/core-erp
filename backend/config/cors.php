<?php

return [
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login', 'logout', 'register',
        'forgot-password', 'reset-password',
        'email/verification-notification',
    ],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'allowed_origins' => array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5174'))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'Origin', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-CSRF-TOKEN'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
