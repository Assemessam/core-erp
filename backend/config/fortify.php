<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'middleware' => ['web'],
    'auth_middleware' => 'auth',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'views' => false,
    'home' => rtrim((string) env('FRONTEND_URL', 'http://localhost:5174'), '/').'/app',
    'prefix' => '',
    'domain' => null,
    'lowercase_usernames' => true,
    'limiters' => ['login' => 'login'],
    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
    ],
];
