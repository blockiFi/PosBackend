<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\ProductBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BatchController extends Controller
{
    use HasBranchAccess;

    /**
     * List all batches with filtering
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view batches')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Get permitted branches
        $permittedBranches = $user->getBranchesInBusiness($businessId);

        $withCount = [
            'quickSales as quick_sale_requested_count' => function ($q) {
                $q->whereIn('status', [\App\Models\QuickSale::STATUS_PENDING, \App\Models\QuickSale::STATUS_APPROVED, \App\Models\QuickSale::STATUS_ACTIVE]);
            },
            'productLevelQuickSales as product_level_quick_sale_count' => function ($q) {
                $q->whereIn('status', [\App\Models\QuickSale::STATUS_PENDING, \App\Models\QuickSale::STATUS_APPROVED, \App\Models\QuickSale::STATUS_ACTIVE]);
            },
        ];
        if ($permittedBranches->isEmpty()) {
            // User has business-wide access
            $query = ProductBatch::with(['product', 'branch', 'inventoryTransaction'])
                ->withCount($withCount)
                ->forBusiness($businessId);
        } else {
            // User has branch-specific access
            $query = ProductBatch::with(['product', 'branch', 'inventoryTransaction'])
                ->withCount($withCount)
                ->forBusiness($businessId)
                ->whereIn('branch_id', $permittedBranches);
        }

        // Filters
        if ($request->has('branch_id')) {
            $branchId = (int) $request->branch_id;
            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json(['message' => 'You do not have access to this branch'], 403);
            }
            $query->where('branch_id', $branchId);
        }

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('expired') && $request->expired === 'true') {
            $query->expired();
        }

        if ($request->has('near_expiry')) {
            $days = (int) $request->input('near_expiry', 30);
            $query->nearExpiry($days);
        }

        if ($request->has('batch_number')) {
            $query->where('batch_number', 'like', '%'.$request->batch_number.'%');
        }

        if ($request->has('lot_number')) {
            $query->where('lot_number', 'like', '%'.$request->lot_number.'%');
        }

        // Sort
        $sortField = $request->input('sort_by', 'expiry_date');
        $sortDirection = $request->input('sort_direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        $batches = $query->paginate($request->input('per_page', 15));

        // Ensure consistent shape with product/branch and quick_sale_requested (same as near-expiry)
        $batches->getCollection()->transform(fn (ProductBatch $batch) => array_merge(
            $batch->toArray(),
            ['quick_sale_requested' => $batch->quick_sale_requested]
        ));

        return response()->json($batches);
    }

    /**
     * Get batches for a specific product
     */
    public function forProduct(Request $request, int $productId)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json(['message' => 'Business context is required'], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view batches')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $permittedBranches = $user->getBranchesInBusiness($businessId);

        $query = ProductBatch::with(['branch'])
            ->withCount([
                'quickSales as quick_sale_requested_count' => function ($q) {
                    $q->whereIn('status', [\App\Models\QuickSale::STATUS_PENDING, \App\Models\QuickSale::STATUS_APPROVED, \App\Models\QuickSale::STATUS_ACTIVE]);
                },
                'productLevelQuickSales as product_level_quick_sale_count' => function ($q) {
                    $q->whereIn('status', [\App\Models\QuickSale::STATUS_PENDING, \App\Models\QuickSale::STATUS_APPROVED, \App\Models\QuickSale::STATUS_ACTIVE]);
                },
            ])
            ->forBusiness($businessId)
            ->where('product_id', $productId);

        if ($permittedBranches->isNotEmpty()) {
            $query->whereIn('branch_id', $permittedBranches);
        }

        $batches = $query->orderByFEFO()
            ->get()
            ->map(function ($batch) {
                return [
                    'id' => $batch->id,
                    'uuid' => $batch->uuid,
                    'batch_number' => $batch->batch_number,
                    'lot_number' => $batch->lot_number,
                    'branch' => [
                        'id' => $batch->branch->id,
                        'name' => $batch->branch->name,
                    ],
                    'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
                    'manufacturing_date' => $batch->manufacturing_date?->format('Y-m-d'),
                    'received_quantity' => $batch->received_quantity,
                    'current_quantity' => $batch->current_quantity,
                    'unit_cost' => $batch->unit_cost,
                    'supplier_name' => $batch->supplier_name,
                    'status' => $batch->status,
                    'is_expired' => $batch->isExpired(),
                    'is_near_expiry' => $batch->isNearExpiry(),
                    'days_until_expiry' => $batch->daysUntilExpiry(),
                    'quick_sale_requested' => $batch->quick_sale_requested,
                ];
            });

        return response()->json(['batches' => $batches]);
    }

    /**
     * Get near-expiry batches
     */
    public function nearExpiry(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json(['message' => 'Business context is required'], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view batches')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $days = (int) $request->input('days', 30);
        $permittedBranches = $user->getBranchesInBusiness($businessId);

        $withCount = [
            'quickSales as quick_sale_requested_count' => function ($q) {
                $q->whereIn('status', [\App\Models\QuickSale::STATUS_PENDING, \App\Models\QuickSale::STATUS_APPROVED, \App\Models\QuickSale::STATUS_ACTIVE]);
            },
            'productLevelQuickSales as product_level_quick_sale_count' => function ($q) {
                $q->whereIn('status', [\App\Models\QuickSale::STATUS_PENDING, \App\Models\QuickSale::STATUS_APPROVED, \App\Models\QuickSale::STATUS_ACTIVE]);
            },
        ];
        $query = ProductBatch::with(['product', 'branch'])
            ->withCount($withCount)
            ->forBusiness($businessId);

        if ($permittedBranches->isNotEmpty()) {
            $query->whereIn('branch_id', $permittedBranches);
        }

        if ($request->has('branch_id')) {
            $branchId = (int) $request->branch_id;
            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json(['message' => 'You do not have access to this branch'], 403);
            }
            $query->where('branch_id', $branchId);
        }

        $batches = $query->nearExpiry($days)
            ->orderBy('expiry_date', 'asc')
            ->get()
            ->map(function ($batch) {
                return [
                    'id' => $batch->id,
                    'uuid' => $batch->uuid,
                    'batch_number' => $batch->batch_number,
                    'lot_number' => $batch->lot_number,
                    'product' => $batch->product ? [
                        'id' => $batch->product->id,
                        'name' => $batch->product->name,
                        'sku' => $batch->product->sku,
                    ] : null,
                    'branch' => $batch->branch ? [
                        'id' => $batch->branch->id,
                        'name' => $batch->branch->name,
                    ] : null,
                    'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
                    'current_quantity' => $batch->current_quantity,
                    'unit_cost' => $batch->unit_cost,
                    'days_until_expiry' => $batch->daysUntilExpiry(),
                    'status' => $batch->status,
                    'quick_sale_requested' => $batch->quick_sale_requested,
                ];
            });

        return response()->json([
            'batches' => $batches,
            'count' => $batches->count(),
            'days_threshold' => $days,
        ]);
    }

    /**
     * Get expired batches
     */
    public function expired(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json(['message' => 'Business context is required'], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view batches')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $permittedBranches = $user->getBranchesInBusiness($businessId);

        $withCount = [
            'quickSales as quick_sale_requested_count' => function ($q) {
                $q->whereIn('status', [\App\Models\QuickSale::STATUS_PENDING, \App\Models\QuickSale::STATUS_APPROVED, \App\Models\QuickSale::STATUS_ACTIVE]);
            },
            'productLevelQuickSales as product_level_quick_sale_count' => function ($q) {
                $q->whereIn('status', [\App\Models\QuickSale::STATUS_PENDING, \App\Models\QuickSale::STATUS_APPROVED, \App\Models\QuickSale::STATUS_ACTIVE]);
            },
        ];
        $query = ProductBatch::with(['product', 'branch'])
            ->withCount($withCount)
            ->forBusiness($businessId);

        if ($permittedBranches->isNotEmpty()) {
            $query->whereIn('branch_id', $permittedBranches);
        }

        $batches = $query->expired()
            ->where('current_quantity', '>', 0)
            ->orderBy('expiry_date', 'desc')
            ->get();
        // ->map(function ($batch) {
        //     return [
        //         'id' => $batch->id,
        //         'uuid' => $batch->uuid,
        //         'batch_number' => $batch->batch_number,
        //         'lot_number' => $batch->lot_number,
        //         'product' => [
        //             'id' => $batch->product->id,
        //             'name' => $batch->product->name,
        //             'sku' => $batch->product->sku,
        //         ],
        //         'branch' => [
        //             'id' => $batch->branch->id,
        //             'name' => $batch->branch->name,
        //         ],
        //         'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
        //         'current_quantity' => $batch->current_quantity,
        //         'unit_cost' => $batch->unit_cost,
        //         'total_value' => $batch->current_quantity * $batch->unit_cost,
        //         'status' => $batch->status,
        //         'quick_sale_requested' => $batch->quick_sale_requested,
        //     ];
        // });

        $totalValue = $batches->sum('total_value');

        return response()->json([
            'batches' => $batches,
            'count' => $batches->count(),
            'total_value' => number_format($totalValue, 2),
        ]);
    }

    /**
     * Get batch details
     */
    public function show(Request $request, int $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json(['message' => 'Business context is required'], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view batches')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $permittedBranches = $user->getBranchesInBusiness($businessId);

        $query = ProductBatch::with(['product', 'branch', 'inventoryTransaction', 'transactions'])
            ->forBusiness($businessId);

        if ($permittedBranches->isNotEmpty()) {
            $query->whereIn('branch_id', $permittedBranches);
        }

        $batch = $query->findOrFail($id);

        return response()->json([
            'batch' => [
                'id' => $batch->id,
                'uuid' => $batch->uuid,
                'batch_number' => $batch->batch_number,
                'lot_number' => $batch->lot_number,
                'product' => [
                    'id' => $batch->product->id,
                    'name' => $batch->product->name,
                    'sku' => $batch->product->sku,
                ],
                'branch' => [
                    'id' => $batch->branch->id,
                    'name' => $batch->branch->name,
                ],
                'manufacturing_date' => $batch->manufacturing_date?->format('Y-m-d'),
                'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
                'received_quantity' => $batch->received_quantity,
                'current_quantity' => $batch->current_quantity,
                'allocated_quantity' => $batch->received_quantity - $batch->current_quantity,
                'unit_cost' => $batch->unit_cost,
                'total_value' => $batch->current_quantity * $batch->unit_cost,
                'supplier_name' => $batch->supplier_name,
                'supplier_reference' => $batch->supplier_reference,
                'status' => $batch->status,
                'is_expired' => $batch->isExpired(),
                'is_near_expiry' => $batch->isNearExpiry(),
                'days_until_expiry' => $batch->daysUntilExpiry(),
                'created_at' => $batch->created_at,
                'original_transaction' => $batch->inventoryTransaction ? [
                    'id' => $batch->inventoryTransaction->id,
                    'reference_number' => $batch->inventoryTransaction->reference_number,
                    'type' => $batch->inventoryTransaction->type,
                    'created_at' => $batch->inventoryTransaction->created_at,
                ] : null,
                'transaction_count' => $batch->transactions->count(),
                'quick_sale_requested' => $batch->quick_sale_requested,
            ],
        ]);
    }

    /**
     * Update batch (for corrections/recalls)
     */
    public function update(Request $request, int $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json(['message' => 'Business context is required'], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage batches')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'status' => 'sometimes|in:active,depleted,expired,recalled',
            'lot_number' => 'sometimes|string|max:255',
            'supplier_name' => 'sometimes|string|max:255',
            'supplier_reference' => 'sometimes|string|max:255',
            'notes' => 'sometimes|string',
        ]);

        $permittedBranches = $user->getBranchesInBusiness($businessId);

        $query = ProductBatch::forBusiness($businessId);

        if ($permittedBranches->isNotEmpty()) {
            $query->whereIn('branch_id', $permittedBranches);
        }

        $batch = $query->findOrFail($id);

        DB::beginTransaction();
        try {
            if ($request->has('status')) {
                $batch->status = $request->status;
            }

            if ($request->has('lot_number')) {
                $batch->lot_number = $request->lot_number;
            }

            if ($request->has('supplier_name')) {
                $batch->supplier_name = $request->supplier_name;
            }

            if ($request->has('supplier_reference')) {
                $batch->supplier_reference = $request->supplier_reference;
            }

            if ($request->has('notes')) {
                $metaData = $batch->meta_data ?? [];
                $metaData['update_notes'] = array_merge(
                    $metaData['update_notes'] ?? [],
                    [[
                        'note' => $request->notes,
                        'user_id' => $user->id,
                        'timestamp' => now()->toISOString(),
                    ]]
                );
                $batch->meta_data = $metaData;
            }

            $batch->save();

            DB::commit();

            return response()->json([
                'message' => 'Batch updated successfully',
                'batch' => $batch,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to update batch',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
