<?php

return [
    'stateful' => array_filter(explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', 'localhost:5174,localhost:5173'))),
    'routes' => true,
];
