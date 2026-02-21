<?php

/**
 * Generates a complete Postman Collection v2.1 JSON for the POS Backend API.
 * Run: php scripts/generate_postman_collection.php > POS_Backend_API_Complete.postman_collection.json
 */
$base = [
    'info' => [
        '_postman_id' => 'pos-backend-api-complete-v1',
        'name' => 'POS Backend API - Complete Reference',
        'description' => "Complete Postman collection for the POS Backend. Includes every route with full request bodies and detailed descriptions.\n\n**Setup:**\n1. Set `base_url` (e.g. http://127.0.0.1:8000/api).\n2. Use Register or Login to get a token; it is stored in `auth_token`.\n3. Set `business_id` and optionally `branch_id` for business-scoped requests.\n4. All protected routes use Bearer token automatically.",
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    ],
    'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{auth_token}}', 'type' => 'string']]],
    'variable' => [
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
    ],
];

function req(string $name, string $method, string $path, string $description, ?string $body = null, array $extraHeaders = [], bool $noAuth = false): array
{
    $pathParts = array_values(array_filter(explode('/', $path)));
    $url = ['raw' => '{{base_url}}/'.$path, 'host' => ['{{base_url}}'], 'path' => $pathParts];
    $headers = [
        ['key' => 'Accept', 'value' => 'application/json'],
        ['key' => 'Content-Type', 'value' => 'application/json'],
    ];
    foreach ($extraHeaders as $k => $v) {
        $headers[] = ['key' => $k, 'value' => $v];
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

// ---- 1. Authentication ----
$items[] = [
    'name' => '1. Authentication',
    'description' => 'Public and protected auth endpoints. Register and Login store the token in collection variable `auth_token`.',
    'item' => [
        req('Get Current User', 'GET', 'user', 'Returns the currently authenticated user. Requires valid Bearer token. Use to verify token or get user profile.', null, [], false),
        array_merge(req('Register', 'POST', 'register', '**Public.** Create a new user. Body: name, email, password, password_confirmation (min 8 chars). Returns user + token. Test script saves token to collection variable auth_token.', '{
  "name": "Jane Doe",
  "email": "jane.doe@example.com",
  "password": "SecurePassword123!",
  "password_confirmation": "SecurePassword123!"
}', [], true), ['event' => [['listen' => 'test', 'script' => ['exec' => ['if (pm.response.code === 201) { var j = pm.response.json(); if (j.token) pm.collectionVariables.set(\'auth_token\', j.token); }'], 'type' => 'text/javascript']]]]),
        array_merge(req('Login', 'POST', 'login', '**Public.** Login with email and password. Returns user + token. Test script saves token to collection variable auth_token.', '{
  "email": "jane.doe@example.com",
  "password": "SecurePassword123!"
}', [], true), ['event' => [['listen' => 'test', 'script' => ['exec' => ['if (pm.response.code === 200) { var j = pm.response.json(); if (j.token) pm.collectionVariables.set(\'auth_token\', j.token); }'], 'type' => 'text/javascript']]]]),
        req('PIN Login', 'POST', 'pin-login', "**Public.** Fast login with 6-digit PIN. User must have PIN set and 'use-pin-login' permission. pin_code: exactly 6 digits.", '{
  "pin_code": "123456"
}', [], true),
        req('Set PIN', 'POST', 'pin/set', "Set or update a user's PIN. user_id: target user. pin_code: 6 digits. If setting own PIN, password is required. Requires manage-pin-codes for other users.", '{
  "user_id": 1,
  "pin_code": "654321",
  "password": "SecurePassword123!"
}', []),
        req('Remove PIN', 'POST', 'pin/remove', "Remove PIN for a user. Requires password (current user's) and manage-pin-codes when removing another user's PIN.", '{
  "user_id": 1,
  "password": "SecurePassword123!"
}', []),
    ],
];

// ---- 2. Businesses ----
$items[] = [
    'name' => '2. Businesses',
    'item' => [
        req('List Businesses', 'GET', 'businesses', 'List all businesses the authenticated user belongs to. No business context required. Returns id, name, branches, pivot branch_id, etc.'),
        req('Create Business', 'POST', 'businesses', 'Create a new business. Creator becomes owner. All fields optional except name. Creates a main branch from main_branch_name/code. currency 3-letter (e.g. USD), country 2-letter. settings: arbitrary object.', '{
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
        req('Get Business', 'GET', 'businesses/{{business_id}}', 'Get one business by ID. Requires X-Business-Id header (business context).', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Business', 'PUT', 'businesses/{{business_id}}', 'Update business. Same fields as create (all optional). Requires X-Business-Id.', '{
  "name": "Acme Retail Updated",
  "email": "info@acme.com",
  "phone": "+1987654321",
  "address": "200 New Address",
  "default_tax_rate": 12
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Delete Business', 'DELETE', 'businesses/{{business_id}}', 'Delete a business (soft delete). Requires X-Business-Id.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 3. Permissions (no business context) ----
$items[] = [
    'name' => '3. Permissions (Global)',
    'item' => [
        req('List Permissions', 'GET', 'permissions', 'List all available permissions in the system. No business context. Used when building roles.'),
    ],
];

// ---- 4. Branches ----
$items[] = [
    'name' => '4. Branches',
    'item' => [
        req('List Branches', 'GET', 'branches', 'List branches for the business. Requires X-Business-Id. User sees only branches they have access to.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Branch', 'POST', 'branches', 'Create a branch. name, code required. code must be unique within business. tax_rate 0-100. is_main: mark as main branch.', '{
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

// ---- 5. Roles & Permissions ----
$items[] = [
    'name' => '5. Roles & Permissions',
    'item' => [
        req('List Roles', 'GET', 'roles', 'List all roles for the business. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Role', 'POST', 'roles', "Create a role. name required. guard_name usually 'api'. business_id from context.", '{
  "name": "Cashier",
  "guard_name": "api"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Add Permission to Role', 'POST', 'roles/addpermission', 'Attach a permission to a role. role_id, permission_id required. Optionally branch_id for branch-scoped role.', '{
  "role_id": 1,
  "permission_id": 1,
  "branch_id": null
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Remove Permission from Role', 'POST', 'roles/removepermission', 'Detach a permission from a role. role_id, permission_id required.', '{
  "role_id": 1,
  "permission_id": 1
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
        req('List Business Users', 'GET', 'business-users', 'List all users in the business with roles. Owner or manage-users. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Add User to Business', 'POST', 'business-users', 'Add a user by email. If email does not exist, creates new user with random password; password returned in data.password. name required. role_ids: optional array of role ids (business roles) to assign. Only owner can add.', '{
  "email": "newstaff@example.com",
  "name": "New Staff",
  "is_active": true,
  "role_ids": [1, 2]
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Business User', 'GET', 'business-users/{{user_id}}', "Get one user's details in the business: roles, permissions. X-Business-Id required.", null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Business User', 'PUT', 'business-users/{{user_id}}', "Update user's active status in the business. is_active required. Owner only.", '{"is_active": false}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Remove User from Business', 'DELETE', 'business-users/{{user_id}}', 'Remove user from business and clear their roles. Owner only. Cannot remove self.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 7. Product Categories ----
$items[] = [
    'name' => '7. Product Categories',
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

// ---- 8. Products ----
$items[] = [
    'name' => '8. Products',
    'item' => [
        req('List Products', 'GET', 'products', 'List products. Query: branch_id, category_id, search, per_page, is_active. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
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
        req('Add Product to Branch', 'POST', 'products/{{product_id}}/branches', 'Add product to a branch (creates branch_product). branch_id, selling_price, compare_price, stock_quantity, etc. Query: current_business_id.', '{
  "branch_id": 1,
  "selling_price": 29.99,
  "compare_price": 39.99,
  "cost_price": 15.99,
  "stock_quantity": 100,
  "discount_amount": 0,
  "is_available": true,
  "low_stock_threshold": 10
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Remove Product from Branch', 'DELETE', 'products/{{product_id}}/branches', 'Remove product from branch. Query: branch_id, current_business_id.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Product Price', 'PATCH', 'products/{{product_id}}/price', 'Update base selling price for product. selling_price required. Query: branch_id optional. X-Business-Id required.', '{"selling_price": 34.99}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Products by Branch', 'GET', 'branches/{{branch_id}}/products', 'Get products for a specific branch with branch-level pricing/stock. Query: category_id, search, per_page. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 9. Branch Products ----
$items[] = [
    'name' => '9. Branch Products',
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
        req('Get Branch Product', 'GET', 'branch-products/1', 'Get one branch product. Replace 1 with branch_product id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
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
        req('Update Branch Product Stock', 'POST', 'branch-products/1/stock', 'Adjust stock. quantity (integer), operation: add|subtract|set. X-Business-Id required.', '{"quantity": 10, "operation": "add"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Move Stock to Shelf', 'POST', 'branch-products/1/move-to-shelf', 'Move quantity from store to shelf. quantity required. X-Business-Id required.', '{"quantity": 5}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Move Stock to Store', 'POST', 'branch-products/1/move-to-store', 'Move quantity from shelf to store. quantity required. X-Business-Id required.', '{"quantity": 5}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Branch Products Stock Summary', 'GET', 'branch-products/summary/stock', 'Stock summary. Query: branch_id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Bulk Update Branch Products', 'POST', 'branch-products/bulk-update', 'Bulk update. updates: array of { id: branch_product_id, data: { ...fields } }. X-Business-Id required.', '{
  "updates": [
    {"id": 1, "data": {"is_available": true, "shelf_quantity": 30}},
    {"id": 2, "data": {"low_stock_threshold": 15}}
  ]
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 10. Inventory ----
$items[] = [
    'name' => '10. Inventory',
    'item' => [
        req('List Inventory Transactions', 'GET', 'inventory/transactions', 'List transactions. Query: branch_id, product_id, type, start_date, end_date, reference_number, per_page. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Inventory Transaction', 'POST', 'inventory/transactions', 'Create transaction. type: purchase|sale|adjustment|transfer_out|transfer_in|return|damage|initial. quantity non-zero integer. location: shelf|store|both. For transfers, related_branch_id. Batch: batch_number, lot_number, manufacturing_date, expiry_date, supplier_name.', '{
  "branch_id": 1,
  "product_id": 1,
  "type": "adjustment",
  "quantity": 25,
  "shelf_quantity": 20,
  "store_quantity": 5,
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
  "supplier_name": null,
  "supplier_reference": null
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Inventory Transaction', 'GET', 'inventory/transactions/1', 'Get one transaction. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Inventory Stock Summary', 'GET', 'inventory/stock-summary', 'Stock summary. Query: branch_id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 11. Customers ----
$items[] = [
    'name' => '11. Customers',
    'item' => [
        req('List Customers', 'GET', 'customers', 'List customers. Query: type (walk-in|regular|vip), is_active, search, per_page. X-Business-Id or current_business_id.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Customer', 'POST', 'customers', 'Create customer. name required. type: walk-in|regular|vip. credit_limit numeric. metadata object.', '{
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
        req('Create Payment Method', 'POST', 'payment-methods', 'Create payment method. name, type required. type: cash|card|mobile_money|bank_transfer|cheque|other. account_details object, sort_order.', '{
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
    'item' => [
        req('List Sales', 'GET', 'sales', 'List sales. Query: branch_id, start_date, end_date, status, customer_id, per_page. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Sale', 'POST', 'sales', 'Create sale. branch_id, items required. items: product_id, quantity, unit_price; optional discount_percentage, tax_rate. payments optional: payment_method_id, amount, reference_number. customer_id, shift_id optional. sale_type: pos|online|delivery|wholesale. Shift must be open.', '{
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
        req('Add Payment to Sale', 'POST', 'sales/{{sale_id}}/payments', 'Add a payment to a sale. payment_method_id, amount required. reference_number optional.', '{
  "payment_method_id": 1,
  "amount": 50.00,
  "reference_number": "TXN-123"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Cancel Sale', 'POST', 'sales/{{sale_id}}/cancel', 'Cancel/void a sale. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 14. Sales Shifts ----
$items[] = [
    'name' => '14. Sales Shifts',
    'item' => [
        req('List Shifts', 'GET', 'shifts', 'List shifts. Query: status, branch_id, user_id, filter (today|last_7_days), start_date, end_date, has_discrepancy. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
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
    ],
];

// ---- 15. Batches ----
$items[] = [
    'name' => '15. Batches',
    'item' => [
        req('List Batches', 'GET', 'batches', 'List batches. Query: branch_id, product_id, status (active|expired|exhausted), per_page. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Batches Near Expiry', 'GET', 'batches/near-expiry', 'Batches nearing expiry. Query: branch_id, product_id, days. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Expired Batches', 'GET', 'batches/expired', 'List expired batches. Query: branch_id, product_id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Batch', 'GET', 'batches/1', 'Get one batch. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Update Batch', 'PATCH', 'batches/1', 'Update batch. expiry_date, quantity_remaining, etc. X-Business-Id required.', '{"expiry_date": "2026-12-31", "notes": "Extended"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Batches for Product', 'GET', 'products/{{product_id}}/batches', 'List batches for a product. Query: branch_id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 16. Analytics ----
$items[] = [
    'name' => '16. Analytics',
    'item' => [
        req('Organization Analytics', 'GET', 'analytics/organization', 'Org-wide analytics. Query: start_date, end_date, compare_previous. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Branch Analytics', 'GET', 'analytics/branches', 'Analytics by branch. Query: start_date, end_date, branch_id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Product Analytics', 'GET', 'analytics/products', 'Product performance. Query: start_date, end_date, branch_id, limit. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Profit & Loss', 'GET', 'analytics/profit-loss', 'P&L report. Query: start_date, end_date, branch_id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Growth Trends', 'GET', 'analytics/growth-trends', 'Growth trends. Query: start_date, end_date, period (day|week|month), branch_id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 17. Stock Transfer Requests ----
$items[] = [
    'name' => '17. Stock Transfer Requests',
    'item' => [
        req('List Stock Transfer Requests', 'GET', 'stock-transfer-requests', 'List transfer requests. Query: status, branch_id, per_page. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Stock Transfer Request', 'POST', 'stock-transfer-requests', 'Create request. from_branch_id, to_branch_id, items (branch_product_id, quantity). notes optional.', '{
  "from_branch_id": 1,
  "to_branch_id": 2,
  "items": [{"branch_product_id": 1, "quantity": 20}],
  "notes": "Restock request"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Stock Transfer Request', 'GET', 'stock-transfer-requests/1', 'Get one request. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Approve Transfer', 'POST', 'stock-transfer-requests/1/approve', 'Approve a pending transfer. Optional body. X-Business-Id required.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Reject Transfer', 'POST', 'stock-transfer-requests/1/reject', 'Reject transfer. Optional reason. X-Business-Id required.', '{"reason": "Insufficient stock"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Confirm Transfer', 'POST', 'stock-transfer-requests/1/confirm', 'Confirm receipt at destination. X-Business-Id required.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Cancel Transfer', 'POST', 'stock-transfer-requests/1/cancel', 'Cancel a request. X-Business-Id required.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 18. Stock Write-offs ----
$items[] = [
    'name' => '18. Stock Write-offs',
    'item' => [
        req('List Stock Write-offs', 'GET', 'stock-writeoffs', 'List write-offs. Query: branch_id, product_id, start_date, end_date. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Stock Write-off', 'POST', 'stock-writeoffs', 'Create write-off. branch_id, product_id, quantity, reason required. batch_id optional.', '{
  "branch_id": 1,
  "product_id": 1,
  "quantity": 5,
  "reason": "Damaged",
  "batch_id": null,
  "notes": "Water damage"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Stock Write-off', 'GET', 'stock-writeoffs/1', 'Get one write-off. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 19. Refund Requests ----
$items[] = [
    'name' => '19. Refund Requests',
    'item' => [
        req('List Refund Requests', 'GET', 'refund-requests', 'List refund requests. Query: status, branch_id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Refund Request', 'POST', 'refund-requests', 'Create refund request. sale_id, reason required. amount optional (full refund if omitted).', '{
  "sale_id": 1,
  "reason": "Customer return",
  "amount": 29.99
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Refund Request', 'GET', 'refund-requests/1', 'Get one request. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Approve Refund', 'POST', 'refund-requests/1/approve', 'Approve refund. Requires approver permission. X-Business-Id required.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Reject Refund', 'POST', 'refund-requests/1/reject', 'Reject refund. X-Business-Id required.', '{"reason": "No receipt"}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 20. Quick Sales ----
$items[] = [
    'name' => '20. Quick Sales',
    'item' => [
        req('List Quick Sales', 'GET', 'quick-sales', 'List quick sale (near-expiry discount) requests. Query: status, branch_id. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Create Quick Sale', 'POST', 'quick-sales', 'Request quick sale. branch_product_id, discount_percentage, start_date, end_date. X-Business-Id required.', '{
  "branch_product_id": 1,
  "discount_percentage": 20,
  "start_date": "2026-02-01 00:00:00",
  "end_date": "2026-02-28 23:59:59"
}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Get Quick Sale', 'GET', 'quick-sales/1', 'Get one quick sale. X-Business-Id required.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Approve Quick Sale', 'POST', 'quick-sales/1/approve', 'Approve quick sale. X-Business-Id required.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Reject Quick Sale', 'POST', 'quick-sales/1/reject', 'Reject quick sale. X-Business-Id required.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('End Quick Sale', 'POST', 'quick-sales/1/end', 'End active quick sale early. X-Business-Id required.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

// ---- 21. Sync (Offline) ----
$items[] = [
    'name' => '21. Sync (Offline / Device)',
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
    ],
];

// ---- 22. Server Sync (Edge/Cloud) ----
$items[] = [
    'name' => '22. Server Sync (Edge ↔ Cloud)',
    'item' => [
        req('Server Sync Push', 'POST', 'server-sync/push', 'Edge: push data to cloud. Body and headers per server-sync implementation. X-Business-Id.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Server Sync Pull', 'POST', 'server-sync/pull', 'Edge: pull changes from cloud. X-Business-Id.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Server Sync Status', 'GET', 'server-sync/status', 'Get server-sync status. X-Business-Id.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Server Sync Health', 'GET', 'server-sync/health', 'Health check for server-sync. X-Business-Id.', null, [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Server Sync Receive', 'POST', 'server-sync/receive', 'Cloud: receive data from edge. X-Business-Id.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
        req('Server Sync Provide Changes', 'POST', 'server-sync/provide-changes', 'Cloud: provide changes to edge. X-Business-Id.', '{}', [['key' => 'X-Business-Id', 'value' => '{{business_id}}']]),
    ],
];

echo json_encode(array_merge($base, ['item' => $items]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
