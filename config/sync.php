<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sync Mode
    |--------------------------------------------------------------------------
    |
    | Determines the server's role in the sync architecture:
    | - 'cloud': Central cloud server that edge servers sync with
    | - 'edge': Local branch server that syncs with cloud server
    | - 'standalone': No server-to-server sync (client-only sync)
    |
    */
    'mode' => env('SYNC_MODE', 'standalone'),

    /*
    |--------------------------------------------------------------------------
    | Cloud Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for connecting to the cloud server (used by edge servers)
    |
    */
    'cloud_server_url' => env('CLOUD_SERVER_URL', 'https://pos-cloud.example.com/api'),
    'cloud_server_token' => env('CLOUD_SERVER_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Local Server Configuration
    |--------------------------------------------------------------------------
    |
    | Unique identifier for this server instance
    |
    */
    'local_server_id' => env('LOCAL_SERVER_ID', 'standalone-001'),
    'local_server_url' => env('LOCAL_SERVER_URL', 'http://localhost:8000/api'),

    /*
    |--------------------------------------------------------------------------
    | Sync Schedule
    |--------------------------------------------------------------------------
    |
    | How often to sync with cloud server (in seconds)
    |
    */
    'sync_interval' => env('SYNC_INTERVAL', 30), // 30 seconds

    /*
    |--------------------------------------------------------------------------
    | Auto Sync
    |--------------------------------------------------------------------------
    |
    | Whether to automatically sync on schedule
    |
    */
    'auto_sync' => env('AUTO_SYNC', true),

    /*
    |--------------------------------------------------------------------------
    | Sync Entities
    |--------------------------------------------------------------------------
    |
    | Which entities to sync between servers
    |
    */
    'entities' => [
        'sales',
        'customers',
        'products',
        'categories',
        'branch_products',
        'payment_methods'
    ],

    /*
    |--------------------------------------------------------------------------
    | Conflict Resolution
    |--------------------------------------------------------------------------
    |
    | Strategy for resolving conflicts:
    | - 'cloud_wins': Cloud server version always wins
    | - 'edge_wins': Edge server version always wins
    | - 'latest_wins': Most recently updated version wins
    | - 'manual': Conflicts require manual resolution
    |
    */
    'conflict_resolution' => env('SYNC_CONFLICT_RESOLUTION', 'latest_wins'),

    /*
    |--------------------------------------------------------------------------
    | Connection Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time to wait for sync requests (in seconds)
    |
    */
    'timeout' => env('SYNC_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | How many times to retry failed sync operations
    |
    */
    'max_retries' => env('SYNC_MAX_RETRIES', 3),
    'retry_delay' => env('SYNC_RETRY_DELAY', 5), // seconds
];
