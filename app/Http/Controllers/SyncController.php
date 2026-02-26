<?php

namespace App\Http\Controllers;

use App\Http\Traits\HasBranchAccess;
use App\Models\BranchProduct;
use App\Models\BranchProductQuantityTier;
use App\Models\BranchProductUnitPrice;
use App\Models\ChangeLog;
use App\Models\Customer;
use App\Models\DeviceRegistration;
use App\Models\InventoryTransaction;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\QuickSale;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesShift;
use App\Models\SyncSession;
use App\Services\InventoryBatchService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SyncController extends Controller
{
    use HasBranchAccess;

    public function __construct(
        protected InventoryBatchService $batchService
    ) {}

    /**
     * Register a new device for sync operations
     */
    public function registerDevice(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|string|max:50|unique:device_registrations,device_id',
            'device_name' => 'required|string|max:100',
            'device_type' => 'required|in:web,desktop,mobile,tablet',
            'os' => 'nullable|string|max:50',
            'app_version' => 'nullable|string|max:20',
            'branch_id' => 'nullable|exists:branches,id',
            'business_id' => 'nullable|exists:businesses,id',
            'capabilities' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json([
                'success' => false,
                'error' => 'Business context is required',
            ], 400);
        }

        setPermissionsTeamId($businessId);
        if (! $user->hasPermissionTo('sync data')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Verify branch access if branch_id provided
        if ($request->branch_id && ! $this->userHasBranchAccess($user, $businessId, $request->branch_id)) {
            return response()->json(['success' => false, 'message' => 'You do not have access to this branch'], 403);
        }

        $device = DeviceRegistration::create([
            'device_id' => $request->device_id,
            'business_id' => $businessId,
            'branch_id' => $request->branch_id,
            'user_id' => $user->id,
            'device_name' => $request->device_name,
            'device_type' => $request->device_type,
            'os' => $request->os,
            'app_version' => $request->app_version,
            'ip_address' => $request->ip(),
            'status' => 'active',
            'last_seen_at' => now(),
            'capabilities' => $request->capabilities ?? [],
            'metadata' => $request->metadata ?? [],
        ]);

        return response()->json([
            'device' => $device,
            'sync_token' => $request->bearerToken(),
        ], 201);
    }

    /**
     * Bootstrap - Download initial dataset for new/reset device
     */
    public function bootstrap(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => 'required|exists:branches,id',
            'business_id' => 'nullable|exists:businesses,id',
            'entities' => 'nullable|array',
            'entities.*' => 'in:products,categories,payment_methods,customers,branch_products,product_units,branch_product_unit_prices,branch_product_quantity_tiers',
            'include_history' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json([
                'success' => false,
                'error' => 'Business context is required',
            ], 400);
        }

        setPermissionsTeamId($businessId);
        if (! $user->hasPermissionTo('sync data')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Verify branch access
        if (! $this->userHasBranchAccess($user, $businessId, $request->branch_id)) {
            return response()->json(['success' => false, 'message' => 'You do not have access to this branch'], 403);
        }

        $branchId = $request->branch_id;
        $entities = $request->entities ?? ['products', 'categories', 'payment_methods', 'customers', 'branch_products'];

        $sessionId = Str::uuid()->toString();
        $data = [];

        // Load requested entities
        if (in_array('products', $entities)) {
            $data['products'] = Product::where('business_id', $businessId)
                // ->select('id', 'uuid', 'business_id', 'category_id', 'name', 'sku', 'barcode',
                //          'description', 'base_selling_price', 'base_cost_price', 'stock_tracking',
                //          'version', 'synced_at')
                ->get();
        }

        if (in_array('categories', $entities)) {
            $data['categories'] = ProductCategory::where('business_id', $businessId)
                // ->select('id', 'uuid', 'business_id', 'name', 'description')
                ->get();
        }

        if (in_array('payment_methods', $entities)) {
            $data['payment_methods'] = PaymentMethod::where('business_id', $businessId)
                // ->select('id', 'business_id', 'name', 'type', 'is_active')
                ->get();
        }

        if (in_array('customers', $entities)) {
            $data['customers'] = Customer::where('business_id', $businessId)
                // ->select('id', 'business_id', 'customer_code', 'name', 'email', 'phone',
                //          'address', 'type', 'credit_limit', 'client_uuid', 'version', 'synced_at')
                ->get();
        }

        if (in_array('branch_products', $entities)) {
            $data['branch_products'] = BranchProduct::where('branch_id', $branchId)
                ->get();
        }

        if (in_array('product_units', $entities)) {
            $productIds = Product::where('business_id', $businessId)->pluck('id');
            $data['product_units'] = ProductUnit::whereIn('product_id', $productIds)->get();
        }

        if (in_array('branch_product_unit_prices', $entities)) {
            $bpIds = BranchProduct::where('branch_id', $branchId)->pluck('id');
            $data['branch_product_unit_prices'] = BranchProductUnitPrice::whereIn('branch_product_id', $bpIds)->get();
        }

        if (in_array('branch_product_quantity_tiers', $entities)) {
            $bpIds = BranchProduct::where('branch_id', $branchId)->pluck('id');
            $data['branch_product_quantity_tiers'] = BranchProductQuantityTier::whereIn('branch_product_id', $bpIds)->get();
        }

        $totalRecords = collect($data)->sum(fn ($items) => $items->count());

        return response()->json([
            'session_id' => $sessionId,
            'server_timestamp' => now()->toIso8601String(),
            'data' => $data,
            'metadata' => [
                'total_records' => $totalRecords,
                'checksum' => md5(json_encode($data)),
                'estimated_size_kb' => round(strlen(json_encode($data)) / 1024, 2),
            ],
        ]);
    }

    /**
     * Pull changes from server since last sync
     */
    public function pull(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'last_sync_at' => 'required|date',
            'business_id' => 'nullable|exists:businesses,id',
            'entities' => 'nullable|array',
            'limit' => 'nullable|integer|min:1|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json([
                'success' => false,
                'error' => 'Business context is required',
            ], 400);
        }

        setPermissionsTeamId($businessId);
        if (! $user->hasPermissionTo('sync data')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $lastSyncAt = Carbon::parse($request->last_sync_at);
        $entities = $request->entities ?? ['products', 'customers', 'branch_products'];
        $limit = $request->limit ?? 500;
        $deviceId = $request->header('X-Device-Id');

        $sessionId = Str::uuid()->toString();
        $changes = [];

        foreach ($entities as $entity) {
            $changes[$entity] = $this->getEntityChanges($entity, $businessId, $lastSyncAt, $deviceId, $limit);
        }

        return response()->json([
            'session_id' => $sessionId,
            'server_timestamp' => now()->toIso8601String(),
            'changes' => $changes,
            'has_more' => false,
            'next_cursor' => null,
        ]);
    }

    /**
     * Push offline changes to server
     */
    public function push(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'session_id' => 'required|uuid',
            'batch_id' => 'nullable|string',
            'business_id' => 'nullable|exists:businesses,id',
            'changes' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json([
                'success' => false,
                'error' => 'Business context is required',
            ], 400);
        }

        setPermissionsTeamId($businessId);
        if (! $user->hasPermissionTo('sync data')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $userId = $user->id;
        $deviceUuid = $request->header('X-Device-Id');
        $sessionId = $request->session_id;

        // Get device registration record
        $device = DeviceRegistration::where('device_id', $deviceUuid)->first();
        if (! $device) {
            return response()->json([
                'success' => false,
                'error' => 'Device not registered',
            ], 404);
        }

        // Create sync session
        $session = SyncSession::create([
            'session_id' => $sessionId,
            'device_id' => $device->id,
            'business_id' => $businessId,
            'user_id' => $userId,
            'direction' => 'push',
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        $results = [];
        $hasConflicts = false;

        DB::beginTransaction();
        try {
            foreach ($request->changes as $entityType => $records) {
                $results[$entityType] = $this->processPushRecords($entityType, $records, $businessId, $userId, $deviceUuid);

                if ($results[$entityType]['conflicts'] > 0) {
                    $hasConflicts = true;
                }

                $session->recordPush($results[$entityType]['accepted']);
            }

            $session->completeSession($hasConflicts ? 'partial' : 'completed');
            DB::commit();

            $statusCode = $hasConflicts ? 207 : 200;
            $status = $hasConflicts ? 'partial' : 'completed';

            return response()->json([
                'session_id' => $sessionId,
                'status' => $status,
                'results' => $results,
                'server_timestamp' => now()->toIso8601String(),
            ], $statusCode);

        } catch (\Exception $e) {
            DB::rollBack();
            $session->recordError($e->getMessage());
            $session->completeSession('failed');

            return response()->json([
                'success' => false,
                'error' => 'Sync failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get sync status
     */
    public function status(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if ($businessId) {
            setPermissionsTeamId($businessId);
            if (! $user->hasPermissionTo('sync data')) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }
        }

        $deviceUuid = $request->header('X-Device-Id');
        $device = DeviceRegistration::where('device_id', $deviceUuid)->first();

        // Verify device belongs to user or user's business
        if ($device && $businessId && $device->business_id != $businessId) {
            return response()->json(['success' => false, 'error' => 'Device not found'], 404);
        }

        if (! $device) {
            return response()->json([
                'success' => false,
                'error' => 'Device not found',
            ], 404);
        }

        $lastSession = SyncSession::where('device_id', $device->id)
            ->latest('started_at')
            ->first();

        $pendingChanges = ChangeLog::where('business_id', $device->business_id)
            ->unsynced()
            ->count();

        return response()->json([
            'device' => [
                'device_id' => $device->device_id,
                'status' => $device->status,
                'last_sync_at' => $device->last_sync_at,
                'total_syncs' => $device->total_syncs,
            ],
            'pending_changes' => [
                'server_to_client' => $pendingChanges,
                'conflicts' => 0,
            ],
            'last_session' => $lastSession ? [
                'session_id' => $lastSession->session_id,
                'status' => $lastSession->status,
                'completed_at' => $lastSession->completed_at,
            ] : null,
            'server_timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Device heartbeat
     */
    public function heartbeat(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if ($businessId) {
            setPermissionsTeamId($businessId);
            if (! $user->hasPermissionTo('sync data')) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }
        }

        $deviceId = $request->header('X-Device-Id');
        $device = DeviceRegistration::where('device_id', $deviceId)->first();

        if ($device) {
            $device->updateLastSeen($request->ip());
        }

        $pendingChanges = $device ? ChangeLog::where('business_id', $device->business_id)
            ->unsynced()
            ->exists() : false;

        return response()->json([
            'status' => 'ok',
            'server_timestamp' => now()->toIso8601String(),
            'has_pending_changes' => $pendingChanges,
            'should_sync' => $pendingChanges,
            'messages' => [],
        ]);
    }

    /**
     * List devices last seen in the last 5 minutes (online devices).
     */
    public function onlineDevices(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json([
                'success' => false,
                'message' => 'Business context is required',
            ], 400);
        }

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);
        if (! $user->hasPermissionTo('sync data')) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $query = DeviceRegistration::with(['branch:id,name', 'user:id,name'])
            ->forBusiness($businessId)
            ->online(5)
            ->orderByDesc('last_seen_at');

        if ($request->filled('branch_id')) {
            $branchId = $request->branch_id;
            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json(['message' => 'You do not have access to this branch'], 403);
            }
            $query->where('branch_id', $branchId);
        } else {
            $accessibleBranches = $user->getBranchesInBusiness($businessId);
            if ($accessibleBranches->isNotEmpty()) {
                $query->where(function ($q) use ($accessibleBranches) {
                    $q->whereIn('branch_id', $accessibleBranches)
                        ->orWhereNull('branch_id');
                });
            }
        }

        $devices = $query->get();

        return response()->json(['data' => $devices]);
    }

    /**
     * Helper: Get entity changes since timestamp
     */
    private function getEntityChanges($entity, $businessId, $since, $excludeDevice, $limit)
    {
        $changes = [
            'created' => [],
            'updated' => [],
            'deleted' => [],
        ];

        switch ($entity) {
            case 'products':
                $created = Product::where('business_id', $businessId)
                    ->where('created_at', '>', $since)
                    ->limit($limit)
                    ->get();

                $updated = Product::where('business_id', $businessId)
                    ->where('updated_at', '>', $since)
                    ->where('created_at', '<=', $since)
                    ->limit($limit)
                    ->get();

                $changes['created'] = $created;
                $changes['updated'] = $updated;
                break;

            case 'customers':
                $created = Customer::where('business_id', $businessId)
                    ->where('created_at', '>', $since)
                    ->limit($limit)
                    ->get();

                $updated = Customer::where('business_id', $businessId)
                    ->where('updated_at', '>', $since)
                    ->where('created_at', '<=', $since)
                    ->limit($limit)
                    ->get();

                $changes['created'] = $created;
                $changes['updated'] = $updated;
                break;
        }

        return $changes;
    }

    /**
     * Helper: Process pushed records
     */
    private function processPushRecords($entityType, $records, $businessId, $userId, $deviceId)
    {
        $result = [
            'accepted' => 0,
            'rejected' => 0,
            'conflicts' => 0,
            'mappings' => [],
            'conflicts_details' => [],
        ];

        foreach ($records as $record) {
            try {
                switch ($entityType) {
                    case 'sales':
                        $mapping = $this->processSale($record, $businessId, $userId, $deviceId);
                        $result['mappings'][$record['client_uuid']] = $mapping;
                        $result['accepted']++;
                        break;

                    case 'customers':
                        $mapping = $this->processCustomer($record, $businessId, $userId, $deviceId);
                        $result['mappings'][$record['client_uuid']] = $mapping;
                        $result['accepted']++;
                        break;
                }
            } catch (\Exception $e) {
                $result['rejected']++;
                $result['conflicts_details'][] = [
                    'client_uuid' => $record['client_uuid'] ?? null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * Helper: Process sale record
     */
    private function processSale($data, $businessId, $userId, $deviceId)
    {
        // Check if already exists
        if (isset($data['client_uuid'])) {
            $existing = Sale::where('client_uuid', $data['client_uuid'])->first();
            if ($existing) {
                return [
                    'server_id' => $existing->id,
                    'sale_number' => $existing->sale_number,
                    'status' => 'already_synced',
                ];
            }
        }

        // Resolve shift_id: client sends local id; use only if that shift exists on server for this business/branch
        $shiftId = null;
        if (! empty($data['shift_id'])) {
            $shift = SalesShift::where('id', $data['shift_id'])
                ->where('business_id', $businessId)
                ->where('branch_id', $data['branch_id'])
                ->first();
            if ($shift) {
                $shiftId = $shift->id;
            }
        }

        $branchId = (int) $data['branch_id'];

        // Validate inventory for all items before creating sale (so we don't persist a sale we then reject)
        if (isset($data['items'])) {
            foreach ($data['items'] as $item) {
                $productId = (int) $item['product_id'];
                $qtyForBatch = (int) round((float) $item['quantity']);
                $branchProduct = BranchProduct::where('branch_id', $branchId)
                    ->where('product_id', $productId)
                    ->first();
                if (! $branchProduct) {
                    $product = Product::find($productId);

                    throw new \Exception(
                        'BranchProduct not found for product: '.($product ? $product->name : "ID {$productId}")
                    );
                }
                if ($branchProduct->stock_quantity < $qtyForBatch) {
                    $product = $branchProduct->product;

                    throw new \Exception(
                        "Insufficient stock for product: {$product->name}"
                    );
                }
                if (isset($item['batch_id']) && $item['batch_id'] !== null) {
                    $batchId = (int) $item['batch_id'];
                    $batch = ProductBatch::where('id', $batchId)
                        ->where('product_id', $productId)
                        ->where('branch_id', $branchId)
                        ->where('business_id', $businessId)
                        ->first();
                    if (! $batch || $batch->current_quantity < $qtyForBatch) {
                        $product = $branchProduct->product;

                        throw new \Exception(
                            "Invalid or insufficient batch quantity for product: {$product->name}"
                        );
                    }
                }
            }
        }

        // Create sale
        $sale = Sale::create([
            'business_id' => $businessId,
            'branch_id' => $data['branch_id'],
            'shift_id' => $shiftId,
            'customer_id' => $data['customer_id'] ?? null,
            'sale_number' => $data['sale_number'],
            'sale_type' => $data['sale_type'] ?? 'pos',
            'sale_date' => $data['sale_date'],
            'subtotal' => $data['subtotal'],
            'tax_amount' => $data['tax_amount'] ?? $data['tax'] ?? 0,
            'discount_amount' => $data['discount'] ?? 0,
            'total_amount' => $data['total_amount'] ?? $data['total'],
            'payment_status' => $data['payment_status'] ?? 'paid',
            'status' => $data['status'] ?? 'completed',
            'user_id' => $userId,
            'notes' => $data['notes'] ?? null,
            'client_uuid' => $data['client_uuid'] ?? null,
            'version' => $data['version'] ?? 1,
            'device_id' => $deviceId,
            'sync_status' => 'synced',
            'synced_at' => now(),
            'origin' => $data['origin'] ?? 'offline',
        ]);

        // Create items and deduct inventory
        $saleNumber = $data['sale_number'];

        if (isset($data['items'])) {
            foreach ($data['items'] as $item) {
                $productId = (int) $item['product_id'];
                $qty = (float) $item['quantity'];
                $qtyForBatch = (int) round($qty);

                $branchProduct = BranchProduct::where('branch_id', $branchId)
                    ->where('product_id', $productId)
                    ->first();

                if (! $branchProduct) {
                    $product = Product::find($productId);

                    throw new \Exception(
                        'BranchProduct not found for product: '.($product ? $product->name : "ID {$productId}")
                    );
                }

                if ($branchProduct->stock_quantity < $qtyForBatch) {
                    $product = $branchProduct->product;

                    throw new \Exception(
                        "Insufficient stock for product: {$product->name}"
                    );
                }

                // Resolve batch: prefer active quick sale batch (like online flow), else client batch_id
                $batch = null;
                $batchId = null;
                $quickSale = QuickSale::getActiveQuickSaleForProduct($productId, $branchId);
                if ($quickSale && $quickSale->batch_id) {
                    $batch = $quickSale->batch;
                    if ($batch && $batch->current_quantity >= $qtyForBatch) {
                        $batchId = $batch->id;
                    }
                }
                if ($batchId === null && isset($item['batch_id']) && $item['batch_id'] !== null) {
                    $batchId = (int) $item['batch_id'];
                    $batch = ProductBatch::where('id', $batchId)
                        ->where('product_id', $productId)
                        ->where('branch_id', $branchId)
                        ->where('business_id', $businessId)
                        ->first();
                    if (! $batch || $batch->current_quantity < $qtyForBatch) {
                        $product = $branchProduct->product;

                        throw new \Exception(
                            "Invalid or insufficient batch quantity for product: {$product->name}"
                        );
                    }
                }

                // Unit price: apply quick sale discount when active quick sale exists for resolved batch
                $unitPrice = (float) ($item['unit_price'] ?? $branchProduct->selling_price ?? 0);
                if ($batchId !== null) {
                    $quickSaleForBatch = QuickSale::getActiveQuickSale($productId, $branchId, null, $batchId);
                    if ($quickSaleForBatch) {
                        $unitPrice = $quickSaleForBatch->calculateFinalPrice(
                            $branchProduct->selling_price ?? $unitPrice
                        );
                    }
                }

                $subtotal = round($qty * $unitPrice, 2);
                $total = $subtotal;

                $payload = [
                    'sale_id' => $sale->id,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'] ?? $branchProduct->product?->name ?? 'Unknown Product',
                    'product_sku' => $item['product_sku'] ?? $branchProduct->product?->sku ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                    'discount_amount' => $item['discount'] ?? 0,
                    'tax_amount' => $item['tax'] ?? 0,
                    'subtotal' => $subtotal,
                    'total' => $total,
                ];
                if (isset($item['metadata'])) {
                    $payload['metadata'] = $item['metadata'];
                }
                if ($batchId !== null) {
                    $payload['batch_id'] = $batchId;
                }
                SaleItem::create($payload);

                $deductResult = $branchProduct->deductForSale($qtyForBatch);
                if (! $deductResult['stock_tracked']) {
                    $branchProduct->decrement('stock_quantity', $qtyForBatch);
                    $deductResult['quantity_before'] = $branchProduct->stock_quantity + $qtyForBatch;
                    $deductResult['quantity_after'] = $branchProduct->stock_quantity;
                }

                if ($batchId !== null && $batch) {
                    $batch->allocate($qtyForBatch);
                }

                $invPayload = [
                    'uuid' => (string) Str::uuid(),
                    'business_id' => $businessId,
                    'branch_id' => $branchId,
                    'product_id' => $productId,
                    'user_id' => $userId,
                    'type' => 'sale',
                    'quantity' => -$qtyForBatch,
                    'quantity_before' => $deductResult['quantity_before'],
                    'quantity_after' => $deductResult['quantity_after'],
                    'unit_cost' => $branchProduct->cost_price,
                    'total_cost' => $branchProduct->cost_price ? $branchProduct->cost_price * $qty : null,
                    'reference_number' => $saleNumber,
                    'notes' => "Sale: {$saleNumber}",
                ];
                if ($deductResult['stock_tracked']) {
                    $invPayload['shelf_quantity'] = -$deductResult['from_shelf'];
                    $invPayload['store_quantity'] = -$deductResult['from_store'];
                    $invPayload['shelf_quantity_before'] = $deductResult['shelf_quantity_before'];
                    $invPayload['store_quantity_before'] = $deductResult['store_quantity_before'];
                    $invPayload['shelf_quantity_after'] = $deductResult['shelf_quantity_after'];
                    $invPayload['store_quantity_after'] = $deductResult['store_quantity_after'];
                }
                if ($batchId !== null) {
                    $invPayload['batch_id'] = $batchId;
                }
                $invTransaction = InventoryTransaction::create($invPayload);

                if ($batchId === null && $qtyForBatch > 0) {
                    $this->batchService->allocateStockOut(
                        $productId,
                        $branchId,
                        $qtyForBatch,
                        $invTransaction,
                        ['reference_number' => $saleNumber, 'notes' => "Sale: {$saleNumber}"]
                    );
                }
            }
        }

        // Create payments
        if (isset($data['payments'])) {
            foreach ($data['payments'] as $payment) {
                Payment::create([
                    'sale_id' => $sale->id,
                    'payment_method_id' => $payment['payment_method_id'],
                    'amount' => $payment['amount'],
                    'payment_date' => $payment['payment_date'],
                    'reference_number' => $payment['reference_number'] ?? null,
                    'notes' => $payment['notes'] ?? null,
                    'status' => $payment['status'] ?? 'completed',

                ]);
            }
        }

        // Log change
        ChangeLog::logChange('sales', $sale->id, $sale->client_uuid, 'created', 1, [], $deviceId, $userId, $businessId);

        return [
            'server_id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'status' => 'synced',
        ];
    }

    /**
     * Helper: Process customer record
     */
    private function processCustomer($data, $businessId, $userId, $deviceId)
    {
        // Check if already exists
        if (isset($data['client_uuid'])) {
            $existing = Customer::where('client_uuid', $data['client_uuid'])->first();
            if ($existing) {
                return [
                    'server_id' => $existing->id,
                    'customer_code' => $existing->customer_code,
                    'status' => 'already_synced',
                ];
            }
        }

        $customer = Customer::create([
            'business_id' => $businessId,
            'customer_code' => $data['customer_code'],
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'type' => $data['type'] ?? 'walk-in',
            'credit_limit' => $data['credit_limit'] ?? 0,
            'client_uuid' => $data['client_uuid'] ?? null,
            'version' => $data['version'] ?? 1,
            'device_id' => $deviceId,
            'sync_status' => 'synced',
            'synced_at' => now(),
        ]);

        // Log change
        ChangeLog::logChange('customers', $customer->id, $customer->client_uuid, 'created', 1, [], $deviceId, $userId, $businessId);

        return [
            'server_id' => $customer->id,
            'customer_code' => $customer->customer_code,
            'status' => 'synced',
        ];
    }
}
