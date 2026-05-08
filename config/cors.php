<?php

/**
 * CORS — permissive defaults for browser clients (SPA, Electron, any origin).
 *
 * - paths `*` applies these rules to every route (not only api/*).
 * - allowed_origins `*` allows any Origin. Browsers forbid combining this with
 *   supports_credentials=true; keep credentials false unless you switch to an
 *   explicit origin allowlist and reflect the request Origin in middleware.
 */
return [
    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
