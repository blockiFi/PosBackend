<?php

/**
 * Generates a complete Postman Collection v2.1 JSON for the POS Backend API.
 * Discovers all API routes from Laravel and merges with metadata (description, body, query, required/optional).
 * Run from project root: php scripts/generate_postman_collection.php > POS_Backend_API_Complete.postman_collection.json
 */

$projectRoot = dirname(__DIR__);
require $projectRoot.'/vendor/autoload.php';
$app = require_once $projectRoot.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$metadata = require __DIR__.'/postman_metadata.php';
if (! is_array($metadata)) {
    $metadata = [];
}

$routes = Illuminate\Support\Facades\Route::getRoutes();
$apiRoutes = [];
foreach ($routes as $route) {
    $uri = $route->uri();
    if (! str_starts_with($uri, 'api')) {
        continue;
    }
    foreach ($route->methods() as $method) {
        if ($method === 'HEAD') {
            continue;
        }
        $apiRoutes[] = ['method' => $method, 'uri' => $uri, 'action' => $route->getActionName()];
    }
}

/** Normalize URI for Postman: replace path params with {{var}} */
function normalizeUriForPostman(string $uri): string
{
    $replacements = [
        '{branchId}' => '{{branch_id}}',
        '{userId}' => '{{user_id}}',
        '{tierId}' => '{{tier_id}}',
        '{unitId}' => '{{unit_id}}',
        '{unitPriceId}' => '{{unit_price_id}}',
        '{id}' => '{{id}}',
    ];
    return str_replace(array_keys($replacements), array_values($replacements), $uri);
}

/** Build description markdown from metadata */
function buildDescription(array $meta, string $action): string
{
    $desc = $meta['description'] ?? 'See controller '.$action;
    $usage = $meta['usage'] ?? '';
    $parts = ["**Functionality:**\n".$desc];
    if ($usage !== '') {
        $parts[] = "**Usage:**\n".$usage;
    }
    $body = $meta['body'] ?? [];
    if ($body !== []) {
        $required = [];
        $optional = [];
        foreach ($body as $key => $opts) {
            $req = $opts['required'] ?? false;
            $type = $opts['type'] ?? 'string';
            if ($req) {
                $required[] = $key.' ('.$type.')';
            } else {
                $optional[] = $key.' ('.$type.')';
            }
        }
        $parts[] = '**Required body:** '.(count($required) > 0 ? implode(', ', $required) : 'None');
        $parts[] = '**Optional body:** '.(count($optional) > 0 ? implode(', ', $optional) : 'None');
    }
    $query = $meta['query'] ?? [];
    if ($query !== []) {
        $qList = [];
        foreach ($query as $key => $opts) {
            $req = $opts['required'] ?? false;
            $d = $opts['description'] ?? '';
            $qList[] = $key.($req ? ' (required)' : '').': '.$d;
        }
        $parts[] = "**Query/filters:**\n".implode("\n", $qList);
    }
    return implode("\n\n", $parts);
}

/** Build body JSON example from metadata */
function buildBodyExample(array $body): ?string
{
    if ($body === []) {
        return null;
    }
    $arr = [];
    foreach ($body as $key => $opts) {
        $ex = $opts['example'] ?? null;
        if ($ex === null && isset($opts['type'])) {
            $ex = match ($opts['type']) {
                'integer' => 1,
                'number' => 1.0,
                'boolean' => false,
                'array' => [],
                'object' => (object) [],
                default => '',
            };
        }
        $arr[$key] = $ex;
    }
    return json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/** Get folder name from URI (first segment after api/) */
function getFolderName(string $uri): string
{
    $parts = explode('/', $uri);
    if (count($parts) < 2) {
        return 'API';
    }
    $first = $parts[1];
    $folderMap = [
        'user' => '1. Authentication',
        'register' => '1. Authentication',
        'login' => '1. Authentication',
        'pin-login' => '1. Authentication',
        'business-details-with-branch-auth' => '1. Authentication',
        'pin' => '1. Authentication',
        'businesses' => '2. Businesses',
        'permissions' => '3. Permissions',
        'branches' => '4. Branches',
        'roles' => '5. Roles',
        'users' => '5. Roles',
        'business-users' => '6. User Business',
        'categories' => '7. Categories',
        'products' => '8. Products',
        'branch-products' => '9. Branch Products',
        'inventory' => '10. Inventory',
        'customers' => '11. Customers',
        'payment-methods' => '12. Payment Methods',
        'sales' => '13. Sales',
        'shifts' => '14. Sales Shifts',
        'batches' => '15. Batches',
        'analytics' => '16. Analytics',
        'stock-transfer-requests' => '17. Stock Transfer Requests',
        'shelf-store-move-requests' => '18. Shelf Store Move',
        'stock-writeoffs' => '19. Stock Write-offs',
        'refund-requests' => '20. Refund Requests',
        'quick-sales' => '21. Quick Sales',
        'sync' => '22. Sync',
        'server-sync' => '23. Server Sync',
    ];
    return $folderMap[$first] ?? ucfirst($first);
}

/** Humanize request name from method + uri */
function requestName(string $method, string $uri, string $action): string
{
    $uri = preg_replace('#\{[^}]+\}#', ':id', $uri);
    $parts = explode('/', $uri);
    $last = end($parts);
    if ($last === 'api' || $last === '') {
        $last = 'index';
    }
    $verb = match ($method) {
        'GET' => 'Get',
        'POST' => 'Create',
        'PUT' => 'Update',
        'PATCH' => 'Update',
        'DELETE' => 'Delete',
        default => $method,
    };
    $resource = str_replace(['api/', ':id'], '', $uri);
    $resource = trim($resource, '/');
    $resource = ucwords(str_replace(['-', '/'], ' ', $resource));
    if (str_contains($uri, '/') && $last !== 'index') {
        $suffix = ucfirst(str_replace('-', ' ', $last));
        return $verb.' '.$suffix;
    }
    return $verb.' '.$resource;
}

$base = [
    'info' => [
        '_postman_id' => 'pos-backend-api-complete-v2',
        'name' => 'POS Backend API - Complete Reference',
        'description' => "Complete Postman collection for the POS Backend. **All routes** with request bodies, query/filters, and required/optional fields.\n\n**Setup:**\n1. Set `base_url` (e.g. http://127.0.0.1:8000).\n2. Use Register or Login to get a token; store in `auth_token`.\n3. Set `business_id` and optionally `branch_id` for business-scoped requests.\n4. All protected routes use Bearer token. Use X-Business-Id header for business context.",
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    ],
    'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{auth_token}}', 'type' => 'string']]],
    'variable' => [
        ['key' => 'base_url', 'value' => 'http://127.0.0.1:8000', 'type' => 'string'],
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
        ['key' => 'id', 'value' => '1', 'type' => 'string'],
    ],
];

$folderItems = [];
foreach ($apiRoutes as $r) {
    $method = $r['method'];
    $uri = $r['uri'];
    $action = $r['action'];
    $key = $method.' '.$uri;
    $meta = $metadata[$key] ?? [];
    $noAuth = $meta['noAuth'] ?? false;
    $uriForUrl = normalizeUriForPostman($uri);
    $pathParts = array_values(array_filter(explode('/', $uriForUrl)));
    $url = [
        'raw' => '{{base_url}}/'.$uriForUrl,
        'host' => ['{{base_url}}'],
        'path' => $pathParts,
    ];
    $query = $meta['query'] ?? [];
    if ($query !== []) {
        $url['query'] = [];
        foreach ($query as $qk => $qOpts) {
            $url['query'][] = [
                'key' => $qk,
                'value' => isset($qOpts['example']) ? (string) $qOpts['example'] : '',
                'description' => $qOpts['description'] ?? '',
            ];
        }
    }
    $headers = [
        ['key' => 'Accept', 'value' => 'application/json'],
        ['key' => 'Content-Type', 'value' => 'application/json'],
    ];
    if (! $noAuth) {
        $headers[] = ['key' => 'X-Business-Id', 'value' => '{{business_id}}'];
        if (str_contains($uri, 'sync/') && ! str_contains($uri, 'server-sync')) {
            $headers[] = ['key' => 'X-Device-Id', 'value' => '{{device_id}}'];
        }
    }
    $request = [
        'method' => $method,
        'header' => $headers,
        'url' => $url,
        'description' => buildDescription($meta, $action),
    ];
    $body = $meta['body'] ?? [];
    if (in_array($method, ['POST', 'PUT', 'PATCH']) && $body !== []) {
        $request['body'] = ['mode' => 'raw', 'raw' => buildBodyExample($body)];
    }
    if ($noAuth) {
        $request['auth'] = ['type' => 'noauth'];
    }
    $name = $meta['name'] ?? requestName($method, $uri, $action);
    $item = ['name' => $name, 'request' => $request, 'response' => []];

    $folderName = getFolderName($uri);
    if (! isset($folderItems[$folderName])) {
        $folderItems[$folderName] = ['name' => $folderName, 'item' => []];
    }
    $folderItems[$folderName]['item'][] = $item;
}

ksort($folderItems);
$items = array_values($folderItems);

$collection = array_merge($base, ['item' => $items]);
echo json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
