<?php

/**
 * CORS for SPA / Electron renderers (Vite dev servers, packaged apps loading http://localhost:*).
 *
 * Set CORS_ALLOWED_ORIGINS to a comma-separated list of extra origins (e.g. your deployed frontend).
 * Localhost-style origins are matched via allowed_origins_patterns so any dev port works.
 */
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_merge(
        array_filter([env('FRONTEND_URL')]),
        array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))),
    ))),

    'allowed_origins_patterns' => [
        '#^https?://localhost(:\d+)?$#',
        '#^https?://127\.0\.0\.1(:\d+)?$#',
        '#^https?://\[::1\](:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
