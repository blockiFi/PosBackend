<?php

namespace App\Http\Controllers;

use App\Http\Traits\HasBranchAccess;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchProductQuantityTier;
use App\Models\BranchProductUnitPrice;
use App\Models\Business;
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
use App\Services\GoodsReceivingService;
use App\Services\InventoryBatchService;
use App\Services\TieredPricingService;
use App\Support\BusinessQuantityPolicy;
use App\Support\BusinessSettings;
use App\Support\Quantity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SyncController extends Controller
{
    use HasBranchAccess;

    private const DEPOSIT_STOCK_MODES = ['reserve_on_create', 'deduct_on_complete'];

    private const DEFAULT_DEPOSIT_STOCK_MODE = 'reserve_on_create';

    public function __construct(
        protected InventoryBatchService $batchService,
        protected TieredPricingService $tieredPricingService
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

        // // Load requested entities
        // if (in_array('products', $entities)) {
        //     $data['products'] = Product::where('business_id', $businessId)
        //         // ->select('id', 'uuid', 'business_id', 'category_id', 'name', 'sku', 'barcode',
        //         //          'description', 'base_selling_price', 'base_cost_price', 'stock_tracking',
        //         //          'version', 'synced_at')
        //         ->get();
        // }

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

        if (in_array('products', $entities)) {
            $data['products'] = BranchProduct::where('branch_id', $branchId)
                ->with(['product', 'product.category'])
                ->get()
                ->map(
                    function ($branchProduct) use ($businessId) {
                        return $this->transformBranchProduct($branchProduct, (int) $businessId);
                    }
                );
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

        $business = Business::find($businessId);
        $businessSettings = $business
            ? BusinessSettings::syncPayload($business)
            : ['allow_decimal_quantities' => false, 'deposit_stock_mode' => null];

        return response()->json([
            'session_id' => $sessionId,
            'server_timestamp' => now()->toIso8601String(),
            'data' => $data,
            'business_settings' => $businessSettings,
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
            'branch_id' => 'required|exists:branches,id',
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

        $branch = Branch::where('id', $request->branch_id)
            ->where('business_id', $businessId)
            ->first();
        if (! $branch) {
            return response()->json(['success' => false, 'message' => 'Branch not found or does not belong to business'], 404);
        }
        if (! $this->userHasBranchAccess($user, $businessId, (int) $request->branch_id)) {
            return response()->json(['success' => false, 'message' => 'You do not have access to this branch'], 403);
        }

        $lastSyncAt = Carbon::parse($request->last_sync_at);
        $entities = $request->entities ?? ['products', 'customers', 'branch_products'];
        $limit = $request->limit ?? 500;
        $deviceId = $request->header('X-Device-Id');
        $branchId = (int) $request->branch_id;
        $sessionId = Str::uuid()->toString();
        $changes = [];

        foreach ($entities as $entity) {
            $changes[$entity] = $this->getEntityChanges($entity, $businessId, $lastSyncAt, $deviceId, $limit, $branchId);
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
                // Persist incoming sales push payloads into change_logs for audit/sync tracing
                if ($entityType === 'sales' && is_array($records)) {
                    foreach ($records as $rec) {
                        try {
                            $entityId = $rec['server_id'] ?? ($rec['id'] ?? null);
                            $entityUuid = $rec['client_uuid'] ?? null;
                            $action = 'updated';
                            if (! empty($rec['deleted']) || (isset($rec['action']) && $rec['action'] === 'deleted')) {
                                $action = 'deleted';
                            } elseif (empty($entityId) && empty($rec['deleted'])) {
                                $action = 'created';
                            }

                            $version = $rec['version'] ?? ($rec['v'] ?? 1);

                            ChangeLog::logChange(
                                'sales',
                                $entityId,
                                $entityUuid,
                                $action,
                                (int) $version,
                                ['push_payload' => $rec],
                                $deviceUuid,
                                $userId,
                                $businessId
                            );
                        } catch (\Exception $e) {
                            // non-fatal: log and continue processing other records
                            // use logger if available
                            if (method_exists($this, 'warn')) {
                                $this->warn('Failed to persist incoming sales change log: ' . $e->getMessage());
                            }
                        }
                    }
                }

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
    private function getEntityChanges($entity, $businessId, $since, $excludeDevice, $limit, $branchId)
    {
        $changes = [
            'created' => [],
            'updated' => [],
            'deleted' => [],
        ];

        switch ($entity) {
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

                // case 'products':
                //     $created = Product::where('business_id', $businessId)
                //         ->where('created_at', '>', $since)
                //         ->limit($limit)
                //         ->get();

                //     $updated = Product::where('business_id', $businessId)
                //         ->where('updated_at', '>', $since)
                //         ->where('created_at', '<=', $since)
                //         ->limit($limit)
                //         ->get();

                //     $changes['created'] = $created;
                //     $changes['updated'] = $updated;
                //     break;

            case 'products':
                $baseQuery = BranchProduct::query()
                    ->with(['product', 'product.category'])
                    ->whereHas('branch', function ($query) use ($businessId) {
                        $query->where('business_id', $businessId);
                    });

                if ($branchId !== null) {
                    $baseQuery->where('branch_id', $branchId);
                }

                $created = (clone $baseQuery)
                    ->where('created_at', '>', $since)
                    ->with(['product', 'product.category'])
                    ->limit($limit)
                    ->get()
                    ->map(fn (BranchProduct $branchProduct) => $this->transformBranchProduct($branchProduct, (int) $businessId));

                $updated = (clone $baseQuery)
                    ->where('updated_at', '>', $since)
                    ->where('created_at', '<=', $since)
                    ->with(['product', 'product.category'])
                    ->limit($limit)
                    ->get()
                    ->map(fn (BranchProduct $branchProduct) => $this->transformBranchProduct($branchProduct, (int) $businessId));

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

                    case 'grn':
                        $mapping = $this->processGrn($record, $businessId, $userId, $deviceId);
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

        $saleType = $data['sale_type'] ?? 'pos';
        $isDeposit = $saleType === 'deposit';
        if ($isDeposit && empty($data['customer_id'])) {
            throw new \Exception('A customer is required for deposit sales');
        }
        if (empty($data['items']) || ! is_array($data['items']) || count($data['items']) === 0) {
            throw new \Exception('At least one item is required for sales');
        }
        if(isset($data['payments']) && count($data['payments']) === 0) {   
            throw new \Exception('At least one payment is required for sales');
        }

        foreach ($data['items'] as $item) {
            try {
                BusinessQuantityPolicy::assertAllowed(
                    $businessId,
                    (float) ($item['quantity'] ?? 0),
                    'quantity'
                );
            } catch (\Illuminate\Validation\ValidationException $e) {
                throw new \Exception('Decimal quantities are not enabled for this business');
            }
        }

        $depositStockMode = $isDeposit ? $this->resolveDepositStockMode($businessId, $data['metadata'] ?? null) : null;
        $deferStockToCompletion = $isDeposit && $depositStockMode === 'deduct_on_complete';

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
        else{
            throw new \Exception('Shift ID is required for sales');
        }

        $branchId = (int) $data['branch_id'];

        // Validate inventory for all items before creating sale (so we don't persist a sale we then reject)
        if (! $deferStockToCompletion && isset($data['items'])) {
            foreach ($data['items'] as $item) {
                $productId = (int) $item['product_id'];
                $qty = BusinessQuantityPolicy::normalizeForBusiness($businessId, (float) $item['quantity']);
                $branchProduct = BranchProduct::where('branch_id', $branchId)
                    ->where('product_id', $productId)
                    ->first();
                if (! $branchProduct) {
                    $product = Product::find($productId);

                    throw new \Exception(
                        'BranchProduct not found for product: '.($product ? $product->name : "ID {$productId}")
                    );
                }
                if ((float) $branchProduct->stock_quantity < $qty) {
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
                    if (! $batch || (float) $batch->current_quantity < $qty) {
                        $product = $branchProduct->product;

                        throw new \Exception(
                            "Invalid or insufficient batch quantity for product: {$product->name}"
                        );
                    }
                }
            }
        }

        $saleMetadata = [];
        if ($isDeposit) {
            $saleMetadata['deposit_stock_mode'] = $depositStockMode;
        }

        $defaultStatus ='pending';

        // Create sale (line economics are inferred server-side; totals filled after items via calculateTotals())
        // Wrap creation, items, and payments in a DB transaction so we don't leave orphaned sales when payments fail
        DB::beginTransaction();
        try {
            $sale = Sale::create([
                'business_id' => $businessId,
                'branch_id' => $data['branch_id'],
                'shift_id' => $shiftId,
                'customer_id' => $data['customer_id'] ?? null,
                'sale_number' => $data['sale_number'],
                'reference_id' => $data['reference_id'] ?? null,
                'sale_type' => $saleType,
                'sale_date' => $data['sale_date'],
                'subtotal' => 0,
                'tax_amount' => 0,
                'discount_amount' => $data['discount'] ?? $data['discount_amount'] ?? 0,
                'total_amount' => 0,
                'payment_status' => 'unpaid',
                'status' => 'pending',
                'user_id' => $userId,
                'notes' => $data['notes'] ?? null,
                'client_uuid' => $data['client_uuid'] ?? null,
                'version' => $data['version'] ?? 1,
                'device_id' => $deviceId,
                'sync_status' => 'synced',
                'synced_at' => now(),

                'origin' => $data['origin'] ?? 'offline',
                'metadata' => $saleMetadata ?: null,
            ]);

            // Create items and deduct inventory
            $saleNumber = $data['sale_number'];

            if (isset($data['items'])) {
                foreach ($data['items'] as $item) {
                    $productId = (int) $item['product_id'];
                    $qty = BusinessQuantityPolicy::normalizeForBusiness($businessId, (float) $item['quantity']);

                    $branchProduct = BranchProduct::where('branch_id', $branchId)
                        ->where('product_id', $productId)
                        ->first();

                    if (! $branchProduct) {
                        $product = Product::find($productId);

                        throw new \Exception(
                            'BranchProduct not found for product: '.($product ? $product->name : "ID {$productId}")
                        );
                    }

                    if (! $deferStockToCompletion && (float) $branchProduct->stock_quantity < $qty) {
                        $product = $branchProduct->product;

                        throw new \Exception(
                            "Insufficient stock for product: {$product->name}"
                        );
                    }

                    // Resolve batch: prefer active quick sale batch (like online flow), else client batch_id
                    $batch = null;
                    $batchId = null;
                    if (! $deferStockToCompletion) {
                        $quickSale = QuickSale::getActiveQuickSaleForProduct($productId, $branchId);
                        if ($quickSale && $quickSale->batch_id) {
                            $batch = $quickSale->batch;
                            if ($batch && (float) $batch->current_quantity >= $qty) {
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
                            if (! $batch || (float) $batch->current_quantity < $qty) {
                                $product = $branchProduct->product;

                                throw new \Exception(
                                    "Invalid or insufficient batch quantity for product: {$product->name}"
                                );
                            }
                        }
                    }

                    $product = $branchProduct->product;
                    if (! $product || (int) $product->business_id !== (int) $businessId) {
                        throw new \Exception(
                            'Product not found or does not belong to this business: '.($product ? $product->name : "ID {$productId}")
                        );
                    }

                    // Authoritative line pricing from BranchProduct tiers (sync ignores client unit_price / names / SKU)
                    $tierResult = $this->tieredPricingService->getUnitPrice($branchProduct, $qty);
                    $unitPrice = $tierResult['unit_price'];
                    $metadata = [
                        'tier_type' => $tierResult['tier_type'],
                    ];
                    if ($tierResult['product_unit_id'] !== null) {
                        $metadata['product_unit_id'] = $tierResult['product_unit_id'];
                    }
                    if ($tierResult['quantity_tier_id'] !== null) {
                        $metadata['quantity_tier_id'] = $tierResult['quantity_tier_id'];
                    }

                    if ($batchId !== null) {
                        $quickSaleForBatch = QuickSale::getActiveQuickSale($product->id, $branchId, null, $batchId);
                        if ($quickSaleForBatch) {
                            $originalPrice = $branchProduct->selling_price ?? $unitPrice;
                            $unitPrice = $quickSaleForBatch->calculateFinalPrice($originalPrice);
                        }
                    }

                    if (isset($item['metadata']) && is_array($item['metadata'])) {
                        $metadata = array_merge($metadata, $item['metadata']);
                    }

                    $saleItem = new SaleItem([
                        'product_id' => $product->id,
                        'batch_id' => $batchId,
                        'product_name' => $product->name,
                        'product_sku' => $product->sku,
                        'description' => $item['description'] ?? null,
                        'quantity' => $qty,
                        'unit_price' => $unitPrice,
                        'discount_percentage' => isset($item['discount_percentage']) ? (float) $item['discount_percentage'] : 0,
                        'tax_rate' => isset($item['tax_rate']) ? (float) $item['tax_rate'] : 0,
                        'metadata' => $metadata ?: null,
                    ]);
                    $saleItem->calculateTotals();
                    $sale->items()->save($saleItem);

                    if (! $deferStockToCompletion) {
                        $deductResult = $branchProduct->deductForSale($qty);
                        if (! $deductResult['stock_tracked']) {
                            $branchProduct->decrement('stock_quantity', $qty);
                            $deductResult['quantity_before'] = (float) $branchProduct->stock_quantity + $qty;
                            $deductResult['quantity_after'] = (float) $branchProduct->stock_quantity;
                        }

                        if ($batchId !== null && $batch) {
                            $batch->allocate($qty);
                        }

                        $invPayload = [
                            'uuid' => (string) Str::uuid(),
                            'business_id' => $businessId,
                            'branch_id' => $branchId,
                            'product_id' => $productId,
                            'user_id' => $userId,
                            'type' => 'sale',
                            'quantity' => -$qty,
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

                        if ($batchId === null && Quantity::isPositive($qty)) {
                            $this->batchService->allocateStockOut(
                                $productId,
                                $branchId,
                                $qty,
                                $invTransaction,
                                ['reference_number' => $saleNumber, 'notes' => "Sale: {$saleNumber}"]
                            );
                        }
                    }
                }

                $sale->load('items');
                $sale->calculateTotals();
                $sale->save();
            }

            // Create payments. Offline / sync clients often omit payment_date and shift_id;
            // default to the sale's date and the resolved shift so deposits sync with their
            // payments intact instead of being silently dropped (which used to leave the sale
            // row committed without any of the customer's tendered cash).
            $persistedPaymentCount = 0;
            $skippedPayments = [];
            $mappedPayments = [];

            // resolve a default method for mapping when client method is unknown
            $defaultMethod = PaymentMethod::where('business_id', $businessId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->first();

            if (isset($data['payments']) && is_array($data['payments'])) {
                $defaultPaymentDate = $sale->sale_date?->copy() ?? now();
                foreach ($data['payments'] as $payment) {
                    $methodId = $payment['payment_method_id'] ?? null;
                    $amount = (float) ($payment['amount'] ?? 0);

                    // Basic validation
                    if (! $methodId || $amount <= 0) {
                        $skippedPayments[] = ['reason' => 'missing_method_or_amount', 'payload' => $payment];
                        continue;
                    }

                    // Ensure method belongs to this business; if not, attempt mapping to defaultMethod
                    $method = PaymentMethod::where('id', $methodId)
                        ->where('business_id', $businessId)
                        ->first();

                    if (! $method) {
                        if ($defaultMethod) {
                            $mappedPayments[] = ['original' => $payment, 'mapped_to' => $defaultMethod->id];
                            $methodId = $defaultMethod->id;
                        } else {
                            $skippedPayments[] = ['reason' => 'unknown_payment_method', 'payload' => $payment];
                            continue;
                        }
                    }

                    $paymentDate = $payment['payment_date'] ?? $defaultPaymentDate;

                    Payment::create([
                        'sale_id' => $sale->id,
                        'shift_id' =>  $shiftId,
                        'payment_method_id' => $methodId,
                        'amount' => $amount,
                        'payment_date' => $paymentDate,
                        'reference_number' => $payment['reference_number'] ?? null,
                        'notes' => $payment['notes'] ?? null,
                        'status' => 'completed',
                    ]);
                    $persistedPaymentCount++;
                }
            }

            // Align paid_amount / payment_status with persisted payments
            if ($persistedPaymentCount > 0) {
                $sale->refresh();
                $sale->updatePaymentStatus();
                if (! $isDeposit && $sale->isFullyPaid()) {
                    $sale->status = 'completed';
                    $sale->save();
                }

                $amountPaid = $sale->payments->sum('amount');
                $sale->paid_amount = $amountPaid;
                $sale->save();
            }

            // Log sale creation
            ChangeLog::logChange('sales', $sale->id, $sale->client_uuid, 'created', $sale->version ?? 1, [], $deviceId, $userId, $businessId);

            // If any payments were skipped or mapped, log those details against the sale for traceability
            if (! empty($skippedPayments) || ! empty($mappedPayments)) {
                $sale->version = (($sale->version ?? 0) + 1);
                $sale->save();
                ChangeLog::logChange('sales', $sale->id, $sale->client_uuid, 'updated', $sale->version, [
                    'skipped_payments' => $skippedPayments,
                    'mapped_payments' => $mappedPayments,
                ], $deviceId, $userId, $businessId);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            // Re-throw so caller can record conflict/rejection; sale won't be partially persisted
            throw $e;
        }

        // Log change
        ChangeLog::logChange('sales', $sale->id, $sale->client_uuid, 'created', 1, [], $deviceId, $userId, $businessId);

        return [
            'server_id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'status' => 'synced',
        ];
    }

    private function resolveDepositStockMode(int $businessId, mixed $saleMetadata): string
    {
        if (is_array($saleMetadata)) {
            $stamped = $saleMetadata['deposit_stock_mode'] ?? null;
            if (is_string($stamped) && in_array($stamped, self::DEPOSIT_STOCK_MODES, true)) {
                return $stamped;
            }
        }

        $business = Business::find($businessId);
        if (! $business) {
            return self::DEFAULT_DEPOSIT_STOCK_MODE;
        }

        $settings = is_array($business->settings) ? $business->settings : [];
        $mode = $settings['deposit_stock_mode'] ?? null;
        if (is_string($mode) && in_array($mode, self::DEPOSIT_STOCK_MODES, true)) {
            return $mode;
        }

        return self::DEFAULT_DEPOSIT_STOCK_MODE;
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

    /**
     * Helper: Process GRN record (Phase 4).
     *
     * Expected payload: { client_uuid, branch_id, supplier_id, supplier_invoice_number?, notes?, lines: [...] }
     */
    private function processGrn($data, $businessId, $userId, $deviceId)
    {
        $clientUuid = $data['client_uuid'] ?? null;
        if (! $clientUuid) {
            throw new \Exception('client_uuid is required for GRN sync');
        }

        /** @var GoodsReceivingService $svc */
        $svc = app(GoodsReceivingService::class);

        $grn = $svc->createDraft([
            'business_id' => (int) $businessId,
            'branch_id' => (int) ($data['branch_id'] ?? 0),
            'supplier_id' => (int) ($data['supplier_id'] ?? 0),
            'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
            'supplier_invoice_date' => $data['supplier_invoice_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'device_id' => $deviceId,
            'client_uuid' => $clientUuid,
            'received_by' => $userId,
        ]);

        if (! isset($data['lines']) || ! is_array($data['lines'])) {
            throw new \Exception('GRN lines are required');
        }

        if ($grn->status !== 'draft') {
            return [
                'server_id' => $grn->id,
                'grn_number' => $grn->grn_number,
                'status' => 'already_synced',
            ];
        }

        foreach ($data['lines'] as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $branchProductId = (int) ($line['branch_product_id'] ?? 0);
            try {
                BusinessQuantityPolicy::assertAllowed($grn->business_id, (float) ($line['quantity_received'] ?? 0), 'quantity_received');
                BusinessQuantityPolicy::assertAllowed($grn->business_id, (float) ($line['quantity_accepted'] ?? 0), 'quantity_accepted');
            } catch (\Illuminate\Validation\ValidationException $e) {
                throw new \Exception('Decimal quantities are not enabled for this business');
            }
            $qtyReceived = BusinessQuantityPolicy::normalizeForBusiness($grn->business_id, (float) ($line['quantity_received'] ?? 0));
            $qtyAccepted = BusinessQuantityPolicy::normalizeForBusiness($grn->business_id, (float) ($line['quantity_accepted'] ?? 0));

            $bp = BranchProduct::where('id', $branchProductId)
                ->where('branch_id', $grn->branch_id)
                ->where('product_id', $productId)
                ->first();
            if (! $bp) {
                $svc->reject($grn, $userId, 'product_unavailable');
                throw new \Exception('product_unavailable');
            }

            $batchNumber = $line['batch_number'] ?? null;
            $unitCost = $line['unit_cost'] ?? null;
            if ($batchNumber) {
                $existing = ProductBatch::where('branch_id', $grn->branch_id)
                    ->where('product_id', $productId)
                    ->where('batch_number', $batchNumber)
                    ->first();
                if ($existing && $unitCost !== null && $existing->unit_cost !== null && (float) $existing->unit_cost !== (float) $unitCost) {
                    $batchNumber = $batchNumber.'-2';
                    $line['meta_data'] = array_merge($line['meta_data'] ?? [], ['batch_conflict' => true]);
                }
            }

            $svc->addOrUpdateLine($grn, [
                'product_id' => $productId,
                'branch_product_id' => $branchProductId,
                'quantity_received' => $qtyReceived,
                'quantity_accepted' => $qtyAccepted,
                'quantity_rejected' => BusinessQuantityPolicy::normalizeForBusiness(
                    $grn->business_id,
                    (float) ($line['quantity_rejected'] ?? 0)
                ),
                'unit_cost' => $unitCost,
                'batch_number' => $batchNumber,
                'lot_number' => $line['lot_number'] ?? null,
                'manufacturing_date' => $line['manufacturing_date'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null,
                'storage_location' => $line['storage_location'] ?? 'store',
                'notes' => $line['notes'] ?? null,
                'meta_data' => $line['meta_data'] ?? null,
            ]);
        }

        $svc->submit($grn, $userId);

        return [
            'server_id' => $grn->id,
            'grn_number' => $grn->grn_number,
            'status' => 'submitted',
        ];
    }

    private function transformBranchProduct(BranchProduct $branchProduct, int $businessId): array
    {
        $activeQuickSale = QuickSale::getActiveQuickSale(
            $branchProduct->product_id,
            $branchProduct->branch_id
        );

        $quickSaleData = null;
        if ($activeQuickSale) {
            $quickSaleData = [
                'id' => $activeQuickSale->id,
                'discount_type' => $activeQuickSale->discount_type,
                'discount_value' => $activeQuickSale->discount_value,
                'batch_id' => $activeQuickSale->batch_id,
                'start_time' => $activeQuickSale->start_time,
                'end_time' => $activeQuickSale->end_time,
                'status' => $activeQuickSale->status,
            ];
        }

        $qty = fn (mixed $value): int|float => BusinessQuantityPolicy::serializeQuantity($businessId, $value);

        return [
            'id' => $branchProduct->id,
            'branch_id' => $branchProduct->branch_id,
            'product_id' => $branchProduct->product_id,
            'name' => $branchProduct->product->name,
            'sku' => $branchProduct->product->sku,
            'barcode' => $branchProduct->product->barcode,
            'image' => $branchProduct->product->image,
            'category' => $branchProduct->product->category ? [
                'id' => $branchProduct->product->category->id,
                'name' => $branchProduct->product->category->name,
            ] : null,

            'cost_price' => $branchProduct->cost_price,
            'selling_price' => $branchProduct->selling_price,
            'compare_price' => $branchProduct->compare_price,
            'discount_amount' => $branchProduct->discount_amount,
            'discount_type' => $branchProduct->discount_type,
            'tax_rate' => $branchProduct->tax_rate,
            'final_price' => $branchProduct->getFinalPrice(),
            'price_with_tax' => $branchProduct->getPriceWithTax(),
            'profit_margin' => $branchProduct->getProfitMargin(),
            'quick_sale' => $quickSaleData,

            'stock_quantity' => $qty($branchProduct->stock_quantity),
            'shelf_quantity' => $qty($branchProduct->shelf_quantity),
            'store_quantity' => $qty($branchProduct->store_quantity),
            'low_stock_threshold' => $branchProduct->low_stock_threshold,
            'allow_backorder' => $branchProduct->allow_backorder,
            'reorder_point' => $branchProduct->reorder_point,
            'reorder_quantity' => $qty($branchProduct->reorder_quantity),
            'is_in_stock' => $branchProduct->isInStock(),
            'is_low_stock' => $branchProduct->isLowStock(),
            'is_out_of_stock' => $branchProduct->isOutOfStock(),
            'needs_reorder' => $branchProduct->needsReorder(),
            'shelf_needs_restocking' => $branchProduct->shelfNeedsRestocking(),
            'bin_location' => $branchProduct->bin_location,
            'shelf_location' => $branchProduct->shelf_location,
            'is_available' => $branchProduct->is_available,
            'is_featured' => $branchProduct->is_featured,
            'display_order' => $branchProduct->display_order,
            'branch_meta_data' => $branchProduct->branch_meta_data,
            'created_at' => $branchProduct->created_at,
            'updated_at' => $branchProduct->updated_at,
        ];
    }
}
