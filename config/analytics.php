<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Read analytics from rollup table (analytics_daily_summaries)
    |--------------------------------------------------------------------------
    |
    | When true, period metrics, branch contributions, trends, P/L, and growth
    | trends read from rollups. Run `php artisan analytics:rollup` to backfill.
    |
    */

    'use_rollups' => env('ANALYTICS_USE_ROLLUPS', false),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (seconds) for analytics endpoints
    |--------------------------------------------------------------------------
    |
    | When rollups are enabled, shorter TTL is usually enough (live reads are cheap).
    |
    */

    'cache_ttl_seconds' => (int) env('ANALYTICS_CACHE_TTL_SECONDS', 900),

    'rollup_cache_ttl_seconds' => (int) env('ANALYTICS_ROLLUP_CACHE_TTL_SECONDS', 120),

];
