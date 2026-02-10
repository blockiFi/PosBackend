<?php

namespace App\Http\Controllers;

use App\Models\DeviceRegistration;
use App\Models\SyncSession;
use App\Models\ChangeLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PaymentMethod;
use App\Models\Customer;
use App\Models\BranchProduct;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class SyncController extends Controller
{
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
            'capabilities' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $businessId = $request->business_id 
            ?? $request->header('X-Business-Id') 
            ?? auth()->user()->business_id 
            ?? null;
        
        if (!$businessId) {
            return response()->json([
                'success' => false,
                'error' => 'User has no associated business'
            ], 403);
        }

        $device = DeviceRegistration::create([
            'device_id' => $request->device_id,
            'business_id' => $businessId,
            'branch_id' => $request->branch_id,
            'user_id' => auth()->id(),
            'device_name' => $request->device_name,
            'device_type' => $request->device_type,
            'os' => $request->os,
            'app_version' => $request->app_version,
            'ip_address' => $request->ip(),
            'status' => 'active',
            'last_seen_at' => now(),
            'capabilities' => $request->capabilities ?? [],
            'metadata' => $request->metadata ?? []
        ]);

        return response()->json([
            'device' => $device,
            'sync_token' => $request->bearerToken()
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
            'entities.*' => 'in:products,categories,payment_methods,customers,branch_products',
            'include_history' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $businessId = $request->business_id 
            ?? $request->header('X-Business-Id') 
            ?? auth()->user()->business_id 
            ?? null;
        
        if (!$businessId) {
            return response()->json([
                'success' => false,
                'error' => 'User has no associated business'
            ], 403);
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
                // ->select('id', 'branch_id', 'product_id', 'version', 'synced_at')
                ->get();
        }

        $totalRecords = collect($data)->sum(fn($items) => $items->count());

        return response()->json([
            'session_id' => $sessionId,
            'server_timestamp' => now()->toIso8601String(),
            'data' => $data,
            'metadata' => [
                'total_records' => $totalRecords,
                'checksum' => md5(json_encode($data)),
                'estimated_size_kb' => round(strlen(json_encode($data)) / 1024, 2)
            ]
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
            'limit' => 'nullable|integer|min:1|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $businessId = $request->business_id 
            ?? $request->header('X-Business-Id') 
            ?? auth()->user()->business_id 
            ?? null;
        
        if (!$businessId) {
            return response()->json([
                'success' => false,
                'error' => 'User has no associated business'
            ], 403);
        }

        $lastSyncAt = $request->last_sync_at;
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
            'next_cursor' => null
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
            'changes' => 'required|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $businessId = $request->business_id 
            ?? $request->header('X-Business-Id') 
            ?? auth()->user()->business_id 
            ?? null;
        
        if (!$businessId) {
            return response()->json([
                'success' => false,
                'error' => 'User has no associated business'
            ], 403);
        }

        $userId = auth()->id();
        $deviceUuid = $request->header('X-Device-Id');
        $sessionId = $request->session_id;

        // Get device registration record
        $device = DeviceRegistration::where('device_id', $deviceUuid)->first();
        if (!$device) {
            return response()->json([
                'success' => false,
                'error' => 'Device not registered'
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
            'started_at' => now()
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
                'server_timestamp' => now()->toIso8601String()
            ], $statusCode);

        } catch (\Exception $e) {
            DB::rollBack();
            $session->recordError($e->getMessage());
            $session->completeSession('failed');

            return response()->json([
                'success' => false,
                'error' => 'Sync failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get sync status
     */
    public function status(Request $request)
    {
        $deviceUuid = $request->header('X-Device-Id');
        $device = DeviceRegistration::where('device_id', $deviceUuid)->first();

        if (!$device) {
            return response()->json([
                'success' => false,
                'error' => 'Device not found'
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
                'total_syncs' => $device->total_syncs
            ],
            'pending_changes' => [
                'server_to_client' => $pendingChanges,
                'conflicts' => 0
            ],
            'last_session' => $lastSession ? [
                'session_id' => $lastSession->session_id,
                'status' => $lastSession->status,
                'completed_at' => $lastSession->completed_at
            ] : null,
            'server_timestamp' => now()->toIso8601String()
        ]);
    }

    /**
     * Device heartbeat
     */
    public function heartbeat(Request $request)
    {
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
            'messages' => []
        ]);
    }

    /**
     * Helper: Get entity changes since timestamp
     */
    private function getEntityChanges($entity, $businessId, $since, $excludeDevice, $limit)
    {
        $changes = [
            'created' => [],
            'updated' => [],
            'deleted' => []
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
            'conflicts_details' => []
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
                    'error' => $e->getMessage()
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
                    'status' => 'already_synced'
                ];
            }
        }

        // Create sale
        $sale = Sale::create([
            'business_id' => $businessId,
            'branch_id' => $data['branch_id'],
            'shift_id' => $data['shift_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'sale_number' => $data['sale_number'],
            'sale_type' => $data['sale_type'] ?? 'pos',
            'sale_date' => $data['sale_date'],
            'subtotal' => $data['subtotal'],
            'tax_amount' => $data['tax'] ?? 0,
            'discount_amount' => $data['discount'] ?? 0,
            'total_amount' => $data['total'],
            'payment_status' => $data['payment_status'] ?? 'paid',
            'status' => $data['status'] ?? 'completed',
            'user_id' => $userId,
            'notes' => $data['notes'] ?? null,
        ]);

        // Create items
        if (isset($data['items'])) {
            foreach ($data['items'] as $item) {
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'] ?? 'Unknown Product',
                    'product_sku' => $item['product_sku'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_amount' => $item['discount'] ?? 0,
                    'tax_amount' => $item['tax'] ?? 0,
                    'subtotal' => $item['subtotal'],
                    'total' => $item['total'] ?? $item['subtotal'],
                ]);
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
            'status' => 'synced'
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
                    'status' => 'already_synced'
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
            'client_uuid' => $data['client_uuid'],
            'version' => $data['version'] ?? 1,
            'device_id' => $deviceId,
            'origin' => 'offline',
            'sync_status' => 'synced',
            'synced_at' => now()
        ]);

        // Log change
        ChangeLog::logChange('customers', $customer->id, $customer->client_uuid, 'created', 1, [], $deviceId, $userId, $businessId);

        return [
            'server_id' => $customer->id,
            'customer_code' => $customer->customer_code,
            'status' => 'synced'
        ];
    }
}
