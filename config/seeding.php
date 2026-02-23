<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Seed Size
    |--------------------------------------------------------------------------
    |
    | Use "small" for fast seeding (e.g. remote DB, CI, quick tests).
    | Use "large" for full demo data locally.
    |
    | Set via env: SEED_SIZE=small or SEED_SIZE=large
    | Or run: php artisan seed:run --size=small
    |
    */
    'size' => env('SEED_SIZE', 'large'),

    /*
    |--------------------------------------------------------------------------
    | Size-specific limits (used by seeders)
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'small' => [
            'businesses' => 1,
            'sales' => 50,
            'customers' => 10,
            'refund_requests' => [2, 5],
            'quick_sales' => 3,
            'stock_transfers' => [2, 5],
            'stock_writeoffs' => [2, 5],
            'batches_per_product' => [1, 2],
            'shifts_per_day' => [1, 2],
        ],
        'large' => [
            'businesses' => 2,
            'sales' => 1000,
            'customers' => 50,
            'refund_requests' => [10, 20],
            'quick_sales' => 15,
            'stock_transfers' => [5, 15],
            'stock_writeoffs' => [5, 15],
            'batches_per_product' => [2, 5],
            'shifts_per_day' => [1, 3],
        ],
    ],

];
