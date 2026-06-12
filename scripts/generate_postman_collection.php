<?php

/**
 * Generates a complete Postman Collection v2.1 JSON for the POS Backend API.
 * Covers every route registered under the `api` prefix (see `php artisan route:list --path=api`)
 * plus Laravel's `/up` health route.
 *
 * Regenerate:
 *   php scripts/generate_postman_collection.php > POS_Backend_Complete_API_v2.postman_collection.json
 *   cp POS_Backend_Complete_API_v2.postman_collection.json POS_Backend_API_Complete.postman_collection.json
 */
$base = [
    'info' => [
        '_postman_id' => 'pos-backend-complete-v2',
        'name' => 'POS Backend API (Complete)',
        'description' => "Complete Postman collection for this Laravel backend. Generated from `scripts/generate_postman_collection.php` (mirrors `routes/api.php` and `GET /up`).\n\n**Regenerate:** `php scripts/generate_postman_collection.php > POS_Backend_Complete_API_v2.postman_collection.json`\n\n**Find routes fast:**\n- **Device groups (terminals):** folder `4b. Device groups` — paths `device-groups`, `device-groups/report`.\n- **Deposits:** under `13. Sales` → subfolder `Deposits` — `GET sales/by-reference/...`, `POST sales/.../complete-deposit`. Deposit **stock mode** settings: `2c. Business settings` → `settings/business` (`deposit_stock_mode`).\n- **Shifts ↔ group:** `14. Sales shifts` — `POST shifts/backfill-groups` links shift `group_id` to device group (not the same folder as device-groups CRUD).\n\n**Setup:**\n1. `base_url` = http://127.0.0.1:8000/api — use paths without a second `/api` prefix.\n2. `app_root` = http://127.0.0.1:8000 — for `/up` only.\n3. Register or Login sets `auth_token`.\n4. Use `X-Business-Id: {{business_id}}` on business-scoped routes.\n5. Collection uses Bearer auth; public endpoints override with noauth.",
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    ],
    'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{auth_token}}', 'type' => 'string']]],
    'variable' => [
        ['key' => 'app_root', 'value' => 'http://127.0.0.1:8000', 'type' => 'string'],
        ['key' => 'base_url', 'value' => 'http://127.0.0.1:8000/api', 'type' => 'string'],
        ['key' => 'auth_token', 'value' => '', 'type' => 'string'],
        ['key' => 'business_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'branch_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'user_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'product_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'category_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'customer_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'shift_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'sale_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'payment_method_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'device_id', 'value' => 'device-postman-001', 'type' => 'string'],
        ['key' => 'seed_import_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'device_group_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'device_registration_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'sale_reference', 'value' => 'SALE-001', 'type' => 'string'],
        ['key' => 'supplier_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'grn_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'grn_line_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'purchase_order_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'inventory_transaction_id', 'value' => '1', 'type' => 'string'],
    ],
];

function req(string $name, string $method, string $path, string $description, ?string $body = null, array $extraHeaders = [], bool $noAuth = false, array $query = [], array $responses = []): array
{
    $fullPath = ltrim($path, '/');
    $pathParts = array_values(array_filter(explode('/', $fullPath)));
    $url = ['raw' => '{{base_url}}/'.$fullPath, 'host' => ['{{base_url}}'], 'path' => $pathParts];
    if ($query !== []) {
        $url['query'] = array_map(fn ($q) => ['key' => $q[0], 'value' => $q[1]], $query);
    }
    $headers = [
        ['key' => 'Accept', 'value' => 'application/json'],
        ['key' => 'Content-Type', 'value' => 'application/json'],
    ];
    foreach ($extraHeaders as $h) {
        $headers[] = is_array($h) && isset($h['key'], $h['value']) ? $h : ['key' => (string) $h[0], 'value' => (string) $h[1]];
    }
    $request = [
        'method' => $method,
        'header' => $headers,
        'url' => $url,
        'description' => $description,
    ];
    if ($body !== null) {
        $request['body'] = ['mode' => 'raw', 'raw' => $body];
    }
    if ($noAuth) {
        $request['auth'] = ['type' => 'noauth'];
    }

    return ['name' => $name, 'request' => $request, 'response' => $responses];
}

/**
 * Request against app root (no /api), e.g. Laravel health `GET /up`.
 */
function reqAppRoot(string $name, string $method, string $path, string $description, ?string $body = null, bool $noAuth = true): array
{
    $fullPath = ltrim($path, '/');
    $pathParts = array_values(array_filter(explode('/', $fullPath)));
    $url = ['raw' => '{{app_root}}/'.$fullPath, 'host' => ['{{app_root}}'], 'path' => $pathParts];
    $headers = [
        ['key' => 'Accept', 'value' => 'application/json'],
    ];
    if ($body !== null || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
    }
    $request = [
        'method' => $method,
        'header' => $headers,
        'url' => $url,
        'description' => $description,
    ];
    if ($body !== null) {
        $request['body'] = ['mode' => 'raw', 'raw' => $body];
    }
    if ($noAuth) {
        $request['auth'] = ['type' => 'noauth'];
    }

    return ['name' => $name, 'request' => $request, 'response' => []];
}

/**
 * Multipart form-data request (e.g. file upload). Do not send Content-Type: application/json.
 *
 * @param  array<int, array<string, mixed>>  $formdata  Postman formdata rows: key, type (text|file), value, optional description, optional disabled
 */
function reqFormData(string $name, string $method, string $path, string $description, array $formdata, array $extraHeaders = []): array
{
    $fullPath = ltrim($path, '/');
    $pathParts = array_values(array_filter(explode('/', $fullPath)));
    $url = ['raw' => '{{base_url}}/'.$fullPath, 'host' => ['{{base_url}}'], 'path' => $pathParts];
    $headers = [
        ['key' => 'Accept', 'value' => 'application/json'],
    ];
    foreach ($extraHeaders as $h) {
        $headers[] = is_array($h) && isset($h['key'], $h['value']) ? $h : ['key' => (string) $h[0], 'value' => (string) $h[1]];
    }
    $request = [
        'method' => $method,
        'header' => $headers,
        'url' => $url,
        'description' => $description,
        'body' => ['mode' => 'formdata', 'formdata' => $formdata],
    ];

    return ['name' => $name, 'request' => $request, 'response' => []];
}

function sampleResponse(string $name, int $code, string $body, string $status = 'OK'): array
{
    return [
        'name' => $name,
        'originalRequest' => null,
        'status' => $status,
        'code' => $code,
        'header' => [['key' => 'Content-Type', 'value' => 'application/json']],
        'body' => $body,
    ];
}

function saveTokenScript(): array
{
    return [
        'listen' => 'test',
        'script' => [
            'exec' => [
                'if (pm.response.code === 200 || pm.response.code === 201) {',
                '    const j = pm.response.json();',
                '    if (j.token) pm.collectionVariables.set(\'auth_token\', j.token);',
                '    if (j.data && j.data.id && !j.data.branches) pm.collectionVariables.set(\'business_id\', j.data.id);',
                '    if (j.data && j.data.id && j.data.code) pm.collectionVariables.set(\'branch_id\', j.data.id);',
                '}',
            ],
            'type' => 'text/javascript',
        ],
    ];
}

$items = [];

// ---- 0. System (non-api) ----
$items[] = [
    'name' => '0. System',
    'description' => 'Laravel framework route (not under `/api`).',
    'item' => [
        reqAppRoot('Health (up)', 'GET', 'up', "Laravel default health endpoint (`bootstrap/app.php` → `health: '/up'`). Returns 200 when the application can boot. No authentication.\n\nUse collection variable `app_root` (not `base_url`).", null, true),
    ],
];

// ---- 1. Authentication ----
$items[] = [
    'name' => '1. Authentication',
    'description' => 'Public and protected auth endpoints. Register and Login store the token in collection variable `auth_token`.',
    'item' => [
        req('Get Current User', 'GET', 'user', "Returns the currently authenticated user (includes profile_image_url). Requires valid Bearer token.\n\n**Response:** id, name, email, profile_image, profile_image_url.", null, [], false),
        req('Update Profile', 'PUT', 'user', "Update current user profile.\n\n**Fields:**\n- name: optional | string | max:255\n- profile_image: optional | file | image | max:2048\n\nReturns updated user.", '{"name": "New Name"}', []),
        array_merge(req('Register', 'POST', 'register', "**Public.** Create a new user account.\n\n**Fields:**\n- name: required | string | max:255\n- email: required | string | email | max:255 | unique:users\n- password: required | string | min:8 | confirmed\n- password_confirmation: required | string\n- profile_image: optional | file | image | max:2048\n\nReturns user + token. Test script saves token.", '{
  "name": "Jane Doe",
  "email": "jane.doe@example.com",
  "password": "SecurePassword123!",
  "password_confirmation": "SecurePassword123!"
}', [], true), ['event' => [['listen' => 'test', 'script' => ['exec' => ['if (pm.response.code === 201) { var j = pm.response.json(); if (j.token) pm.collectionVariables.set(\'auth_token\', j.token); }'], 'type' => 'text/javascript']]]]),
        array_merge(req('Login', 'POST', 'login', "**Public.** Login with email and password.\n\n**Fields:**\n- email: required | string | email\n- password: required | string\n\nReturns user + token. Test script saves token.", '{
  "email": "jane.doe@example.com",
  "password": "SecurePassword123!"
}', [], true), ['event' => [['listen' => 'test', 'script' => ['exec' => ['if (pm.response.code === 200) { var j = pm.response.json(); if (j.token) pm.collectionVariables.set(\'auth_token\', j.token); }'], 'type' => 'text/javascript']]]]),
        req('PIN Login', 'POST', 'pin-login', "**Public.** Fast login with 6-digit PIN.\n\n**Fields:**\n- pin_code: required | string | size:6\n\nUser must have PIN set and 'use-pin-login' permission.", '{
  "pin_code": "123456"
}', [], true),
        req('Set PIN', 'POST', 'pin/set', "Set or update a user's PIN.\n\n**Fields:**\n- user_id: required | integer | exists:users,id\n- pin_code: required | string | size:6\n- password: required_if setting own PIN | string\n\nRequires manage-pin-codes for other users.", '{
  "user_id": 1,
  "pin_code": "654321",
  "password": "SecurePassword123!"
}', []),
        req('Remove PIN', 'POST', 'pin/remove', "Remove PIN for a user.\n\n**Fields:**\n- user_id: required | integer | exists:users,id\n- password: required | string\n\nRequires manage-pin-codes when removing another user's PIN.", '{
  "user_id": 1,
  "password": "SecurePassword123!"
}', []),
        req('Get Business Details With Branch Auth', 'POST', 'business-details-with-branch-auth', "**Public.** Get business and branch by branch authorization code. Body: auth_code (required, string). Business is derived from the code; no business_id needed. Returns business + branch when code is valid and not expired.\n\n**Fields:**\n- auth_code: required | string", '{
  "auth_code": "847291"
}', [], true),
        req('Register Cashier Device', 'POST', 'register-cashier-device', "**Public.** Register a cashier device using a valid branch auth code.\n\n**Fields:**\n- auth_code: required | string\n- device_id: required | string | max:50\n- device_name: required | string | max:100\n- device_type: optional | web | desktop | mobile | tablet\n- os: optional | string | max:50\n- app_version: optional | string | max:20", '{
  "auth_code": "847291",
  "device_id": "{{device_id}}",
  "device_name": "Postman POS",
  "device_type": "desktop",
  "os": "macOS",
  "app_version": "0.1.0"
}', [], true),
    ],
];

// ---- 2. Businesses ----
$items[] = [
    'name' => '2. Businesses',
    'description' => 'CRUD for businesses. Multi-tenant: each business has an owner and can have multiple branches. List returns only businesses the authenticated user belongs to. Create sets the creator as owner and creates a main branch. Use X-Business-Id header for get/update/delete.',
    'item' => [
        req('List Businesses', 'GET', 'businesses', "List all businesses the authenticated user belongs to. No business context required.\n\n**Response:** Array of businesses with id, name, slug, branches, pivot (with branch_id)."),
        req('Create Business', 'POST', 'businesses', "Create a new business. Creator becomes owner and a main branch is auto-created.\n\n**Fields:**\n- name: required | string | max:255\n- legal_name: nullable | string | max:255\n- slug: nullable | string | max:255 | unique:businesses\n- email: nullable | email | max:255\n- phone: nullable | string | max:50\n- address: nullable | string | max:500\n- city: nullable | string | max:100\n- state: nullable | string | max:100\n- postal_code: nullable | string | max:20\n- country: nullable | string | max:2\n- currency: nullable | string | max:3\n- time_zone: nullable | string | max:50\n- tax_registration_number: nullable | string | max:100\n- default_tax_rate: nullable | numeric | min:0 | max:100\n- main_branch_name: nullable | string | max:255\n- main_branch_code: nullable | string | max:50\n- settings: nullable | array", '{
  "name": "Acme Retail Ltd",
  "legal_name": "Acme Retail Limited",
  "slug": "acme-retail",
  "email": "contact@acme.com",
  "phone": "+1234567890",
  "address": "100 Commerce Street",
  "city": "New York",
  "state": "NY",
  "postal_code": "10001",
  "country": "US",
  "currency": "USD",
  "time_zone": "America/New_York",
  "tax_registration_number": "TAX123",
  "default_tax_rate": 10,
  "main_branch_name": "Head Office",
  "main_branch_code": "MAIN",
  "settings": {}
}', []),
        req('Get Business', 'GET', 'businesses/{{business_id}}', "Get one business by ID. Requires X-Business-Id header.\n\n**Response:** Business object with branches.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Business', 'PUT', 'businesses/{{business_id}}', "Update business. Same fields as create (all optional).\n\n**Fields:** All fields from Create Business are accepted, none are required.\n\nRequires X-Business-Id.", '{
  "name": "Acme Retail Updated",
  "email": "info@acme.com",
  "phone": "+1987654321",
  "address": "200 New Address",
  "default_tax_rate": 12
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Delete Business', 'DELETE', 'businesses/{{business_id}}', 'Delete a business (soft delete). Requires X-Business-Id.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 2b. Dashboard ----
$items[] = [
    'name' => '2b. Dashboard',
    'description' => 'Business KPI summary (cached). Requires X-Business-Id and permissions for analytics, sales, inventory, or shifts.',
    'item' => [
        req('Dashboard Summary', 'GET', 'dashboard/summary', "Returns organization-level summary: revenue, order counts, low/out stock, open shifts, etc.\n\nX-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 2c. Business Settings ----
$items[] = [
    'name' => '2c. Business settings (currency, deposit_stock_mode, allow_decimal_quantities)',
    'description' => '**Search: deposit, deposit_stock_mode, allow_decimal_quantities, settings.** Endpoints: `GET/PUT settings/business`. `deposit_stock_mode` controls when stock moves for **deposit** sales. `allow_decimal_quantities` (default false) enables fractional qty on sales, purchases, GRN, and stock moves. GET: any member. PUT: owner or **manage-settings**.',
    'item' => [
        req('Get Business Settings', 'GET', 'settings/business', "Returns currency (ISO 3), currency_symbol, deposit_stock_mode (reserve_on_create | deduct_on_complete), allow_decimal_quantities (boolean, default false).\n\nX-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Business Settings', 'PUT', 'settings/business', "Update business-level settings.\n\n**Fields:**\n- currency: optional | string | size:3\n- currency_symbol: optional | string | max:10\n- deposit_stock_mode: optional | reserve_on_create | deduct_on_complete\n- allow_decimal_quantities: optional | boolean\n\nX-Business-Id required.", '{
  "currency": "NGN",
  "currency_symbol": "₦",
  "deposit_stock_mode": "reserve_on_create",
  "allow_decimal_quantities": false
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 3. Permissions (no business context) ----
$items[] = [
    'name' => '3. Permissions (Global)',
    'description' => 'Global list of all permissions in the system. Used when creating or editing roles. No business context required.',
    'item' => [
        req('List Permissions', 'GET', 'permissions', 'List all available permissions in the system. No business context. Used when building roles.'),
    ],
];

// ---- 4. Branches ----
$items[] = [
    'name' => '4. Branches',
    'item' => [
        req('List Branches', 'GET', 'branches', 'List branches for the business. Requires X-Business-Id. User sees only branches they have access to.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Generate Branch Auth Codes', 'POST', 'branches/generate-auth-codes', 'Generate 2-minute-expiry auth codes for all branches the user has permission to. No body. X-Business-Id required. Returns authorizations array (branch_id, branch_name, auth_code, expires_at). Existing non-expired codes are reused.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Branch', 'POST', 'branches', "Create a branch within the business.\n\n**Fields:**\n- name: required | string | max:255\n- code: required | string | max:50 | unique within business\n- email: nullable | string | email | max:255\n- phone: nullable | string | max:50\n- address: nullable | string | max:500\n- city: nullable | string | max:100\n- state: nullable | string | max:100\n- postal_code: nullable | string | max:20\n- country: nullable | string | max:2\n- time_zone: nullable | string | max:50\n- tax_rate: nullable | numeric | min:0 | max:100\n- settings: nullable | array\n- is_main: optional | boolean\n- is_active: optional | boolean", '{
  "name": "Downtown Store",
  "code": "DT001",
  "email": "downtown@acme.com",
  "phone": "+1555123456",
  "address": "50 Main Ave",
  "city": "New York",
  "state": "NY",
  "postal_code": "10002",
  "country": "US",
  "time_zone": "America/New_York",
  "tax_rate": 10,
  "settings": {},
  "is_main": false,
  "is_active": true
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Branch', 'GET', 'branches/{{branch_id}}', 'Get one branch. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Branch', 'PUT', 'branches/{{branch_id}}', 'Update branch. Same fields as create.', '{
  "name": "Downtown Store Updated",
  "code": "DT001",
  "address": "51 Main Ave",
  "is_active": true
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Delete Branch', 'DELETE', 'branches/{{branch_id}}', 'Delete a branch. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 4b. Device Groups ----
$items[] = [
    'name' => '4b. Device groups (path: device-groups)',
    'description' => '**Search: device-groups, group, terminal.** API path prefix is `device-groups` (not `groups`). Group POS **DeviceRegistration** rows for sales/shift reporting. Related: **4c. Devices**, and `POST shifts/backfill-groups` (14) to set `group_id` on old shifts. Permissions: view/manage device groups, assign device to group.',
    'item' => [
        req('List Device Groups', 'GET', 'device-groups', 'Query: branch_id, is_active, search. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Device Group Sales Report', 'GET', 'device-groups/report', 'Aggregate completed sales by device group. Query: start_date, end_date, branch_id, group_id, device_id, shift_id.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['start_date', '2026-02-01'], ['end_date', '2026-02-28'], ['branch_id', '{{branch_id}}']]),
        req('Create Device Group', 'POST', 'device-groups', "Create a device group.\n\n**Fields:**\n- name: required | string\n- code: required | string | unique per business\n- branch_id: optional | integer\n- description: optional | string\n- is_active: optional | boolean\n\nX-Business-Id required.", '{
  "branch_id": null,
  "name": "Front Counter",
  "code": "FC01",
  "description": "Main checkout terminals",
  "is_active": true
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Device Group', 'GET', 'device-groups/{{device_group_id}}', 'Returns group with active_shifts_count. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Device Group', 'PUT', 'device-groups/{{device_group_id}}', 'Update group. Same fields as create (optional). X-Business-Id required.', '{
  "name": "Front Counter A",
  "is_active": true
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Delete Device Group', 'DELETE', 'device-groups/{{device_group_id}}', 'Delete device group. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Assign Device to Group', 'POST', 'device-groups/{{device_group_id}}/assign-device', "Set a device registration's group. **Body:** device_id (string, max 50) — the client device_id, not DB id.", '{
  "device_id": "{{device_id}}"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Remove Device from Group', 'POST', 'device-groups/{{device_group_id}}/remove-device', 'Remove device from this group (group_id cleared on device if it was in this group). **Body:** device_id (string).', '{
  "device_id": "{{device_id}}"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 4c. Devices ----
$items[] = [
    'name' => '4c. Devices (registrations)',
    'description' => 'Registered sync devices for the business. Path {device} is the **DeviceRegistration** id (integer), not the string device_id.',
    'item' => [
        req('List Devices', 'GET', 'devices', 'Query: branch_id, status (active|inactive|blocked), search. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Device', 'GET', 'devices/{{device_registration_id}}', 'Get one device registration with branch, user, group. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Device', 'PUT', 'devices/{{device_registration_id}}', "Update device metadata and group/branch.\n\n**Fields:**\n- device_name, device_type: required on update (web|desktop|mobile|tablet)\n- status: required (active|inactive|blocked)\n- os, app_version, branch_id, group_id: optional", '{
  "device_name": "POS Terminal 1",
  "device_type": "desktop",
  "os": "macOS",
  "app_version": "1.0.0",
  "branch_id": 1,
  "group_id": 1,
  "status": "active"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Device (PATCH)', 'PATCH', 'devices/{{device_registration_id}}', 'Same body as PUT.', '{
  "device_name": "POS Terminal 1",
  "device_type": "desktop",
  "status": "active"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Delete Device Registration', 'DELETE', 'devices/{{device_registration_id}}', 'Remove device registration from business. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 4d. Sync Dashboard (operations) ----
$items[] = [
    'name' => '4d. Sync Dashboard',
    'description' => 'Ops overview for offline sync (distinct from **Sync** device endpoints below). Requires **sync data** permission.',
    'item' => [
        req('Sync Dashboard Summary', 'GET', 'sync/dashboard/summary', 'Counts: online devices, pending changes, unresolved conflicts, last sync. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Sync Sessions List', 'GET', 'sync/dashboard/sessions', 'Paginated sync sessions. Query: status, direction, device_id (registration id), from, to, per_page (max 100).', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['per_page', '25'], ['from', ''], ['to', '']]),
        req('Unresolved Sync Conflicts', 'GET', 'sync/dashboard/conflicts', 'Sessions where conflicts_detected > conflicts_resolved (limit 100). X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 5. Roles & Permissions ----
$items[] = [
    'name' => '5. Roles & Permissions',
    'description' => 'Business-scoped roles and permissions (Spatie). Create roles, attach/detach permissions, assign/remove roles to users. Role assignment can be branch-scoped (branch_id) or business-wide (branch_id null). X-Business-Id required.',
    'item' => [
        req('List Roles', 'GET', 'roles', 'List all roles for the business. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Role', 'POST', 'roles', 'Create a role. name required. permissions optional (array of permission names). business_id from X-Business-Id.', '{
  "name": "Cashier",
  "permissions": ["view products", "create sales"]
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Add Permission to Role', 'POST', 'roles/addpermission', 'Attach permissions to a role. role_id (integer), permission_name (array of permission names) required.', '{
  "role_id": 1,
  "permission_name": ["view products", "create sales"]
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Remove Permission from Role', 'POST', 'roles/removepermission', 'Detach permissions from a role. role_id (integer), permission_name (array of permission names) required.', '{
  "role_id": 1,
  "permission_name": ["edit_product"]
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Role', 'GET', 'roles/1', 'Get one role with permissions. Replace 1 with role id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Role', 'PUT', 'roles/1', 'Update role name. X-Business-Id required.', '{"name": "Senior Cashier"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Delete Role', 'DELETE', 'roles/1', 'Delete a role. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Assign Role to User', 'POST', 'roles/assign', 'Assign a role to a user in this business. user_id, role_id required. branch_id optional for branch-scoped assignment.', '{
  "user_id": 2,
  "role_id": 1,
  "branch_id": null
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Remove Role from User', 'POST', 'roles/remove', 'Remove a role from a user. user_id, role_id required.', '{
  "user_id": 2,
  "role_id": 1
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get User Roles', 'GET', 'users/{{user_id}}/roles', 'List all roles and permissions for a user in the business. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 6. Business Users ----
$items[] = [
    'name' => '6. Business Users',
    'item' => [
        req('List Business Users', 'GET', 'business-users', 'List all users in the business with their roles. Permission: owner or manage-users. Query: branch_id (optional) – filter to users who have a role in this branch or a business-wide role; branch must belong to the business and requester must have branch access. Returns id, name, email, profile_image, profile_image_url, is_active, joined_at, roles. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Add User to Business', 'POST', 'business-users', 'Add a user by email. If email does not exist, creates new user with random password; password returned in data.password. If user is assigned the Cashier role and has no PIN, a PIN is auto-generated and returned in data.pin_code. name required. Optional: profile_image (multipart) when creating new user. role_ids: optional array of role ids. Only owner can add.', '{
  "email": "newstaff@example.com",
  "name": "New Staff",
  "is_active": true,
  "role_ids": [1, 2]
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Business User', 'GET', 'business-users/{{user_id}}', "Get one user's details in the business: roles, permissions. X-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Business User', 'PUT', 'business-users/{{user_id}}', "Update user's active status in the business. is_active required. Owner only.", '{"is_active": false}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Remove User from Business', 'DELETE', 'business-users/{{user_id}}', 'Remove user from business and clear their roles. Owner only. Cannot remove self.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Set User Password', 'PUT', 'business-users/{{user_id}}/set-password', "Set or clear password for a user in the business. Requires owner role.\n\n**Fields:**\n- password: nullable | string | min:8 | confirmed\n- password_confirmation: required_with:password | string", '{
  "password": "NewPassword123!",
  "password_confirmation": "NewPassword123!"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 7. Product Categories ----
$items[] = [
    'name' => '7. Product Categories',
    'description' => 'Product hierarchy. Categories can have parent_id for tree structure. Breadcrumb returns the parent chain for a category. X-Business-Id required.',
    'item' => [
        req('List Categories', 'GET', 'categories', 'List product categories. Query: per_page, search, parent_id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Category', 'POST', 'categories', 'Create category. name required. parent_id optional for hierarchy. description, image, sort_order optional.', '{
  "name": "Electronics",
  "parent_id": null,
  "description": "Electronic devices and accessories",
  "image": null,
  "sort_order": 0
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Category', 'GET', 'categories/{{category_id}}', 'Get one category. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Category', 'PUT', 'categories/{{category_id}}', 'Update category. Same fields as create.', '{"name": "Electronics & Gadgets", "description": "Updated"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Delete Category', 'DELETE', 'categories/{{category_id}}', 'Delete category. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Category Breadcrumb', 'GET', 'categories/{{category_id}}/breadcrumb', 'Get breadcrumb path for a category (parent chain). X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 7b. Data seeding (async import) ----
$items[] = [
    'name' => '7b. Data Seeding',
    'description' => 'Queue CSV/Excel import (products or product_categories). Returns 202 with id/uuid; poll GET seed/{id}/status for progress. Permission: create products. X-Business-Id required.',
    'item' => [
        array_merge(
            reqFormData(
                'Queue Seed Import',
                'POST',
                'seed',
                "**Multipart form-data.** Queues `ProcessSeedImport` job.\n\n**Fields:**\n- file: required | CSV or Excel (csv, xlsx, xls)\n- entity: required | products | product_categories\n- mapping: required | object or JSON string — file header → DB column (see config/seed.php allowed columns)\n- unique_key: required | string — must be one of the mapping target columns\n- branch_id: required | integer\n- delete: optional | boolean — when true, rows are hard-deleted by unique_key instead of upserted\n\n**Response (202):** message, id, uuid, status (pending). Use **Get Seed Import Status** with `id`.\n\n**Products:** optional virtual columns `category` (by name), `retail_value` (requires `stock_quantity`; selling_price = retail_value / stock_quantity).",
                [
                    ['key' => 'file', 'type' => 'file', 'src' => '', 'description' => 'CSV or Excel file'],
                    ['key' => 'entity', 'type' => 'text', 'value' => 'products'],
                    ['key' => 'mapping[ItemID]', 'type' => 'text', 'value' => 'barcode', 'description' => 'Example: map file column to DB column'],
                    ['key' => 'mapping[ItemDescription]', 'type' => 'text', 'value' => 'name'],
                    ['key' => 'mapping[SupplyPrice]', 'type' => 'text', 'value' => 'base_cost_price'],
                    ['key' => 'unique_key', 'type' => 'text', 'value' => 'barcode'],
                    ['key' => 'branch_id', 'type' => 'text', 'value' => '{{branch_id}}'],
                    ['key' => 'delete', 'type' => 'text', 'value' => 'false', 'description' => 'Optional', 'disabled' => true],
                ],
                [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]
            ),
            [
                'event' => [[
                    'listen' => 'test',
                    'script' => [
                        'exec' => [
                            'if (pm.response.code === 202) {',
                            '    const j = pm.response.json();',
                            '    if (j.id) pm.collectionVariables.set(\'seed_import_id\', String(j.id));',
                            '}',
                        ],
                        'type' => 'text/javascript',
                    ],
                ]],
            ]
        ),
        req('Get Seed Import Status', 'GET', 'seed/{{seed_import_id}}/status', "Poll import job status.\n\n**Response:** id, uuid, status (pending|processing|completed|failed), entity, total_rows, created, updated, deleted, failed, errors, started_at, completed_at.\n\nX-Business-Id or business_id query recommended.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 8. Products ----
$items[] = [
    'name' => '8. Products',
    'item' => [
        req('List Products', 'GET', 'products', "List products.\n\n**Query:**\n- branch_id: optional | integer\n- category_id: optional | integer\n- search: optional | string (name, sku, barcode)\n- per_page: optional | integer (default 15)\n- is_active: optional | boolean\n\nX-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['search', ''], ['category_id', ''], ['branch_id', '{{branch_id}}'], ['per_page', '15']]),
        req('Create Product', 'POST', 'products', 'Create product. name, sku, base_selling_price required. stock_tracking: none|simple|variant. low_stock_threshold integer. meta_data object.', '{
  "name": "Wireless Mouse",
  "sku": "SKU-MOUSE-001",
  "barcode": "1234567890123",
  "category_id": 1,
  "description": "Ergonomic wireless mouse",
  "image": null,
  "base_cost_price": 15.99,
  "base_selling_price": 29.99,
  "is_taxable": true,
  "default_tax_rate": 10,
  "unit_of_measure": "piece",
  "weight": 0.1,
  "weight_unit": "kg",
  "stock_tracking": "simple",
  "low_stock_threshold": 10,
  "is_active": true,
  "is_available_online": false,
  "meta_data": {}
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Product', 'GET', 'products/{{product_id}}', 'Get product. Optional query: branch_id for branch-specific pricing/stock. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Product', 'PUT', 'products/{{product_id}}', 'Update product. Same fields as create (all optional).', '{"name": "Wireless Mouse Pro", "base_selling_price": 34.99}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Delete Product', 'DELETE', 'products/{{product_id}}', 'Delete product. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Add Product to Branch', 'POST', 'products/{{product_id}}/branches', 'Add or update product at a branch. branch_id required. Optional: cost_price, selling_price, compare_price, discount_amount, discount_type (fixed|percentage), tax_rate, stock_quantity, low_stock_threshold, allow_backorder, reorder_point, reorder_quantity, is_available, is_featured, display_order, bin_location, shelf_location, branch_meta_data. Permission: manage branch products.', '{
  "branch_id": 1,
  "selling_price": 29.99,
  "compare_price": 39.99,
  "cost_price": 15.99,
  "stock_quantity": 100,
  "discount_amount": 0,
  "discount_type": "fixed",
  "tax_rate": 10,
  "low_stock_threshold": 10,
  "allow_backorder": false,
  "is_available": true,
  "is_featured": false,
  "display_order": 0,
  "bin_location": null,
  "shelf_location": null,
  "branch_meta_data": null
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Remove Product from Branch', 'DELETE', 'products/{{product_id}}/branches', 'Remove product from branch (soft delete). branch_id and current_business_id required as query params. Permission: manage branch products.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['branch_id', '{{branch_id}}'], ['current_business_id', '{{business_id}}']]),
        req('Update Product Base Selling Price', 'PATCH', 'products/{{product_id}}/base-selling-price', "Update the base selling price for a product.\n\n**Fields:**\n- selling_price: required | numeric | min:0\n\n**Query:**\n- branch_id: optional | integer\n\nX-Business-Id required.", '{"selling_price": 34.99}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Product Price (Legacy)', 'PATCH', 'products/{{product_id}}/price', "**Deprecated.** Legacy endpoint for updating product price. Use `PATCH products/{id}/base-selling-price` instead.\n\n**Fields:**\n- selling_price: required | numeric | min:0\n- branch_id: optional | integer\n\nX-Business-Id required.", '{"selling_price": 34.99}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('List Product Units', 'GET', 'products/{{product_id}}/units', 'List unit definitions for a product (e.g. piece, pack of 6, carton). Used for tiered pricing. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Product Unit', 'POST', 'products/{{product_id}}/units', 'Create a unit definition for a product. Body: name (e.g. \"Pack of 6\"), quantity_multiplier (>=1), optional min_quantity and display_order.', '{
  "name": "Pack of 6",
  "quantity_multiplier": 6,
  "min_quantity": null,
  "display_order": 0
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Product Unit', 'PUT', 'products/{{product_id}}/units/1', 'Update a unit definition. Same fields as create (all optional).', '{
  "name": "Pack of 12",
  "quantity_multiplier": 12
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Delete Product Unit', 'DELETE', 'products/{{product_id}}/units/1', 'Delete a unit definition. Only allowed when not used in active pricing.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Products by Branch', 'GET', 'branches/{{branch_id}}/products', 'Get products for a branch with branch_data. Query: current_business_id (or X-Business-Id), category_id, active_only, available_only, in_stock_only, search, start_id, per_page (default 15), paginated (default true). Permission: view products.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 9. Branch Products ----
$items[] = [
    'name' => '9. Branch Products',
    'description' => 'Product–branch association: pricing, shelf/store quantities, availability. Move to shelf/store: direct move (requires approve shelf store move or manage/adjust inventory) or use Shelf/Store Move Requests for approval workflow. Stock summary, bulk update. X-Business-Id required.',
    'item' => [
        req('List Branch Products', 'GET', 'branch-products', 'List products in a branch. Query: branch_id (required), is_available, is_featured, stock_status, search, per_page. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Branch Products by Category', 'GET', 'branch-products/by-category', 'Get branch products grouped by category. Query: branch_id required. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Branch Product', 'POST', 'branch-products', 'Add product to branch. branch_id, product_id required. selling_price is set from product base_selling_price; use PATCH selling-price to change. shelf_quantity, store_quantity, cost_price, compare_price, low_stock_threshold, etc.', '{
  "branch_id": 1,
  "product_id": 1,
  "cost_price": 15.99,
  "compare_price": 39.99,
  "discount_amount": 0,
  "discount_type": "fixed",
  "tax_rate": 10,
  "stock_quantity": 50,
  "shelf_quantity": 40,
  "store_quantity": 10,
  "low_stock_threshold": 5,
  "allow_backorder": false,
  "reorder_point": 10,
  "reorder_quantity": 20,
  "is_available": true,
  "is_featured": false,
  "display_order": 0,
  "bin_location": "A-01",
  "shelf_location": "Section 1",
  "branch_meta_data": null
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Assign Multiple Products', 'POST', 'branch-products/assign-multiple', 'Add multiple products to a branch at once. branch_id, product_ids array. Uses product defaults for pricing/stock.', '{
  "branch_id": 1,
  "product_ids": [1, 2, 3]
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Bulk Move Shelf/Store', 'POST', 'branch-products/bulk-move', "Bulk move stock between shelf and store within one branch. Requires same permissions as direct move-to-shelf/store (approve shelf store move or manage/adjust inventory).\n\n**Fields:**\n- branch_id: required\n- direction: required | to_shelf | to_store\n- mode: required | all | fixed_quantity | per_item\n- For mode=fixed_quantity: branch_product_ids (array), quantity (integer)\n- For mode=per_item: items: [{ branch_product_id, quantity }]\n\nX-Business-Id required.", '{
  "branch_id": 1,
  "direction": "to_shelf",
  "mode": "fixed_quantity",
  "branch_product_ids": [1, 2],
  "quantity": 5
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Bulk Selling Price (per item)', 'POST', 'branch-products/bulk-selling-price', "Update selling_price for multiple branch products in one branch. Same permission as PATCH branch-products/{id}/selling-price: business owner or **set branch product selling price**.\n\n**Fields:**\n- branch_id: required | integer | exists:branches\n- items: required | array | min:1\n- items.*.branch_product_id: required | integer (must belong to branch_id)\n- items.*.selling_price: required | numeric | min:0\n\n**Response:** message, summary (processed, updated, skipped), results[] with previous_selling_price and selling_price per row.\n\nX-Business-Id required.", '{
  "branch_id": 1,
  "items": [
    {"branch_product_id": 1, "selling_price": 29.99},
    {"branch_product_id": 2, "selling_price": 15.5}
  ]
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Branch Product', 'GET', 'branch-products/1', 'Get one branch product. Replace 1 with branch_product id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Branch Product Tiered Price', 'GET', 'branch-products/1/price', 'Compute effective unit price and total for a quantity using unit packs and quantity tiers. Query: quantity (required). X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['quantity', '6']]),
        req('List Branch Product Unit Prices', 'GET', 'branch-products/1/unit-prices', 'List unit-specific prices for this branch product (e.g. pack of 6, carton). X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Branch Product Unit Price', 'POST', 'branch-products/1/unit-prices', 'Create a unit price for this branch product. Body: product_unit_id, selling_price.', '{
  "product_unit_id": 1,
  "selling_price": 2500
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Branch Product Unit Price', 'PUT', 'branch-products/1/unit-prices/1', 'Update a unit price. Body: selling_price.', '{
  "selling_price": 2600
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Delete Branch Product Unit Price', 'DELETE', 'branch-products/1/unit-prices/1', 'Delete unit price for this branch product.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('List Branch Product Quantity Tiers', 'GET', 'branch-products/1/quantity-tiers', 'List quantity-based price tiers (e.g. 1–5 = 500, 6–19 = 450, 20+ = 400). X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Branch Product Quantity Tier', 'POST', 'branch-products/1/quantity-tiers', 'Create quantity-based price tier. Body: min_quantity, optional max_quantity, price_per_unit.', '{
  "min_quantity": 6,
  "max_quantity": 19,
  "price_per_unit": 450
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Branch Product Quantity Tier', 'PUT', 'branch-products/1/quantity-tiers/1', 'Update quantity-based price tier.', '{
  "min_quantity": 20,
  "max_quantity": null,
  "price_per_unit": 400
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Delete Branch Product Quantity Tier', 'DELETE', 'branch-products/1/quantity-tiers/1', 'Delete quantity-based price tier.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Branch Product', 'PUT', 'branch-products/1', 'Update branch product. Do not send selling_price (use PATCH selling-price). cost_price, compare_price, shelf_quantity, store_quantity, is_available, etc.', '{
  "cost_price": 16.50,
  "compare_price": 35.00,
  "shelf_quantity": 45,
  "store_quantity": 15,
  "is_available": true,
  "low_stock_threshold": 8
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Branch Product Selling Price', 'PATCH', 'branch-products/1/selling-price', "Set selling price for branch product. Requires 'set branch product selling price' permission. selling_price required.", '{"selling_price": 32.99}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Delete Branch Product', 'DELETE', 'branch-products/1', 'Remove product from branch. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Branch Product Stock', 'POST', 'branch-products/1/stock', 'Adjust stock. quantity (numeric, decimal supported), operation: add|subtract|set. X-Business-Id required.', '{"quantity": 10.5, "operation": "add"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Move Stock to Shelf', 'POST', 'branch-products/1/move-to-shelf', 'Direct move (store to shelf). Requires approve shelf store move or manage/adjust inventory. Otherwise use shelf-store-move-requests. quantity required (numeric, min 0.001).', '{"quantity": 5.5}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Move Stock to Store', 'POST', 'branch-products/1/move-to-store', 'Direct move (shelf to store). Requires approve shelf store move or manage/adjust inventory. Otherwise use shelf-store-move-requests. quantity required (numeric, min 0.001).', '{"quantity": 5.5}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Branch Products Stock Summary', 'GET', 'branch-products/summary/stock', 'Stock summary. Query: branch_id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Bulk Update Branch Products', 'POST', 'branch-products/bulk-update', 'Bulk update. updates: array of { id: branch_product_id, data: { ...fields } }. X-Business-Id required.', '{
  "updates": [
    {"id": 1, "data": {"is_available": true, "shelf_quantity": 30}},
    {"id": 2, "data": {"low_stock_threshold": 15}}
  ]
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 9b. Suppliers, GRN & Purchase orders ----
$items[] = [
    'name' => '9b. Suppliers, GRN & Purchase orders',
    'description' => 'Procurement: suppliers, goods received notes (GRN), purchase orders (PO). X-Business-Id required.',
    'item' => [
        req('List Suppliers', 'GET', 'suppliers', 'List suppliers. Query: q, is_active. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['q', ''], ['is_active', 'true']]),
        req('Create Supplier', 'POST', 'suppliers', 'Create supplier. name required. X-Business-Id required.', '{
  "name": "Acme Wholesale",
  "code": "ACM-01",
  "phone": "+2348000000000",
  "email": "orders@acme.example",
  "default_currency": "NGN",
  "is_active": true
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Supplier', 'GET', 'suppliers/{{supplier_id}}', 'Get supplier. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Supplier', 'PUT', 'suppliers/{{supplier_id}}', 'Update supplier. X-Business-Id required.', '{"name": "Acme Wholesale Ltd"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Delete Supplier', 'DELETE', 'suppliers/{{supplier_id}}', 'Delete supplier. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Supplier Prices', 'GET', 'suppliers/{{supplier_id}}/prices', 'List supplier product prices. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),

        req('GRN Analytics: Receipts by Supplier', 'GET', 'grn/analytics/receipts-by-supplier', 'Posted GRNs grouped by supplier. Query: days (default 30). X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['days', '30']]),
        req('List GRNs', 'GET', 'goods-received-notes', 'List goods received notes. Query: branch_id, status. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['branch_id', '{{branch_id}}'], ['status', 'draft']]),
        req('Create GRN', 'POST', 'goods-received-notes', 'Create draft GRN. branch_id and supplier_id required. X-Business-Id required.', '{
  "branch_id": 1,
  "supplier_id": 1,
  "supplier_invoice_number": "INV-2026-001",
  "notes": "Shipment dock 2"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get GRN', 'GET', 'goods-received-notes/{{grn_id}}', 'Get one GRN. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update GRN', 'PUT', 'goods-received-notes/{{grn_id}}', 'Update GRN header fields. X-Business-Id required.', '{"notes": "Updated notes"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Delete GRN', 'DELETE', 'goods-received-notes/{{grn_id}}', 'Delete GRN when allowed. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Submit GRN', 'POST', 'goods-received-notes/{{grn_id}}/submit', 'Submit GRN for approval. X-Business-Id required.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Approve GRN', 'POST', 'goods-received-notes/{{grn_id}}/approve', 'Approve and post GRN. X-Business-Id required.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Reject GRN', 'POST', 'goods-received-notes/{{grn_id}}/reject', 'Reject GRN. Body: reason. X-Business-Id required.', '{"reason": "Invoice mismatch"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Cancel GRN', 'POST', 'goods-received-notes/{{grn_id}}/cancel', 'Cancel GRN. X-Business-Id required.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Add GRN Line', 'POST', 'goods-received-notes/{{grn_id}}/lines', 'Add GRN line. quantity_received/accepted/rejected are numeric (min 0.001 for received). X-Business-Id required.', '{
  "product_id": 1,
  "branch_product_id": 1,
  "quantity_received": 10.5,
  "quantity_accepted": 10.5,
  "quantity_rejected": 0,
  "unit_cost": 500,
  "storage_location": "store"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update GRN Line', 'PUT', 'goods-received-notes/{{grn_id}}/lines/{{grn_line_id}}', 'Update GRN line. X-Business-Id required.', '{
  "product_id": 1,
  "branch_product_id": 1,
  "quantity_received": 24,
  "quantity_accepted": 22,
  "quantity_rejected": 2,
  "rejection_reason": "Damaged",
  "unit_cost": 500
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Delete GRN Line', 'DELETE', 'goods-received-notes/{{grn_id}}/lines/{{grn_line_id}}', 'Delete GRN line. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),

        req('List Purchase Orders', 'GET', 'purchase-orders', 'List purchase orders. Query: branch_id, status, supplier_id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['branch_id', '{{branch_id}}'], ['status', 'draft']]),
        req('PO Analytics: Top Variance Items', 'GET', 'purchase-orders/analytics/top-variance-items', 'Top variance items. Query: limit. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['limit', '10']]),
        req('Create Purchase Order', 'POST', 'purchase-orders', 'Create PO. branch_id, supplier_id and lines[] required. lines.*.quantity_ordered: numeric, min 0.001. X-Business-Id required.', '{
  "branch_id": 1,
  "supplier_id": 1,
  "expected_at": "2026-06-01",
  "currency": "NGN",
  "notes": "Reorder fast movers",
  "lines": [
    {
      "product_id": 1,
      "branch_product_id": 1,
      "quantity_ordered": 100,
      "unit_cost": 450
    }
  ]
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Purchase Order', 'GET', 'purchase-orders/{{purchase_order_id}}', 'Get purchase order. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Purchase Order', 'PUT', 'purchase-orders/{{purchase_order_id}}', 'Update draft purchase order header. X-Business-Id required.', '{"notes": "Split delivery OK"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Submit Purchase Order', 'POST', 'purchase-orders/{{purchase_order_id}}/submit', 'Submit PO. X-Business-Id required.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Cancel Purchase Order', 'POST', 'purchase-orders/{{purchase_order_id}}/cancel', 'Cancel PO. X-Business-Id required.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Purchase Order Receivable', 'GET', 'purchase-orders/{{purchase_order_id}}/receivable', 'Receivable breakdown. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 10. Inventory ----
$items[] = [
    'name' => '10. Inventory',
    'item' => [
        req('List Inventory Transactions', 'GET', 'inventory/transactions', "List inventory transactions.\n\n**Query:**\n- branch_id: optional | integer\n- product_id: optional | integer\n- type: optional | in:purchase,sale,adjustment,transfer_out,transfer_in,return,damage,initial\n- start_date: optional | date\n- end_date: optional | date\n- reference_number: optional | string\n- per_page: optional | integer\n\nX-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['branch_id', '{{branch_id}}'], ['per_page', '15']]),
        req('Create Inventory Transaction', 'POST', 'inventory/transactions', "Create an inventory transaction.\n\n**Fields:**\n- branch_id: required | integer | exists:branches\n- product_id: required | integer | exists:products\n- type: required | in:purchase,sale,adjustment,transfer_out,transfer_in,return,damage,initial\n- quantity: required | numeric | not:0 (positive=in, negative=out)\n- shelf_quantity: nullable | numeric\n- store_quantity: nullable | numeric\n- location: optional | in:shelf,store,both (default: both)\n- unit_cost: nullable | numeric | min:0\n- reference_number: nullable | string | max:255\n- related_branch_id: nullable | integer | exists:branches (for transfers)\n- notes: nullable | string\n- meta_data: nullable | array\n- batch_number: nullable | string | max:100\n- lot_number: nullable | string | max:100\n- manufacturing_date: nullable | date\n- expiry_date: nullable | date\n- supplier_id: nullable | integer | exists:suppliers,id\n- supplier_name: nullable | string | max:255 (legacy fallback)\n- supplier_reference: nullable | string | max:255\n\nServer allocates batches (FEFO) for batch-tracked products.", '{
  "branch_id": 1,
  "product_id": 1,
  "type": "adjustment",
  "quantity": 25,
  "shelf_quantity": null,
  "store_quantity": null,
  "location": "both",
  "unit_cost": 10.50,
  "reference_number": "ADJ-001",
  "related_branch_id": null,
  "notes": "Stock count correction",
  "meta_data": {},
  "batch_number": null,
  "lot_number": null,
  "manufacturing_date": null,
  "expiry_date": null,
  "supplier_id": null,
  "supplier_name": null,
  "supplier_reference": null
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Inventory Transaction', 'GET', 'inventory/transactions/{{inventory_transaction_id}}', 'Get one transaction. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Inventory Stock Summary', 'GET', 'inventory/stock-summary', 'Stock summary. Query: branch_id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 11. Customers ----
$items[] = [
    'name' => '11. Customers',
    'description' => 'CRUD for customers. Types: walk-in, regular, vip. credit_limit, metadata. Filter by type, is_active, search. X-Business-Id required.',
    'item' => [
        req('List Customers', 'GET', 'customers', 'List customers. Query: type (walk-in|regular|vip), is_active, search, per_page. X-Business-Id or current_business_id.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Customer', 'POST', 'customers', "Create customer.\n\n**Fields:**\n- name: required | string | max:255\n- email: nullable | email | max:255\n- phone: nullable | string | max:50\n- address: nullable | string | max:500\n- type: optional | in:walk-in,regular,vip (default: walk-in)\n- credit_limit: nullable | numeric | min:0\n- is_active: optional | boolean\n- metadata: nullable | array", '{
  "name": "John Customer",
  "email": "john@example.com",
  "phone": "+15559876543",
  "address": "123 Customer Lane",
  "type": "regular",
  "credit_limit": 500,
  "metadata": {}
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Customer', 'GET', 'customers/{{customer_id}}', 'Get one customer. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Customer', 'PUT', 'customers/{{customer_id}}', 'Update customer. Same fields as create.', '{"name": "John Customer Updated", "credit_limit": 1000}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Delete Customer', 'DELETE', 'customers/{{customer_id}}', 'Delete customer. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 12. Payment Methods ----
$items[] = [
    'name' => '12. Payment Methods',
    'item' => [
        req('List Payment Methods', 'GET', 'payment-methods', 'List payment methods. Query: is_active. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Payment Method', 'POST', 'payment-methods', "Create payment method.\n\n**Fields:**\n- name: required | string | max:255\n- type: required | in:cash,card,mobile_money,bank_transfer,cheque,other\n- description: nullable | string | max:500\n- account_details: nullable | array\n- is_active: optional | boolean\n- sort_order: optional | integer", '{
  "name": "Cash",
  "type": "cash",
  "description": "Cash payments",
  "account_details": {},
  "is_active": true,
  "sort_order": 10
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Payment Method', 'GET', 'payment-methods/{{payment_method_id}}', 'Get one payment method. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Payment Method', 'PUT', 'payment-methods/{{payment_method_id}}', 'Update payment method. Same fields as create.', '{"name": "Cash Register", "is_active": true}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Delete Payment Method', 'DELETE', 'payment-methods/{{payment_method_id}}', 'Delete payment method. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 13. Sales ----
$items[] = [
    'name' => '13. Sales',
    'description' => '**Search: sales, deposit, by-reference, complete-deposit.** Sales (POS). Open a **deposit** with **Create Sale** using `sale_type: "deposit"`. Deposit **stock policy** is in **2c. Business settings** (`deposit_stock_mode`). Subfolder **Deposits** has lookup + complete-only requests. Unit price: tiered pricing or override permission. Shift must be open for new sales. X-Business-Id required.',
    'item' => [
        [
            'name' => 'Deposits (search: deposit, complete-deposit)',
            'description' => "**Search: deposit, complete-deposit, by-reference, recall.**\n\n- **Create** a deposit sale: use **Create Sale** in this folder with `sale_type: \"deposit\"` (and other required fields).\n- **When stock moves** (on create vs on complete): **2c. Business settings** → `GET/PUT settings/business` → `deposit_stock_mode`.\n- **Lookup** a sale: `GET /sales/by-reference/{reference}` (matches `sale_number` or `reference_id`).\n- **Complete** a pending deposit: `POST /sales/{id}/complete-deposit`.\n\nX-Business-Id required for all.",
            'item' => [
                req('GET sales/by-reference/{ref} — recall / lookup', 'GET', 'sales/by-reference/{{sale_reference}}', "Look up a sale by **sale_number** or **reference_id** (path segment = `{{sale_reference}}`). Common for **recalling a deposit** at the till.\n\nX-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
                req('POST sales/{id}/complete-deposit — finish deposit', 'POST', 'sales/{{sale_id}}/complete-deposit', "Complete a **deposit** sale (`sale_type: deposit`, status **pending**). Optional final **payments**; if you send payments, you need an **open shift** on the sale’s branch.\n\n**Fields:**\n- payments: optional | array of { payment_method_id, amount, reference_number?, notes? }\n- closing_notes: optional | string\n\nX-Business-Id required.", '{
  "payments": [
    {
      "payment_method_id": 1,
      "amount": 50.00,
      "reference_number": null,
      "notes": null
    }
  ],
  "closing_notes": null
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
            ],
        ],
        req('List Sales', 'GET', 'sales', "List sales.\n\n**Query:**\n- branch_id: optional | integer\n- start_date: optional | date (Y-m-d)\n- end_date: optional | date (Y-m-d)\n- status: optional | in:completed,voided\n- customer_id: optional | integer\n- per_page: optional | integer\n\nX-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['branch_id', '{{branch_id}}'], ['per_page', '15']]),
        req('Create Sale', 'POST', 'sales', "Create a new sale. Shift must be open.\n\n**Fields:**\n- branch_id: required | integer | exists:branches\n- customer_id: nullable | integer | exists:customers\n- shift_id: nullable | integer | exists:sales_shifts\n- sale_type: optional | in:pos,online,delivery,wholesale,**deposit** (use **deposit** for layaway / deposit flow)\n- discount_amount: optional | numeric | min:0\n- notes: nullable | string\n- items: required | array | min:1\n  - items.*.product_id: required | integer | exists:products\n  - items.*.quantity: required | numeric | min:0.01\n  - items.*.unit_price: optional | numeric | min:0 (auto-computed from tiered pricing unless overridden; override requires 'override sale price' permission)\n  - items.*.discount_percentage: optional | numeric | min:0 | max:100\n  - items.*.tax_rate: optional | numeric | min:0\n  - items.*.batch_id: nullable | integer\n- payments: optional | array\n  - payments.*.payment_method_id: required | integer | exists:payment_methods\n  - payments.*.amount: required | numeric | min:0.01\n  - payments.*.reference_number: nullable | string | max:255", '{
  "branch_id": 1,
  "customer_id": null,
  "shift_id": null,
  "sale_type": "pos",
  "discount_amount": 0,
  "notes": null,
  "items": [
    {
      "product_id": 1,
      "quantity": 2,
      "unit_price": 29.99,
      "discount_percentage": 0,
      "tax_rate": 10
    }
  ],
  "payments": [
    {
      "payment_method_id": 1,
      "amount": 65.98,
      "reference_number": null
    }
  ]
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Sale', 'GET', 'sales/{{sale_id}}', 'Get one sale with items and payments. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Add Payment to Sale', 'POST', 'sales/{{sale_id}}/payments', 'Add a payment to a sale. payment_method_id, amount required. reference_number, notes optional.', '{
  "payment_method_id": 1,
  "amount": 50.00,
  "reference_number": "TXN-123",
  "notes": null
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Cancel Sale', 'POST', 'sales/{{sale_id}}/cancel', 'Cancel/void a sale. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 14. Sales Shifts ----
$items[] = [
    'name' => '14. Sales Shifts',
    'item' => [
        req('List Shifts', 'GET', 'shifts', "List shifts.\n\n**Query:**\n- status: optional | in:open,paused,closed\n- branch_id: optional | integer\n- user_id: optional | integer\n- filter: optional | in:today,last_7_days\n- start_date: optional | date\n- end_date: optional | date\n- has_discrepancy: optional | boolean\n- per_page: optional | integer\n\nX-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['branch_id', '{{branch_id}}']]),
        req('Open Shift', 'POST', 'shifts', 'Start a new shift. branch_id, opening_balance required. opening_notes optional. User can have only one active (open or paused) shift.', '{
  "branch_id": 1,
  "opening_balance": 100.00,
  "opening_notes": "Morning shift"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Current Shift', 'GET', 'shifts/current', 'Get current open or paused shift for the authenticated user. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Shift', 'GET', 'shifts/{{shift_id}}', 'Get one shift with stats and sales details. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Shift Sales', 'GET', 'shifts/{{shift_id}}/sales', 'List sales for a shift. Query: status (active|voided), payment_method. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Close Shift', 'POST', 'shifts/{{shift_id}}/close', 'Close shift. actual_cash, pin_code required. closing_notes optional. User must have PIN set; PIN verified. Can close from open or paused.', '{
  "actual_cash": 450.00,
  "closing_notes": "End of day",
  "pin_code": "123456"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Pause Shift', 'POST', 'shifts/{{shift_id}}/pause', 'Pause an open shift. No sales allowed while paused. No body required.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Resume Shift', 'POST', 'shifts/{{shift_id}}/resume', 'Resume a paused shift. pin_code required (6 digits). User must have PIN set.', '{"pin_code": "123456"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Resolve Shift Discrepancy', 'POST', 'shifts/{{shift_id}}/resolve-discrepancy', 'Mark shift variance as resolved. resolution_notes required. Shift must be closed with variance.', '{"resolution_notes": "Counted twice, variance explained."}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Branch Shifts Summary', 'GET', 'shifts/branch-summary', "Summary of shifts for a branch.\n\n**Query:**\n- branch_id: required | integer | exists:branches\n- start_date: optional | date\n- end_date: optional | date | after_or_equal:start_date\n- user_id: optional | integer | exists:users\n\nX-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['branch_id', '{{branch_id}}']]),
        req('POST shifts/backfill-groups (link shift → device group)', 'POST', 'shifts/backfill-groups', "Admin/maintenance: set **SalesShift.group_id** from the **device**'s **DeviceGroup** when the shift was missing `group_id`. **Not** the CRUD under `device-groups`—this only backfills old shift rows. No body. Requires owner, **view all shifts**, or **create shift**.\n\n**Response:** scanned, updated, skipped counts.\n\nX-Business-Id required.", '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Shift Summary', 'GET', 'shifts/{{shift_id}}/summary', 'Get summary for a specific shift (totals, payment breakdown, etc.). X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 15. Batches ----
$items[] = [
    'name' => '15. Batches',
    'description' => 'Product batches (FEFO, expiry). List with filters: branch_id, product_id, status, near_expiry (days), expired. Responses include quick_sale_requested. Update status (active|depleted|expired|recalled). X-Business-Id required.',
    'item' => [
        req('List Batches', 'GET', 'batches', 'List batches. Each item includes product, branch, quick_sale_requested_count, quick_sale_requested. Query: branch_id, product_id, status (active|depleted|expired|recalled), expired (true), near_expiry (days), batch_number, lot_number, sort_by (default expiry_date), sort_direction, per_page. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Batches Near Expiry', 'GET', 'batches/near-expiry', 'Batches nearing expiry. Response: batches (with product, branch, quick_sale_requested), count, days_threshold. Query: days (default 30), branch_id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['days', '40'], ['branch_id', '{{branch_id}}']]),
        req('Expired Batches', 'GET', 'batches/expired', 'List expired batches with current_quantity > 0. Response: batches (slim product/branch), count, total_value. Query: branch_id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Batch', 'GET', 'batches/1', 'Get one batch with product, branch, inventory transaction, quick_sale_requested. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Batch', 'PATCH', 'batches/1', 'Update batch. Body: status (active|depleted|expired|recalled), lot_number, supplier_name, supplier_reference, notes. X-Business-Id required.', '{"status": "active", "lot_number": "LOT-001", "supplier_name": "Acme", "notes": "Extended"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Batches for Product', 'GET', 'products/{{product_id}}/batches', 'List batches for a product (FEFO order). Response: batches array with branch, expiry, quantities, quick_sale_requested. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 16. Analytics ----
$items[] = [
    'name' => '16. Analytics',
    'item' => [
        req('Organization Analytics', 'GET', 'analytics/organization', "Organization-wide analytics.\n\n**Query:**\n- start_date: optional | date\n- end_date: optional | date | after_or_equal:start_date\n- compare_previous: optional | boolean\n\nX-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['start_date', '2026-02-01'], ['end_date', '2026-02-28'], ['compare_previous', 'true']]),
        req('Branch Analytics', 'GET', 'analytics/branches', "Analytics by branch.\n\n**Query:**\n- start_date: optional | date\n- end_date: optional | date | after_or_equal:start_date\n- branch_id: optional | integer (all branches if omitted)\n\nX-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['start_date', '2026-02-01'], ['end_date', '2026-02-28']]),
        req('Product Analytics', 'GET', 'analytics/products', "Product performance analytics.\n\n**Query:**\n- start_date: optional | date\n- end_date: optional | date | after_or_equal:start_date\n- branch_id: optional | integer\n- limit: optional | integer (default 10)\n- sort_by: optional | in:quantity,revenue,profit\n- direction: optional | in:asc,desc\n\nX-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['start_date', '2026-02-01'], ['end_date', '2026-02-28'], ['limit', '10']]),
        req('Profit & Loss', 'GET', 'analytics/profit-loss', "Profit & loss report.\n\n**Query:**\n- start_date: optional | date\n- end_date: optional | date | after_or_equal:start_date\n- branch_id: optional | integer\n\nX-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['start_date', '2026-02-01'], ['end_date', '2026-02-28']]),
        req('Growth Trends', 'GET', 'analytics/growth-trends', "Growth trend analysis over time.\n\n**Query:**\n- start_date: optional | date\n- end_date: optional | date | after_or_equal:start_date\n- period: optional | in:day,week,month (default: day)\n- branch_id: optional | integer\n\nX-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']], false, [['start_date', '2026-02-01'], ['end_date', '2026-02-28'], ['period', 'day']]),
    ],
];

// ---- 17. Stock Transfer Requests ----
$items[] = [
    'name' => '17. Stock Transfer Requests',
    'description' => 'Move stock between branches. Create request (branch_from_id, branch_to_id, branch_product_id, quantity). Approve at sending branch; accept/reject at receiving branch; confirm receipt. Cancel from sending branch. X-Business-Id required.',
    'item' => [
        req('List Stock Transfer Requests', 'GET', 'stock-transfer-requests', 'List transfer requests. Query: status, branch_id, per_page. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Stock Transfer Request', 'POST', 'stock-transfer-requests', 'Create a single-item transfer request. branch_from_id, branch_to_id, branch_product_id (branch_product at source branch), quantity_requested required. reason, priority (low|normal|high|urgent) optional.', '{
  "branch_from_id": 1,
  "branch_to_id": 2,
  "branch_product_id": 1,
  "quantity_requested": 20,
  "reason": "Restock request",
  "priority": "normal"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Stock Transfer Request', 'GET', 'stock-transfer-requests/1', 'Get one request. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Approve Transfer', 'POST', 'stock-transfer-requests/1/approve', 'Approve out-request at sending branch. Creates transfer-in request for receiving branch. Optional body: notes. X-Business-Id required.', '{"notes": null}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Accept Transfer', 'POST', 'stock-transfer-requests/1/accept', 'Accept transfer at receiving branch (in-request). Confirms receipt. X-Business-Id required.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Reject In (Receiving Branch)', 'POST', 'stock-transfer-requests/1/reject-in', 'Reject at receiving branch. reason required (max 500). Reverses stock at sending branch. X-Business-Id required.', '{"reason": "Wrong items sent"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Reject (Sending Branch)', 'POST', 'stock-transfer-requests/1/reject', 'Reject out-request at sending branch. reason required (max 500). X-Business-Id required.', '{"reason": "Insufficient stock"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Confirm Transfer', 'POST', 'stock-transfer-requests/1/confirm', 'Confirm receipt at destination (in-request). actual_quantity (optional, default=requested), notes optional. X-Business-Id required.', '{"actual_quantity": null, "notes": null}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Cancel Transfer', 'POST', 'stock-transfer-requests/1/cancel', 'Cancel a request. reason required (max 500). X-Business-Id required.', '{"reason": "No longer needed"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 17b. Shelf/Store Move Requests ----
$items[] = [
    'name' => '17b. Shelf/Store Move Requests',
    'description' => 'Request-based workflow for moving stock between shelf and store within a branch. Create request (branch_product_id, direction: to_shelf|to_store, quantity). Approvers approve or reject; on approve the move is performed. Permissions: request shelf store move, approve shelf store move. Direct move via branch-products move-to-shelf/store requires approve permission. X-Business-Id required.',
    'item' => [
        req('List Shelf/Store Move Requests', 'GET', 'shelf-store-move-requests', 'List move requests. Query: branch_id, status, my_requests, pending_approval, per_page. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Shelf/Store Move Request', 'POST', 'shelf-store-move-requests', 'Request to move stock. branch_product_id, direction (to_shelf|to_store), quantity required. reason optional. Requires request shelf store move.', '{
  "branch_product_id": 1,
  "direction": "to_shelf",
  "quantity": 5,
  "reason": "Restock shelf"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Shelf/Store Move Request', 'GET', 'shelf-store-move-requests/1', 'Get one request. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Approve Shelf/Store Move', 'POST', 'shelf-store-move-requests/1/approve', 'Approve and perform the move. Requires approve shelf store move.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Reject Shelf/Store Move', 'POST', 'shelf-store-move-requests/1/reject', 'Reject the request. reason optional (max 500).', '{"reason": "Not needed"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 18. Stock Write-offs ----
$items[] = [
    'name' => '18. Stock Write-offs',
    'item' => [
        req('List Stock Write-offs', 'GET', 'stock-writeoffs', 'List write-offs. Query: branch_id, product_id, start_date, end_date. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Stock Write-off', 'POST', 'stock-writeoffs', 'Create write-off. Pass product_id + branch_id, or branch_product_id. quantity, source (shelf|store), reason required. reason max 1000 chars. Deducts from batches (FEFO) when product uses batch tracking.', '{
  "current_business_id": 1,
  "branch_id": 1,
  "product_id": 1,
  "quantity": 5,
  "source": "shelf",
  "reason": "Damaged - water damage"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Stock Write-off', 'GET', 'stock-writeoffs/1', 'Get one write-off. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Write Off Entire Batch', 'POST', 'stock-writeoffs/writeoff-batch', "Write off all remaining quantity from a batch.\n\n**Fields:**\n- batch_id: required | integer | exists:product_batches,id\n- reason: required | string | max:1000\n- current_business_id: required | integer | exists:businesses,id\n\nX-Business-Id required.", '{
  "batch_id": 1,
  "reason": "Entire batch expired",
  "current_business_id": 1
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 19. Refund Requests ----
$items[] = [
    'name' => '19. Refund Requests',
    'description' => 'Refund workflow. Create request: refund_scope whole_sale (default) or items. For items, send items array with sale_item_id and quantity (partial refund). Approve restores inventory and updates sale refunded_amount; reject with reason. One pending request per sale. X-Business-Id required.',
    'item' => [
        req('List Refund Requests', 'GET', 'refund-requests', 'List refund requests. Query: status, branch_id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Refund Request', 'POST', 'refund-requests', 'Create refund request. sale_id, reason required. refund_scope: whole_sale (default) or items. For items, pass items: [{ sale_item_id, quantity }].', '{
  "sale_id": 1,
  "reason": "Customer return",
  "refund_scope": "whole_sale"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Refund Request (Partial)', 'POST', 'refund-requests', 'Partial refund: pass refund_scope: items and items array.', '{
  "sale_id": 1,
  "reason": "Return 2 units only",
  "refund_scope": "items",
  "items": [{"sale_item_id": 1, "quantity": 2}]
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Refund Request', 'GET', 'refund-requests/1', 'Get one request. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Approve Refund', 'POST', 'refund-requests/1/approve', 'Approve refund. Requires approver permission. X-Business-Id required.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Reject Refund', 'POST', 'refund-requests/1/reject', "Reject refund request.\n\n**Fields:**\n- rejection_reason: required | string | max:500\n\nX-Business-Id required.", '{"rejection_reason": "No receipt provided"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 20. Quick Sales ----
$items[] = [
    'name' => '20. Quick Sales',
    'description' => 'Near-expiry or promotional quick-sale requests. Create with branch_product_id (or batch), discount_percentage, start_date, end_date. Approve/reject/end. When approved and active, discount applies to branch product. X-Business-Id required.',
    'item' => [
        req('List Quick Sales', 'GET', 'quick-sales', "List quick sale (near-expiry discount) requests.\n\n**Query:**\n- status: optional (pending|approved|rejected|ended)\n- branch_id: optional (integer)\n\nX-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Quick Sale', 'POST', 'quick-sales', "Request quick sale for a near-expiry product.\n\n**Fields:**\n- product_id: required | integer | exists:products\n- branch_id: required | integer | exists:branches\n- batch_id: nullable | integer | exists:product_batches\n- reason: required | string | min:10 | max:1000\n- expiry_date: required | date | after:today\n- discount_type: optional | percentage | fixed\n- discount_value: optional | numeric | min:0\n- start_time: optional | date | after_or_equal:now\n- end_time: optional | date | after:start_time (when start_time present)\n\n**Auto-approve:** If the user has **approve quick sale** (or is business owner) and sends **all four** optional discount/period fields, the quick sale is created and approved in one step (same rules as Approve endpoint). If `start_time` is now or past, status becomes **active** and discount applies to the branch product.\n\nX-Business-Id required.", '{
  "product_id": 1,
  "branch_id": 1,
  "batch_id": null,
  "reason": "Near expiry - 2 weeks left",
  "expiry_date": "2026-03-15"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Quick Sale', 'GET', 'quick-sales/1', 'Get one quick sale with product, branch, batch details. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Approve Quick Sale', 'POST', 'quick-sales/1/approve', "Approve quick sale and set discount details.\n\n**Fields:**\n- discount_type: required | in:percentage,fixed\n- discount_value: required | numeric | min:0\n- start_time: required | date\n- end_time: required | date | after:start_time\n\nX-Business-Id required.", '{
  "discount_type": "percentage",
  "discount_value": 20,
  "start_time": "2026-02-26 00:00:00",
  "end_time": "2026-03-15 23:59:59"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Reject Quick Sale', 'POST', 'quick-sales/1/reject', "Reject quick sale.\n\n**Fields:**\n- rejection_reason: required | string | max:500\n\nX-Business-Id required.", '{
  "rejection_reason": "Discount too steep, try 10%"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('End Quick Sale', 'POST', 'quick-sales/1/end', 'End active quick sale early. No body needed. X-Business-Id required.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 21. Sync (Offline / Device) ----
$items[] = [
    'name' => '21. Sync (Offline / Device)',
    'description' => 'Offline-first device sync. Register device; bootstrap (initial data); pull (changes since timestamp); push (local sales, customers, etc.); resolve conflicts; status; heartbeat. Use X-Business-Id and X-Device-Id.',
    'item' => [
        req('Register Device', 'POST', 'sync/register-device', 'Register device for sync. device_id, device_name, device_type (web|desktop|mobile|tablet) required. branch_id, business_id, os, app_version, capabilities optional. X-Business-Id for context.', '{
  "device_id": "{{device_id}}",
  "device_name": "POS Terminal 1",
  "device_type": "desktop",
  "os": "Windows 10",
  "app_version": "1.0.0",
  "branch_id": 1,
  "business_id": 1,
  "capabilities": ["sales", "customers", "products"]
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Bootstrap', 'POST', 'sync/bootstrap', 'Initial data pull for device. session_id, device_id. Returns products, categories, branches, etc. X-Business-Id, X-Device-Id required.', '{"session_id": "{{$guid}}", "device_id": "{{device_id}}"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}'], ['key' => 'X-Device-Id', 'value' => '{{device_id}}']]),
        req('Pull', 'POST', 'sync/pull', 'Pull changes since last sync. session_id, device_id, since_timestamp optional. X-Business-Id, X-Device-Id required.', '{"session_id": "{{$guid}}", "device_id": "{{device_id}}", "since_timestamp": null}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}'], ['key' => 'X-Device-Id', 'value' => '{{device_id}}']]),
        req('Push', 'POST', 'sync/push', 'Push local changes. session_id, changes: { sales: [], customers: [], ... }. Each record: client_uuid, ... fields. X-Business-Id, X-Device-Id required.', '{
  "session_id": "{{$guid}}",
  "changes": {
    "sales": [{"client_uuid": "{{$guid}}", "sale_number": "SALE-OFFLINE-001", "branch_id": 1, "sale_type": "pos", "sale_date": "2026-02-21T12:00:00Z", "subtotal": 100, "tax_amount": 10, "discount_amount": 0, "total_amount": 110, "payment_status": "paid", "status": "completed", "items": [], "payments": []}],
    "customers": [{"client_uuid": "{{$guid}}", "customer_code": "CUST-OFFLINE-001", "name": "Walk-in", "email": null, "phone": null, "type": "walk-in", "version": 1}]
  }
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}'], ['key' => 'X-Device-Id', 'value' => '{{device_id}}']]),
        req('Resolve Conflicts', 'POST', 'sync/resolve-conflicts', 'Submit conflict resolutions. session_id, resolutions. X-Business-Id, X-Device-Id required.', '{"session_id": "{{$guid}}", "resolutions": []}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}'], ['key' => 'X-Device-Id', 'value' => '{{device_id}}']]),
        req('Sync Status', 'GET', 'sync/status', 'Get sync status for device. X-Business-Id, X-Device-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}'], ['key' => 'X-Device-Id', 'value' => '{{device_id}}']]),
        req('Heartbeat', 'POST', 'sync/heartbeat', 'Keep sync session alive. device_id. X-Business-Id, X-Device-Id required.', '{"device_id": "{{device_id}}"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}'], ['key' => 'X-Device-Id', 'value' => '{{device_id}}']]),
        req('Online Devices', 'GET', 'sync/online-devices', "List devices currently online (heartbeat within threshold).\n\n**Query:**\n- branch_id: optional | integer\n\nX-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 22. Server Sync (Edge ↔ Cloud) ----
$items[] = [
    'name' => '22. Server Sync (Edge ↔ Cloud)',
    'description' => 'Server-to-server sync between edge and cloud. Push/pull from edge; receive/provide-changes on cloud. Status and health endpoints. X-Business-Id required.',
    'item' => [
        req('Server Sync Push (Edge → Cloud)', 'POST', 'server-sync/push', "Edge server pushes local changes to cloud.\n\n**Fields:**\n- session_id: required | string\n- server_id: required | string\n- changes: required | array (keyed by entity type)\n- last_sync_at: nullable | date\n\nX-Business-Id required.", '{
  "session_id": "edge-session-001",
  "server_id": "edge-server-001",
  "changes": {},
  "last_sync_at": null
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Server Sync Pull (Edge ← Cloud)', 'POST', 'server-sync/pull', "Edge server pulls changes from cloud.\n\n**Fields:**\n- session_id: required | string\n- server_id: required | string\n- last_sync_at: nullable | date\n- entities: nullable | array\n\nX-Business-Id required.", '{
  "session_id": "edge-session-001",
  "server_id": "edge-server-001",
  "last_sync_at": null,
  "entities": null
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Server Sync Status', 'GET', 'server-sync/status', "Get server-sync status (last sync time, pending changes, etc.).\n\nX-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Server Sync Health', 'GET', 'server-sync/health', "Health check for server-sync connectivity.\n\nX-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Server Sync Receive (Cloud ← Edge)', 'POST', 'server-sync/receive', "Cloud receives data pushed from edge.\n\n**Fields:**\n- session_id: required | string\n- server_id: required | string\n- changes: required | array\n- last_sync_at: nullable | date\n\nX-Business-Id required.", '{
  "session_id": "cloud-session-001",
  "server_id": "edge-server-001",
  "changes": {},
  "last_sync_at": null
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Server Sync Provide Changes (Cloud → Edge)', 'POST', 'server-sync/provide-changes', "Cloud provides changes to edge.\n\n**Fields:**\n- session_id: required | string\n- server_id: required | string\n- last_sync_at: nullable | date\n- entities: nullable | array\n\nX-Business-Id required.", '{
  "session_id": "cloud-session-001",
  "server_id": "edge-server-001",
  "last_sync_at": null,
  "entities": null
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- Sample Responses (Error Examples) ----
$items[] = [
    'name' => 'Sample Error Responses',
    'description' => 'Reference examples for common error responses returned by the API.',
    'item' => [
        array_merge(
            req('401 Unauthorized', 'GET', 'user', 'Example of an unauthenticated request (no or invalid Bearer token).', null, [], true),
            ['response' => [sampleResponse('401 Unauthorized', 401, json_encode(['message' => 'Unauthenticated.'], JSON_PRETTY_PRINT), 'Unauthorized')]]
        ),
        array_merge(
            req('422 Validation Error', 'POST', 'register', 'Example of a validation error response.', '{"name": "", "email": "invalid"}', [], true),
            ['response' => [sampleResponse('422 Validation Error', 422, json_encode([
                'message' => 'The name field is required. (and 2 more errors)',
                'errors' => [
                    'name' => ['The name field is required.'],
                    'email' => ['The email field must be a valid email address.'],
                    'password' => ['The password field is required.'],
                ],
            ], JSON_PRETTY_PRINT), 'Unprocessable Content')]]
        ),
        array_merge(
            req('403 Forbidden', 'POST', 'roles', 'Example of a forbidden action (insufficient permissions).', '{"name": "Admin"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
            ['response' => [sampleResponse('403 Forbidden', 403, json_encode(['message' => 'You do not have the required permissions.'], JSON_PRETTY_PRINT), 'Forbidden')]]
        ),
        array_merge(
            req('404 Not Found', 'GET', 'products/99999', 'Example of a not-found response.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
            ['response' => [sampleResponse('404 Not Found', 404, json_encode(['message' => 'Resource not found.'], JSON_PRETTY_PRINT), 'Not Found')]]
        ),
    ],
];

echo json_encode(array_merge($base, ['item' => $items]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
