<?php

namespace App\Http\Controllers;

use App\Http\Traits\HasBranchAccess;
use App\Models\BranchProduct;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\ServerSyncSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ServerSyncController extends Controller
{
    use HasBranchAccess;

    /**
     * Push local changes to cloud server (called by local/edge server)
     */
    public function pushToCloud(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json(['message' => 'Business context required'], 400);
        }

        setPermissionsTeamId($businessId);
        if (! $user->hasPermissionTo('manage server sync')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'entities' => 'array',
            'entities.*' => 'in:sales,customers,products,categories,branch_products',
        ]);

        $entities = $validated['entities'] ?? ['sales', 'customers'];
        $lastSync = Cache::get('last_cloud_push') ?? now()->subDay();

        // Get all changes since last sync from this server
        $changes = $this->collectLocalChanges($lastSync, $entities, $businessId);

        if (empty($changes)) {
            return response()->json([
                'status' => 'success',
                'message' => 'No changes to sync',
                'synced_at' => now(),
            ]);
        }

        $cloudApiUrl = config('sync.cloud_server_url');
        $sessionId = (string) Str::uuid();

        try {
            // Send to cloud server
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.config('sync.cloud_server_token'),
                'X-Server-Id' => config('sync.local_server_id'),
                'X-Business-Id' => $businessId,
            ])->timeout(30)->post("{$cloudApiUrl}/server-sync/receive", [
                'session_id' => $sessionId,
                'server_id' => config('sync.local_server_id'),
                'changes' => $changes,
                'timestamp' => now()->toISOString(),
            ]);

            if ($response->successful()) {
                $result = $response->json();

                // Record sync session
                ServerSyncSession::create([
                    'session_id' => $sessionId,
                    'server_id' => config('sync.local_server_id'),
                    'direction' => 'push',
                    'status' => 'success',
                    'records_sent' => collect($changes)->sum(fn ($e) => count($e)),
                    'records_accepted' => $result['accepted'] ?? 0,
                    'records_rejected' => $result['rejected'] ?? 0,
                ]);

                Cache::put('last_cloud_push', now());

                return response()->json([
                    'status' => 'success',
                    'session_id' => $sessionId,
                    'result' => $result,
                    'synced_at' => now(),
                ]);
            }

            throw new \Exception('Cloud server returned error: '.$response->status());
        } catch (\Exception $e) {
            Log::error('Push to cloud failed', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
            ]);

            ServerSyncSession::create([
                'session_id' => $sessionId,
                'server_id' => config('sync.local_server_id'),
                'direction' => 'push',
                'status' => 'failed',
                'records_sent' => collect($changes)->sum(fn ($e) => count($e)),
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pull changes from cloud server (called by local/edge server)
     */
    public function pullFromCloud(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json(['message' => 'Business context required'], 400);
        }

        setPermissionsTeamId($businessId);
        if (! $user->hasPermissionTo('manage server sync')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'entities' => 'array',
            'entities.*' => 'in:sales,customers,products,categories,branch_products,payment_methods',
        ]);

        $entities = $validated['entities'] ?? ['products', 'categories', 'customers', 'branch_products'];
        $lastSync = Cache::get('last_cloud_pull') ?? now()->subDay();

        $cloudApiUrl = config('sync.cloud_server_url');
        $sessionId = (string) Str::uuid();

        try {
            // Request changes from cloud
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.config('sync.cloud_server_token'),
                'X-Server-Id' => config('sync.local_server_id'),
                'X-Business-Id' => $businessId,
            ])->timeout(30)->post("{$cloudApiUrl}/server-sync/provide-changes", [
                'server_id' => config('sync.local_server_id'),
                'last_sync_at' => $lastSync->toISOString(),
                'entities' => $entities,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $appliedCount = $this->applyChanges($data['changes'] ?? []);

                // Record sync session
                ServerSyncSession::create([
                    'session_id' => $sessionId,
                    'server_id' => config('sync.local_server_id'),
                    'direction' => 'pull',
                    'status' => 'success',
                    'records_received' => $data['total_changes'] ?? 0,
                    'records_applied' => $appliedCount,
                ]);

                Cache::put('last_cloud_pull', now());

                return response()->json([
                    'status' => 'success',
                    'session_id' => $sessionId,
                    'changes_applied' => $appliedCount,
                    'synced_at' => now(),
                ]);
            }

            throw new \Exception('Cloud server returned error: '.$response->status());
        } catch (\Exception $e) {
            Log::error('Pull from cloud failed', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
            ]);

            ServerSyncSession::create([
                'session_id' => $sessionId,
                'server_id' => config('sync.local_server_id'),
                'direction' => 'pull',
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Receive changes from edge servers (called by cloud server)
     */
    public function receiveFromEdge(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json(['message' => 'Business context required'], 400);
        }

        setPermissionsTeamId($businessId);
        if (! $user->hasPermissionTo('manage server sync')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'session_id' => 'required|uuid',
            'server_id' => 'required|string',
            'changes' => 'required|array',
            'timestamp' => 'required|date',
        ]);

        $serverId = $request->header('X-Server-Id');
        $sessionId = $validated['session_id'];

        DB::beginTransaction();
        try {
            $accepted = 0;
            $rejected = 0;
            $conflicts = [];

            foreach ($validated['changes'] as $entityType => $records) {
                $result = $this->processEntityChanges($entityType, $records, $serverId);
                $accepted += $result['accepted'];
                $rejected += $result['rejected'];
                $conflicts = array_merge($conflicts, $result['conflicts']);
            }

            // Record sync session
            ServerSyncSession::create([
                'session_id' => $sessionId,
                'server_id' => $serverId,
                'direction' => 'receive',
                'status' => 'success',
                'records_received' => collect($validated['changes'])->sum(fn ($e) => count($e)),
                'records_accepted' => $accepted,
                'records_rejected' => $rejected,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'session_id' => $sessionId,
                'accepted' => $accepted,
                'rejected' => $rejected,
                'conflicts' => $conflicts,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Receive from edge failed', [
                'error' => $e->getMessage(),
                'server_id' => $serverId,
                'session_id' => $sessionId,
            ]);

            ServerSyncSession::create([
                'session_id' => $sessionId,
                'server_id' => $serverId,
                'direction' => 'receive',
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Provide changes to edge servers (called by cloud server)
     */
    public function provideChangesToEdge(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json(['message' => 'Business context required'], 400);
        }

        setPermissionsTeamId($businessId);
        if (! $user->hasPermissionTo('manage server sync')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'server_id' => 'required|string',
            'last_sync_at' => 'required|date',
            'entities' => 'array',
            'entities.*' => 'in:sales,customers,products,categories,branch_products,payment_methods',
        ]);

        $serverId = $validated['server_id'];
        $lastSyncAt = $validated['last_sync_at'];
        $entities = $validated['entities'] ?? ['products', 'categories', 'customers'];

        // Get changes since last sync, excluding changes from requesting server
        $changes = $this->collectChangesForEdge($lastSyncAt, $serverId, $entities, $businessId);

        $totalChanges = collect($changes)->sum(function ($entity) {
            return count($entity['created'] ?? []) +
                   count($entity['updated'] ?? []) +
                   count($entity['deleted'] ?? []);
        });

        return response()->json([
            'changes' => $changes,
            'total_changes' => $totalChanges,
            'server_timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Check server sync status
     */
    public function status(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if ($businessId) {
            setPermissionsTeamId($businessId);
            if (! $user->hasPermissionTo('manage server sync')) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        $serverId = config('sync.local_server_id');

        $recentSessions = ServerSyncSession::where('server_id', $serverId)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $lastPush = Cache::get('last_cloud_push');
        $lastPull = Cache::get('last_cloud_pull');

        // Check if cloud is reachable
        $cloudStatus = $this->checkCloudStatus();

        return response()->json([
            'server_id' => $serverId,
            'mode' => config('sync.mode'),
            'cloud_status' => $cloudStatus,
            'last_push' => $lastPush?->toISOString(),
            'last_pull' => $lastPull?->toISOString(),
            'recent_sessions' => $recentSessions,
            'pending_changes' => $this->countPendingChanges($businessId ?? null),
        ]);
    }

    /**
     * Health check endpoint
     */
    public function health(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if ($businessId) {
            setPermissionsTeamId($businessId);
            if (! $user->hasPermissionTo('manage server sync')) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        return response()->json([
            'status' => 'healthy',
            'server_id' => config('sync.local_server_id'),
            'mode' => config('sync.mode'),
            'timestamp' => now()->toISOString(),
        ]);
    }

    // ============ PRIVATE HELPER METHODS ============

    private function collectLocalChanges($since, array $entities, $businessId = null)
    {
        $changes = [];

        foreach ($entities as $entity) {
            $changes[$entity] = $this->getEntityChanges($entity, $since, $businessId);
        }

        return array_filter($changes);
    }

    private function getEntityChanges($entityType, $since, $businessId = null)
    {
        $addBusinessScope = function ($query) use ($businessId, $since) {
            if ($businessId) {
                $query->where('business_id', $businessId);
            }
            $query->where(function ($q) use ($since) {
                $q->where('created_at', '>', $since)
                    ->orWhere('updated_at', '>', $since);
            });

            return $query;
        };

        switch ($entityType) {
            case 'sales':
                return $addBusinessScope(Sale::with(['items', 'payments']))->get()->toArray();

            case 'customers':
                return $addBusinessScope(Customer::query())->get()->toArray();

            case 'products':
                return $addBusinessScope(Product::query())->get()->toArray();

            case 'categories':
                return $addBusinessScope(ProductCategory::query())->get()->toArray();

            case 'branch_products':
                $query = BranchProduct::query();
                if ($businessId) {
                    $query->whereHas('branch', fn ($q) => $q->where('business_id', $businessId));
                }
                $query->where(function ($q) use ($since) {
                    $q->where('created_at', '>', $since)
                        ->orWhere('updated_at', '>', $since);
                });

                return $query->get()->toArray();

            default:
                return [];
        }
    }

    private function collectChangesForEdge($since, $excludeServerId, array $entities, $businessId = null)
    {
        $changes = [];

        foreach ($entities as $entity) {
            $changes[$entity] = [
                'created' => $this->getCreatedRecords($entity, $since, $excludeServerId, $businessId),
                'updated' => $this->getUpdatedRecords($entity, $since, $excludeServerId, $businessId),
                'deleted' => $this->getDeletedRecords($entity, $since, $excludeServerId),
            ];
        }

        return $changes;
    }

    private function getCreatedRecords($entityType, $since, $excludeServerId, $businessId = null)
    {
        $query = $this->getModelQuery($entityType)
            ->where('created_at', '>', $since);

        if ($businessId) {
            $query->where('business_id', $businessId);
        }

        // Exclude records from requesting server
        if (in_array($entityType, ['sales', 'customers'])) {
            $query->where('origin_server_id', '!=', $excludeServerId);
        }

        return $query->get()->toArray();
    }

    private function getUpdatedRecords($entityType, $since, $excludeServerId, $businessId = null)
    {
        $query = $this->getModelQuery($entityType)
            ->where('updated_at', '>', $since)
            ->where('created_at', '<=', $since);

        if ($businessId) {
            $query->where('business_id', $businessId);
        }

        if (in_array($entityType, ['sales', 'customers'])) {
            $query->where('origin_server_id', '!=', $excludeServerId);
        }

        return $query->get()->toArray();
    }

    private function getDeletedRecords($entityType, $since, $excludeServerId)
    {
        // This requires soft deletes or a deleted_records tracking table
        return [];
    }

    private function applyChanges(array $changes)
    {
        $appliedCount = 0;

        DB::beginTransaction();
        try {
            foreach ($changes as $entityType => $entityChanges) {
                // Apply created records
                foreach ($entityChanges['created'] ?? [] as $record) {
                    if ($this->createOrUpdateRecord($entityType, $record)) {
                        $appliedCount++;
                    }
                }

                // Apply updated records
                foreach ($entityChanges['updated'] ?? [] as $record) {
                    if ($this->createOrUpdateRecord($entityType, $record)) {
                        $appliedCount++;
                    }
                }

                // Apply deletions
                foreach ($entityChanges['deleted'] ?? [] as $uuid) {
                    if ($this->deleteRecord($entityType, $uuid)) {
                        $appliedCount++;
                    }
                }
            }

            DB::commit();

            return $appliedCount;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Apply changes failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function processEntityChanges($entityType, array $records, $serverId)
    {
        $accepted = 0;
        $rejected = 0;
        $conflicts = [];

        foreach ($records as $record) {
            try {
                // Add origin server tracking
                $record['origin_server_id'] = $serverId;

                $result = $this->createOrUpdateRecord($entityType, $record);

                if ($result === true) {
                    $accepted++;
                } elseif ($result === 'conflict') {
                    $conflicts[] = $record;
                    $rejected++;
                } else {
                    $rejected++;
                }
            } catch (\Exception $e) {
                Log::error("Failed to process {$entityType}", [
                    'error' => $e->getMessage(),
                    'record' => $record,
                ]);
                $rejected++;
            }
        }

        return compact('accepted', 'rejected', 'conflicts');
    }

    private function createOrUpdateRecord($entityType, array $data)
    {
        $model = $this->getModelClass($entityType);

        if (! $model) {
            return false;
        }

        // Use UUID for identifying records
        $uniqueKey = isset($data['uuid']) ? 'uuid' : 'id';
        $uniqueValue = $data[$uniqueKey];

        $existing = $model::where($uniqueKey, $uniqueValue)->first();

        if ($existing) {
            // Check version for conflict detection
            if (isset($data['version']) && isset($existing->version)) {
                if ($data['version'] < $existing->version) {
                    return 'conflict';
                }
            }

            $existing->update($data);

            return true;
        }

        $model::create($data);

        return true;
    }

    private function deleteRecord($entityType, $uuid)
    {
        $model = $this->getModelClass($entityType);

        if (! $model) {
            return false;
        }

        return $model::where('uuid', $uuid)->delete();
    }

    private function getModelQuery($entityType)
    {
        switch ($entityType) {
            case 'sales':
                return Sale::with(['items', 'payments']);
            case 'customers':
                return Customer::query();
            case 'products':
                return Product::query();
            case 'categories':
                return ProductCategory::query();
            case 'branch_products':
                return BranchProduct::query();
            case 'payment_methods':
                return PaymentMethod::query();
            default:
                return null;
        }
    }

    private function getModelClass($entityType)
    {
        $models = [
            'sales' => Sale::class,
            'customers' => Customer::class,
            'products' => Product::class,
            'categories' => ProductCategory::class,
            'branch_products' => BranchProduct::class,
            'payment_methods' => PaymentMethod::class,
        ];

        return $models[$entityType] ?? null;
    }

    private function checkCloudStatus()
    {
        try {
            $cloudUrl = config('sync.cloud_server_url');
            $response = Http::timeout(3)->get("{$cloudUrl}/server-sync/health");

            return $response->successful() ? 'online' : 'unreachable';
        } catch (\Exception $e) {
            return 'unreachable';
        }
    }

    private function countPendingChanges($businessId = null)
    {
        $lastSync = Cache::get('last_cloud_push') ?? now()->subDay();

        $pending = 0;
        if ($businessId) {
            $pending += Sale::where('business_id', $businessId)->where('created_at', '>', $lastSync)->count();
            $pending += Customer::where('business_id', $businessId)->where('created_at', '>', $lastSync)->count();
        } else {
            $pending += Sale::where('created_at', '>', $lastSync)->count();
            $pending += Customer::where('created_at', '>', $lastSync)->count();
        }

        return $pending;
    }
}
