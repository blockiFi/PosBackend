<?php

/**
 * Postman collection metadata: description, usage, body (required/optional), query/filters per route.
 * Key: "METHOD api/uri" (e.g. "GET api/sales"). Path params like {id} are kept in the key.
 * Body/query entries: key => [ 'required' => bool, 'type' => string, 'description' => string, 'example' => mixed ]
 * For query, 'type' and 'example' are optional.
 */
return [
    // ---- Authentication ----
    'GET api/user' => [
        'description' => 'Returns the currently authenticated user (includes profile_image_url).',
        'usage' => 'Requires valid Bearer token. No body or query.',
        'body' => [],
        'query' => [],
        'noAuth' => false,
    ],
    'PUT api/user' => [
        'description' => 'Update current user profile.',
        'usage' => 'Send optional name and/or profile_image (multipart). Returns updated user.',
        'body' => [
            'name' => ['required' => false, 'type' => 'string', 'description' => 'Display name', 'example' => 'New Name'],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/register' => [
        'description' => 'Create a new user account.',
        'usage' => 'Public. Body: name, email, password, password_confirmation (min 8). Optional: profile_image. Test script saves token to auth_token.',
        'body' => [
            'name' => ['required' => true, 'type' => 'string', 'description' => 'Full name', 'example' => 'Jane Doe'],
            'email' => ['required' => true, 'type' => 'string', 'description' => 'Email', 'example' => 'jane@example.com'],
            'password' => ['required' => true, 'type' => 'string', 'description' => 'Min 8 characters', 'example' => 'SecurePass123!'],
            'password_confirmation' => ['required' => true, 'type' => 'string', 'description' => 'Must match password', 'example' => 'SecurePass123!'],
        ],
        'query' => [],
        'noAuth' => true,
    ],
    'POST api/login' => [
        'description' => 'Login with email and password.',
        'usage' => 'Public. Returns user and token. Save token to collection variable auth_token.',
        'body' => [
            'email' => ['required' => true, 'type' => 'string', 'description' => 'Email', 'example' => 'jane@example.com'],
            'password' => ['required' => true, 'type' => 'string', 'description' => 'Password', 'example' => 'SecurePass123!'],
        ],
        'query' => [],
        'noAuth' => true,
    ],
    'POST api/pin-login' => [
        'description' => 'Fast login with 6-digit PIN.',
        'usage' => 'Public. User must have PIN set. pin_code: exactly 6 digits.',
        'body' => [
            'pin_code' => ['required' => true, 'type' => 'string', 'description' => '6 digits', 'example' => '123456'],
        ],
        'query' => [],
        'noAuth' => true,
    ],
    'POST api/business-details-with-branch-auth' => [
        'description' => 'Get business and branch by branch authorization code.',
        'usage' => 'Body: auth_code (required). Returns business + branch when code is valid. Requires Bearer token.',
        'body' => [
            'auth_code' => ['required' => true, 'type' => 'string', 'description' => 'Branch auth code', 'example' => '847291'],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/pin/set' => [
        'description' => "Set or update a user's PIN.",
        'usage' => 'user_id: target user. pin_code: 6 digits. If setting own PIN, password required. Requires manage-pin-codes for other users.',
        'body' => [
            'user_id' => ['required' => true, 'type' => 'integer', 'description' => 'Target user ID', 'example' => 1],
            'pin_code' => ['required' => true, 'type' => 'string', 'description' => '6 digits', 'example' => '654321'],
            'password' => ['required' => false, 'type' => 'string', 'description' => 'Required when setting own PIN', 'example' => null],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/pin/remove' => [
        'description' => "Remove PIN for a user.",
        'usage' => 'Requires password (current user). Requires manage-pin-codes when removing another user\'s PIN.',
        'body' => [
            'user_id' => ['required' => true, 'type' => 'integer', 'description' => 'Target user ID', 'example' => 1],
            'password' => ['required' => true, 'type' => 'string', 'description' => 'Current user password', 'example' => 'SecurePass123!'],
        ],
        'query' => [],
        'noAuth' => false,
    ],

    // ---- Businesses ----
    'GET api/businesses' => [
        'description' => 'List all businesses the authenticated user belongs to.',
        'usage' => 'No business context required. No query filters.',
        'body' => [],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/businesses' => [
        'description' => 'Create a new business. Creator becomes owner. Creates main branch.',
        'usage' => 'All fields optional except name. currency 3-letter, country 2-letter. main_branch_name/code for initial branch.',
        'body' => [
            'name' => ['required' => true, 'type' => 'string', 'description' => 'Business name', 'example' => 'Acme Retail Ltd'],
            'legal_name' => ['required' => false, 'type' => 'string', 'description' => 'Legal name', 'example' => null],
            'slug' => ['required' => false, 'type' => 'string', 'description' => 'URL slug', 'example' => null],
            'email' => ['required' => false, 'type' => 'string', 'description' => 'Contact email', 'example' => null],
            'phone' => ['required' => false, 'type' => 'string', 'description' => 'Phone', 'example' => null],
            'address' => ['required' => false, 'type' => 'string', 'description' => 'Address', 'example' => null],
            'city' => ['required' => false, 'type' => 'string', 'description' => 'City', 'example' => null],
            'state' => ['required' => false, 'type' => 'string', 'description' => 'State/region', 'example' => null],
            'postal_code' => ['required' => false, 'type' => 'string', 'description' => 'Postal code', 'example' => null],
            'country' => ['required' => false, 'type' => 'string', 'description' => '2-letter country code', 'example' => 'US'],
            'currency' => ['required' => false, 'type' => 'string', 'description' => '3-letter currency', 'example' => 'USD'],
            'time_zone' => ['required' => false, 'type' => 'string', 'description' => 'Timezone', 'example' => 'America/New_York'],
            'tax_registration_number' => ['required' => false, 'type' => 'string', 'description' => 'Tax ID', 'example' => null],
            'default_tax_rate' => ['required' => false, 'type' => 'number', 'description' => 'Default tax %', 'example' => 10],
            'main_branch_name' => ['required' => false, 'type' => 'string', 'description' => 'Initial branch name', 'example' => 'Head Office'],
            'main_branch_code' => ['required' => false, 'type' => 'string', 'description' => 'Initial branch code', 'example' => 'MAIN'],
            'settings' => ['required' => false, 'type' => 'object', 'description' => 'Arbitrary settings', 'example' => []],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'GET api/businesses/{id}' => [
        'description' => 'Get one business by ID.',
        'usage' => 'Requires X-Business-Id header (business context).',
        'body' => [],
        'query' => [],
        'noAuth' => false,
    ],
    'PUT api/businesses/{id}' => [
        'description' => 'Update business.',
        'usage' => 'Same fields as create (all optional). Requires X-Business-Id.',
        'body' => [
            'name' => ['required' => false, 'type' => 'string', 'description' => 'Business name', 'example' => null],
            'email' => ['required' => false, 'type' => 'string', 'description' => 'Contact email', 'example' => null],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'DELETE api/businesses/{id}' => [
        'description' => 'Delete a business (soft delete).',
        'usage' => 'Requires X-Business-Id.',
        'body' => [],
        'query' => [],
        'noAuth' => false,
    ],

    // ---- Permissions ----
    'GET api/permissions' => [
        'description' => 'List all permissions (global, no business context).',
        'usage' => 'Returns permission names for role assignment.',
        'body' => [],
        'query' => [],
        'noAuth' => false,
    ],

    // ---- Branches ----
    'GET api/branches' => [
        'description' => 'List branches for the current business.',
        'usage' => 'X-Business-Id required. Filter by accessible branches for user.',
        'body' => [],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/branches' => [
        'description' => 'Create a new branch.',
        'usage' => 'X-Business-Id required. name required; code, address, etc. optional.',
        'body' => [
            'name' => ['required' => true, 'type' => 'string', 'description' => 'Branch name', 'example' => 'Downtown Store'],
            'code' => ['required' => false, 'type' => 'string', 'description' => 'Branch code', 'example' => 'DT'],
            'address' => ['required' => false, 'type' => 'string', 'description' => 'Address', 'example' => null],
            'city' => ['required' => false, 'type' => 'string', 'description' => 'City', 'example' => null],
            'phone' => ['required' => false, 'type' => 'string', 'description' => 'Phone', 'example' => null],
            'is_main' => ['required' => false, 'type' => 'boolean', 'description' => 'Is main branch', 'example' => false],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/branches/generate-auth-codes' => [
        'description' => 'Generate or refresh branch authorization codes.',
        'usage' => 'X-Business-Id required. Used for branch-based login/auth.',
        'body' => [],
        'query' => [],
        'noAuth' => false,
    ],
    'GET api/branches/{id}' => [
        'description' => 'Get one branch by ID.',
        'usage' => 'X-Business-Id required.',
        'body' => [],
        'query' => [],
        'noAuth' => false,
    ],
    'PUT api/branches/{id}' => [
        'description' => 'Update a branch.',
        'usage' => 'X-Business-Id required. Same fields as create (all optional).',
        'body' => [
            'name' => ['required' => false, 'type' => 'string', 'description' => 'Branch name', 'example' => null],
            'code' => ['required' => false, 'type' => 'string', 'description' => 'Branch code', 'example' => null],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'DELETE api/branches/{id}' => [
        'description' => 'Delete a branch.',
        'usage' => 'X-Business-Id required.',
        'body' => [],
        'query' => [],
        'noAuth' => false,
    ],

    // ---- Analytics ----
    'GET api/analytics/organization' => [
        'description' => 'Organization-wide analytics: revenue, profit, margin, branch contributions, revenue trend.',
        'usage' => 'X-Business-Id required. Use period or start_date+end_date. When both dates provided, they override period.',
        'body' => [],
        'query' => [
            'period' => ['required' => false, 'description' => 'today|week|month|year|custom', 'example' => 'month'],
            'start_date' => ['required' => false, 'description' => 'Required if period=custom or when using custom range (Y-m-d)', 'example' => '2026-02-01'],
            'end_date' => ['required' => false, 'description' => 'Required with start_date (Y-m-d)', 'example' => '2026-02-28'],
            'compare_previous' => ['required' => false, 'description' => 'Include previous period comparison (default true)', 'example' => '1'],
        ],
        'noAuth' => false,
    ],
    'GET api/analytics/branches' => [
        'description' => 'Branch-level analytics. One or all branches.',
        'usage' => 'X-Business-Id required. branch_id optional to scope to one branch.',
        'body' => [],
        'query' => [
            'branch_id' => ['required' => false, 'description' => 'Filter by branch', 'example' => '{{branch_id}}'],
            'period' => ['required' => false, 'description' => 'today|week|month|year|custom', 'example' => 'month'],
            'start_date' => ['required' => false, 'description' => 'Y-m-d', 'example' => null],
            'end_date' => ['required' => false, 'description' => 'Y-m-d', 'example' => null],
            'compare_previous' => ['required' => false, 'description' => 'Include previous period', 'example' => '1'],
        ],
        'noAuth' => false,
    ],
    'GET api/analytics/products' => [
        'description' => 'Product performance analytics: revenue, quantity, profit, margin per product.',
        'usage' => 'X-Business-Id required. Optional branch_id to scope.',
        'body' => [],
        'query' => [
            'branch_id' => ['required' => false, 'description' => 'Filter by branch', 'example' => null],
            'period' => ['required' => false, 'description' => 'today|week|month|year|custom', 'example' => 'month'],
            'start_date' => ['required' => false, 'description' => 'Y-m-d', 'example' => null],
            'end_date' => ['required' => false, 'description' => 'Y-m-d', 'example' => null],
            'limit' => ['required' => false, 'description' => 'Max products (1-100)', 'example' => '20'],
            'sort_by' => ['required' => false, 'description' => 'revenue|quantity|profit|margin', 'example' => 'revenue'],
            'direction' => ['required' => false, 'description' => 'asc|desc', 'example' => 'desc'],
        ],
        'noAuth' => false,
    ],
    'GET api/analytics/profit-loss' => [
        'description' => 'Profit and loss statement for period.',
        'usage' => 'X-Business-Id required. branch_id optional.',
        'body' => [],
        'query' => [
            'branch_id' => ['required' => false, 'description' => 'Filter by branch', 'example' => null],
            'period' => ['required' => false, 'description' => 'today|week|month|quarter|year|custom', 'example' => 'month'],
            'start_date' => ['required' => false, 'description' => 'Y-m-d', 'example' => null],
            'end_date' => ['required' => false, 'description' => 'Y-m-d', 'example' => null],
        ],
        'noAuth' => false,
    ],
    'GET api/analytics/growth-trends' => [
        'description' => 'Growth trends over intervals (daily/weekly/monthly).',
        'usage' => 'X-Business-Id required.',
        'body' => [],
        'query' => [
            'interval' => ['required' => false, 'description' => 'daily|weekly|monthly', 'example' => 'monthly'],
            'periods' => ['required' => false, 'description' => 'Number of periods', 'example' => '3'],
        ],
        'noAuth' => false,
    ],

    // ---- Sales ----
    'GET api/sales' => [
        'description' => 'List sales. Paginated.',
        'usage' => 'X-Business-Id required. Filter by branch, status, date range.',
        'body' => [],
        'query' => [
            'branch_id' => ['required' => false, 'description' => 'Filter by branch', 'example' => '{{branch_id}}'],
            'status' => ['required' => false, 'description' => 'completed|cancelled|pending', 'example' => null],
            'start_date' => ['required' => false, 'description' => 'Y-m-d', 'example' => null],
            'end_date' => ['required' => false, 'description' => 'Y-m-d', 'example' => null],
            'per_page' => ['required' => false, 'description' => 'Items per page', 'example' => '15'],
        ],
        'noAuth' => false,
    ],
    'POST api/sales' => [
        'description' => 'Create a new sale.',
        'usage' => 'X-Business-Id required. branch_id, sale_type, items (product_id, quantity, unit_price optional), payments optional.',
        'body' => [
            'branch_id' => ['required' => true, 'type' => 'integer', 'description' => 'Branch ID', 'example' => 1],
            'sale_type' => ['required' => true, 'type' => 'string', 'description' => 'e.g. pos', 'example' => 'pos'],
            'customer_id' => ['required' => false, 'type' => 'integer', 'description' => 'Customer ID', 'example' => null],
            'items' => ['required' => true, 'type' => 'array', 'description' => 'Array of { product_id, quantity, unit_price?, discount_percentage?, tax_rate? }', 'example' => []],
            'payments' => ['required' => false, 'type' => 'array', 'description' => 'Array of { payment_method_id, amount, reference_number? }', 'example' => []],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'GET api/sales/{id}' => [
        'description' => 'Get one sale by ID.',
        'usage' => 'X-Business-Id required.',
        'body' => [],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/sales/{id}/payments' => [
        'description' => 'Add payment to a sale.',
        'usage' => 'X-Business-Id required. payment_method_id, amount required.',
        'body' => [
            'payment_method_id' => ['required' => true, 'type' => 'integer', 'description' => 'Payment method ID', 'example' => 1],
            'amount' => ['required' => true, 'type' => 'number', 'description' => 'Amount', 'example' => 100.00],
            'reference_number' => ['required' => false, 'type' => 'string', 'description' => 'Reference', 'example' => null],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/sales/{id}/cancel' => [
        'description' => 'Cancel a sale.',
        'usage' => 'X-Business-Id required. Optional reason.',
        'body' => [
            'reason' => ['required' => false, 'type' => 'string', 'description' => 'Cancellation reason', 'example' => null],
        ],
        'query' => [],
        'noAuth' => false,
    ],

    // ---- Shifts ----
    'GET api/shifts' => [
        'description' => 'List shifts with filtering. Returns shifts (paginated) and statistics (total_shifts_count, total_gross_sales, shifts_by_status, etc.).',
        'usage' => 'X-Business-Id required. Filter by branch_id, user_id, status, has_discrepancy, filter (today/last_7_days), start_date, end_date.',
        'body' => [],
        'query' => [
            'branch_id' => ['required' => false, 'description' => 'Filter by branch', 'example' => '{{branch_id}}'],
            'user_id' => ['required' => false, 'description' => 'Filter by user', 'example' => null],
            'status' => ['required' => false, 'description' => 'open|closed|paused', 'example' => null],
            'has_discrepancy' => ['required' => false, 'description' => 'Only shifts with cash variance', 'example' => null],
            'filter' => ['required' => false, 'description' => 'today|last_7_days', 'example' => null],
            'start_date' => ['required' => false, 'description' => 'Y-m-d', 'example' => null],
            'end_date' => ['required' => false, 'description' => 'Y-m-d', 'example' => null],
        ],
        'noAuth' => false,
    ],
    'GET api/shifts/branch-summary' => [
        'description' => 'All-shifts summary for a branch: total gross sales, transactions, shifts by status, average basket, sales by payment type.',
        'usage' => 'X-Business-Id required. branch_id required. Optional start_date, end_date, user_id.',
        'body' => [],
        'query' => [
            'branch_id' => ['required' => true, 'description' => 'Branch ID', 'example' => '{{branch_id}}'],
            'start_date' => ['required' => false, 'description' => 'Y-m-d', 'example' => null],
            'end_date' => ['required' => false, 'description' => 'Y-m-d', 'example' => null],
            'user_id' => ['required' => false, 'description' => 'Filter by user', 'example' => null],
        ],
        'noAuth' => false,
    ],
    'POST api/shifts' => [
        'description' => 'Open a new shift.',
        'usage' => 'X-Business-Id required. branch_id, opening_balance required. Optional pin_code if PIN required.',
        'body' => [
            'branch_id' => ['required' => true, 'type' => 'integer', 'description' => 'Branch ID', 'example' => 1],
            'opening_balance' => ['required' => true, 'type' => 'number', 'description' => 'Starting cash', 'example' => 100.00],
            'pin_code' => ['required' => false, 'type' => 'string', 'description' => 'PIN if required', 'example' => null],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'GET api/shifts/current' => [
        'description' => 'Get current open/paused shift for user in branch.',
        'usage' => 'X-Business-Id required. branch_id optional to scope.',
        'body' => [],
        'query' => [
            'branch_id' => ['required' => false, 'description' => 'Branch ID', 'example' => '{{branch_id}}'],
        ],
        'noAuth' => false,
    ],
    'GET api/shifts/{id}' => [
        'description' => 'Get shift by ID.',
        'usage' => 'X-Business-Id required.',
        'body' => [],
        'query' => [],
        'noAuth' => false,
    ],
    'GET api/shifts/{id}/summary' => [
        'description' => 'Get shift summary (totals, cash/card/other).',
        'usage' => 'X-Business-Id required. Uses live sales for open/paused, stored for closed.',
        'body' => [],
        'query' => [],
        'noAuth' => false,
    ],
    'GET api/shifts/{id}/sales' => [
        'description' => 'List sales for this shift.',
        'usage' => 'X-Business-Id required. Paginated.',
        'body' => [],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/shifts/{id}/close' => [
        'description' => 'Close a shift. Requires closing balance and optional pin_code.',
        'usage' => 'X-Business-Id required. Only shift owner or manage shifts can close.',
        'body' => [
            'closing_balance' => ['required' => true, 'type' => 'number', 'description' => 'Actual cash count', 'example' => 150.00],
            'pin_code' => ['required' => false, 'type' => 'string', 'description' => 'PIN if required', 'example' => null],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/shifts/{id}/pause' => [
        'description' => 'Pause a shift.',
        'usage' => 'X-Business-Id required. Optional pin_code.',
        'body' => [
            'pin_code' => ['required' => false, 'type' => 'string', 'description' => 'PIN if required', 'example' => null],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/shifts/{id}/resume' => [
        'description' => 'Resume a paused shift.',
        'usage' => 'X-Business-Id required. Optional pin_code.',
        'body' => [
            'pin_code' => ['required' => false, 'type' => 'string', 'description' => 'PIN if required', 'example' => null],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/shifts/{id}/resolve-discrepancy' => [
        'description' => 'Resolve cash variance for a closed shift.',
        'usage' => 'X-Business-Id required. Only closed shifts. Requires manage shifts or owner.',
        'body' => [
            'resolution_note' => ['required' => false, 'type' => 'string', 'description' => 'Note for variance', 'example' => null],
        ],
        'query' => [],
        'noAuth' => false,
    ],

    // ---- Stock writeoffs ----
    'GET api/stock-writeoffs' => [
        'description' => 'List stock write-offs. Paginated.',
        'usage' => 'X-Business-Id required. Filter by branch_id, product_id, start_date, end_date, per_page.',
        'body' => [],
        'query' => [
            'current_business_id' => ['required' => true, 'description' => 'Business ID (or use X-Business-Id)', 'example' => '{{business_id}}'],
            'branch_id' => ['required' => false, 'description' => 'Filter by branch', 'example' => null],
            'product_id' => ['required' => false, 'description' => 'Filter by product', 'example' => null],
            'start_date' => ['required' => false, 'description' => 'Y-m-d', 'example' => null],
            'end_date' => ['required' => false, 'description' => 'Y-m-d', 'example' => null],
            'per_page' => ['required' => false, 'description' => 'Items per page', 'example' => '15'],
        ],
        'noAuth' => false,
    ],
    'POST api/stock-writeoffs' => [
        'description' => 'Create a stock write-off. Use product_id+branch_id, or branch_product_id, or batch_id. quantity and source (shelf|store) required when not using batch_id.',
        'usage' => 'X-Business-Id required. When batch_id provided: quantity and source from request; product/branch from batch. reason always required.',
        'body' => [
            'current_business_id' => ['required' => true, 'type' => 'integer', 'description' => 'Business ID', 'example' => 1],
            'batch_id' => ['required' => false, 'type' => 'integer', 'description' => 'If set, use batch for context; still send quantity and source', 'example' => null],
            'branch_id' => ['required' => false, 'type' => 'integer', 'description' => 'Required with product_id', 'example' => 1],
            'product_id' => ['required' => false, 'type' => 'integer', 'description' => 'Required without branch_product_id/batch_id', 'example' => 1],
            'branch_product_id' => ['required' => false, 'type' => 'integer', 'description' => 'Alternative to product_id+branch_id', 'example' => null],
            'quantity' => ['required' => true, 'type' => 'integer', 'description' => 'Quantity to write off', 'example' => 5],
            'source' => ['required' => true, 'type' => 'string', 'description' => 'shelf|store', 'example' => 'shelf'],
            'reason' => ['required' => true, 'type' => 'string', 'description' => 'Max 1000 chars', 'example' => 'Damaged'],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/stock-writeoffs/writeoff-batch' => [
        'description' => 'Write off the entire remaining quantity of a batch by batch ID.',
        'usage' => 'X-Business-Id required. batch_id and reason required. Writes off full batch current_quantity.',
        'body' => [
            'current_business_id' => ['required' => true, 'type' => 'integer', 'description' => 'Business ID', 'example' => 1],
            'batch_id' => ['required' => true, 'type' => 'integer', 'description' => 'Batch ID', 'example' => 1],
            'reason' => ['required' => true, 'type' => 'string', 'description' => 'Max 1000 chars', 'example' => 'Expired batch'],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'GET api/stock-writeoffs/{id}' => [
        'description' => 'Get one write-off by ID.',
        'usage' => 'X-Business-Id required.',
        'body' => [],
        'query' => [],
        'noAuth' => false,
    ],

    // ---- Sync ----
    'GET api/sync/online-devices' => [
        'description' => 'List devices last seen in the last 5 minutes (online devices).',
        'usage' => 'X-Business-Id required. Optional branch_id to filter. Requires sync data permission.',
        'body' => [],
        'query' => [
            'branch_id' => ['required' => false, 'description' => 'Filter by branch', 'example' => null],
        ],
        'noAuth' => false,
    ],
    'POST api/sync/register-device' => [
        'description' => 'Register a device for offline sync.',
        'usage' => 'X-Business-Id required. device_id, device_name, device_type (web|desktop|mobile|tablet) required.',
        'body' => [
            'device_id' => ['required' => true, 'type' => 'string', 'description' => 'Unique device ID', 'example' => '{{device_id}}'],
            'device_name' => ['required' => true, 'type' => 'string', 'description' => 'Display name', 'example' => 'POS Terminal 1'],
            'device_type' => ['required' => true, 'type' => 'string', 'description' => 'web|desktop|mobile|tablet', 'example' => 'desktop'],
            'branch_id' => ['required' => false, 'type' => 'integer', 'description' => 'Branch', 'example' => null],
            'business_id' => ['required' => false, 'type' => 'integer', 'description' => 'Business (or header)', 'example' => null],
            'os' => ['required' => false, 'type' => 'string', 'description' => 'OS', 'example' => null],
            'app_version' => ['required' => false, 'type' => 'string', 'description' => 'App version', 'example' => null],
            'capabilities' => ['required' => false, 'type' => 'array', 'description' => 'Capabilities', 'example' => []],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/sync/bootstrap' => [
        'description' => 'Initial data pull for device (bootstrap).',
        'usage' => 'X-Business-Id and X-Device-Id required. session_id, device_id in body.',
        'body' => [
            'session_id' => ['required' => true, 'type' => 'string', 'description' => 'Session UUID', 'example' => '{{$guid}}'],
            'device_id' => ['required' => true, 'type' => 'string', 'description' => 'Device ID', 'example' => '{{device_id}}'],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/sync/pull' => [
        'description' => 'Pull changes since last sync.',
        'usage' => 'X-Business-Id and X-Device-Id required. since_timestamp optional.',
        'body' => [
            'session_id' => ['required' => true, 'type' => 'string', 'description' => 'Session UUID', 'example' => '{{$guid}}'],
            'device_id' => ['required' => true, 'type' => 'string', 'description' => 'Device ID', 'example' => '{{device_id}}'],
            'since_timestamp' => ['required' => false, 'type' => 'string', 'description' => 'ISO timestamp', 'example' => null],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/sync/push' => [
        'description' => 'Push local changes (sales, customers, etc.).',
        'usage' => 'X-Business-Id and X-Device-Id required. changes: { sales: [], customers: [], ... }.',
        'body' => [
            'session_id' => ['required' => true, 'type' => 'string', 'description' => 'Session UUID', 'example' => '{{$guid}}'],
            'changes' => ['required' => true, 'type' => 'object', 'description' => 'sales, customers arrays with client_uuid, etc.', 'example' => ['sales' => [], 'customers' => []]],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/sync/resolve-conflicts' => [
        'description' => 'Submit conflict resolutions.',
        'usage' => 'X-Business-Id and X-Device-Id required.',
        'body' => [
            'session_id' => ['required' => true, 'type' => 'string', 'description' => 'Session UUID', 'example' => '{{$guid}}'],
            'resolutions' => ['required' => true, 'type' => 'array', 'description' => 'Resolution list', 'example' => []],
        ],
        'query' => [],
        'noAuth' => false,
    ],
    'GET api/sync/status' => [
        'description' => 'Get sync status for device.',
        'usage' => 'X-Business-Id and X-Device-Id required.',
        'body' => [],
        'query' => [],
        'noAuth' => false,
    ],
    'POST api/sync/heartbeat' => [
        'description' => 'Keep sync session alive (updates last_seen_at).',
        'usage' => 'X-Business-Id and X-Device-Id required.',
        'body' => [],
        'query' => [],
        'noAuth' => false,
    ],
];
