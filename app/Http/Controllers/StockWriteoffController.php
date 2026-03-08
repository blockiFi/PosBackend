<?php

namespace App\Http\Controllers;

use App\Http\Traits\HasBranchAccess;
use App\Models\BranchProduct;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockWriteoff;
use App\Services\InventoryBatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockWriteoffController extends Controller
{
    use HasBranchAccess;

    public function __construct(
        protected InventoryBatchService $batchService
    ) {}

    /**
     * List all stock write-offs
     */
    public function index(Request $request)
    {
        $request->validate([
            'current_business_id' => 'required|exists:businesses,id',
            'branch_id' => 'nullable|exists:branches,id',
            'product_id' => 'nullable|exists:products,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $user = auth()->user();
        $businessId = $request->current_business_id;

        // Verify user has access to the business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('write off stock')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = StockWriteoff::with(['product', 'branch', 'branchProduct', 'writtenOffBy', 'batch'])
            ->where('business_id', $businessId);

        // Filter by branch if provided
        if ($request->filled('branch_id')) {
            $branchId = $request->branch_id;

            // Verify user has access to this branch
            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json(['message' => 'You do not have access to this branch'], 403);
            }

            $query->where('branch_id', $branchId);
        }

        // Filter by product
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filter by date range
        if ($request->filled('start_date')) {
            $query->whereDate('written_off_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('written_off_at', '<=', $request->end_date);
        }

        $writeoffs = $query->latest('written_off_at')
            ->paginate($request->per_page ?? 15);

        return response()->json($writeoffs);
    }

    /**
     * Write off stock
     */
    public function store(Request $request)
    {
        $request->validate([
            'current_business_id' => 'required|exists:businesses,id',
            'batch_id' => 'nullable|exists:product_batches,id',
            'branch_id' => 'required_with:product_id|nullable|exists:branches,id',
            'product_id' => 'required_without_all:branch_product_id,batch_id|nullable|exists:products,id',
            'branch_product_id' => 'required_without_all:product_id,batch_id|nullable|exists:branch_products,id',

            'quantity' => 'required|integer|min:1',
            'source' => 'required|in:shelf,store',
            'reason' => 'required|string|max:1000',
        ], [
            'product_id.required_without' => 'Either product_id, branch_product_id, or batch_id is required.',
            'branch_product_id.required_without' => 'Either product_id, branch_product_id, or batch_id is required.',
        ]);

        $user = auth()->user();
        $businessId = $request->current_business_id;

        // Verify user has access to the business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Check permission
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('write off stock')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($request->filled('batch_id')) {
            return $this->storeWriteoffWithBatch($request, $user, $businessId);
        }

        $branchProduct = null;
        $product = null;
        $branchId = null;

        if ($request->filled('branch_product_id')) {
            $branchProduct = BranchProduct::with(['branch', 'product'])
                ->where('id', $request->branch_product_id)
                ->first();

            if (! $branchProduct || $branchProduct->branch->business_id != $businessId) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => [
                        'branch_product_id' => ['Branch product not found or does not belong to this business.'],
                    ],
                ], 422);
            }

            $branchId = $branchProduct->branch_id;
            $product = $branchProduct->product;
        } else {
            $branchId = $request->branch_id;
            $product = Product::where('id', $request->product_id)
                ->where('business_id', $businessId)
                ->first();

            if (! $product) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => [
                        'product_id' => ['Product not found or does not belong to this business.'],
                    ],
                ], 422);
            }

            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json(['message' => 'You do not have access to this branch'], 403);
            }

            $branchProduct = BranchProduct::where('branch_id', $branchId)
                ->where('product_id', $product->id)
                ->first();

            if (! $branchProduct) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => [
                        'product_id' => ['Product not available in this branch.'],
                    ],
                ], 422);
            }
        }

        if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $source = $request->source;
        $available = $source === 'shelf' ? $branchProduct->shelf_quantity : $branchProduct->store_quantity;
        $location = $source === 'shelf' ? 'shelf' : 'store';

        if ($available < $request->quantity) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'quantity' => [
                        "Insufficient stock on {$location}. Available: {$available}, Requested: {$request->quantity}",
                    ],
                ],
            ], 422);
        }

        // Create write-off and reduce quantity from the chosen source in a transaction
        $writeoff = DB::transaction(function () use ($request, $businessId, $branchId, $branchProduct, $product, $user, $source) {
            // Capture quantities before change
            $shelfQuantityBefore = $branchProduct->shelf_quantity;
            $storeQuantityBefore = $branchProduct->store_quantity;
            $totalQuantityBefore = $shelfQuantityBefore + $storeQuantityBefore;

            if ($source === 'shelf') {
                $branchProduct->updateShelfQuantity($request->quantity, 'subtract');
                $shelfDelta = -$request->quantity;
                $storeDelta = 0;
            } else {
                $branchProduct->updateStoreQuantity($request->quantity, 'subtract');
                $shelfDelta = 0;
                $storeDelta = -$request->quantity;
            }

            // Refresh to get updated quantities
            $branchProduct->refresh();
            $shelfQuantityAfter = $branchProduct->shelf_quantity;
            $storeQuantityAfter = $branchProduct->store_quantity;
            $totalQuantityAfter = $shelfQuantityAfter + $storeQuantityAfter;

            // Create write-off record
            $writeoff = StockWriteoff::create([
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'branch_product_id' => $branchProduct->id,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'quantity' => $request->quantity,
                'source' => $source,
                'reason' => $request->reason,
                'written_off_by' => $user->id,
            ]);

            $damageTransaction = InventoryTransaction::create([
                'uuid' => Str::uuid(),
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'user_id' => $user->id,
                'type' => 'damage',
                'quantity' => -$request->quantity,
                'shelf_quantity' => $shelfDelta,
                'store_quantity' => $storeDelta,
                'quantity_before' => $totalQuantityBefore,
                'shelf_quantity_before' => $shelfQuantityBefore,
                'store_quantity_before' => $storeQuantityBefore,
                'quantity_after' => $totalQuantityAfter,
                'shelf_quantity_after' => $shelfQuantityAfter,
                'store_quantity_after' => $storeQuantityAfter,
                'reference_number' => 'WO-'.str_pad($writeoff->id, 8, '0', STR_PAD_LEFT),
                'notes' => $request->reason,
            ]);

            $this->batchService->allocateStockOut(
                $product->id,
                $branchId,
                $request->quantity,
                $damageTransaction,
                [
                    'reference_number' => 'WO-'.str_pad($writeoff->id, 8, '0', STR_PAD_LEFT),
                    'notes' => $request->reason,
                ]
            );

            return $writeoff;
        });

        $writeoff->load(['product', 'branch', 'branchProduct', 'writtenOffBy']);

        return response()->json([
            'message' => 'Stock written off successfully',
            'data' => $writeoff,
        ], 201);
    }

    /**
     * Write off stock for a specific batch (quantity and source from request).
     */
    private function storeWriteoffWithBatch(Request $request, $user, int $businessId): \Illuminate\Http\JsonResponse
    {
        $batch = ProductBatch::with(['product', 'branch'])
            ->where('id', $request->batch_id)
            ->where('business_id', $businessId)
            ->first();

        if (! $batch) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['batch_id' => ['Batch not found or does not belong to this business.']],
            ], 422);
        }

        if (! $this->userHasBranchAccess($user, $businessId, $batch->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        if ($batch->current_quantity <= 0) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['batch_id' => ['Batch has no remaining quantity to write off.']],
            ], 422);
        }

        if ($request->quantity > $batch->current_quantity) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'quantity' => [
                        'Quantity cannot exceed batch remaining quantity. Batch has: '.$batch->current_quantity.', requested: '.$request->quantity.'.',
                    ],
                ],
            ], 422);
        }

        $branchProduct = BranchProduct::where('branch_id', $batch->branch_id)
            ->where('product_id', $batch->product_id)
            ->first();

        if (! $branchProduct) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['batch_id' => ['Branch product not found for this batch.']],
            ], 422);
        }

        $source = $request->source;
        $available = $source === 'shelf' ? $branchProduct->shelf_quantity : $branchProduct->store_quantity;
        $location = $source === 'shelf' ? 'shelf' : 'store';

        if ($available < $request->quantity) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'quantity' => [
                        "Insufficient stock on {$location}. Available: {$available}, Requested: {$request->quantity}",
                    ],
                ],
            ], 422);
        }

        $product = $batch->product;

        $writeoff = DB::transaction(function () use ($request, $businessId, $batch, $branchProduct, $product, $user, $source) {
            $shelfQuantityBefore = $branchProduct->shelf_quantity;
            $storeQuantityBefore = $branchProduct->store_quantity;
            $totalQuantityBefore = $shelfQuantityBefore + $storeQuantityBefore;

            if ($source === 'shelf') {
                $branchProduct->updateShelfQuantity($request->quantity, 'subtract');
                $shelfDelta = -$request->quantity;
                $storeDelta = 0;
            } else {
                $branchProduct->updateStoreQuantity($request->quantity, 'subtract');
                $shelfDelta = 0;
                $storeDelta = -$request->quantity;
            }

            $branchProduct->refresh();
            $shelfQuantityAfter = $branchProduct->shelf_quantity;
            $storeQuantityAfter = $branchProduct->store_quantity;
            $totalQuantityAfter = $shelfQuantityAfter + $storeQuantityAfter;

            $writeoff = StockWriteoff::create([
                'business_id' => $businessId,
                'branch_id' => $batch->branch_id,
                'branch_product_id' => $branchProduct->id,
                'product_id' => $product->id,
                'batch_id' => $batch->id,
                'sku' => $product->sku,
                'quantity' => $request->quantity,
                'source' => $source,
                'reason' => $request->reason,
                'written_off_by' => $user->id,
            ]);

            $batch->allocate($request->quantity);

            InventoryTransaction::create([
                'uuid' => Str::uuid(),
                'business_id' => $businessId,
                'branch_id' => $batch->branch_id,
                'product_id' => $product->id,
                'user_id' => $user->id,
                'batch_id' => $batch->id,
                'type' => 'damage',
                'quantity' => -$request->quantity,
                'shelf_quantity' => $shelfDelta,
                'store_quantity' => $storeDelta,
                'quantity_before' => $totalQuantityBefore,
                'shelf_quantity_before' => $shelfQuantityBefore,
                'store_quantity_before' => $storeQuantityBefore,
                'quantity_after' => $totalQuantityAfter,
                'shelf_quantity_after' => $shelfQuantityAfter,
                'store_quantity_after' => $storeQuantityAfter,
                'reference_number' => 'WO-'.str_pad($writeoff->id, 8, '0', STR_PAD_LEFT),
                'notes' => $request->reason,
            ]);

            return $writeoff;
        });

        $writeoff->load(['product', 'branch', 'branchProduct', 'writtenOffBy', 'batch']);

        return response()->json([
            'message' => 'Stock written off successfully',
            'data' => $writeoff,
        ], 201);
    }

    /**
     * Write off the remaining quantity of a batch by batch ID
     */
    public function writeOffBatch(Request $request)
    {
        $request->validate([
            'current_business_id' => 'required|exists:businesses,id',
            'batch_id' => 'required|exists:product_batches,id',
            'reason' => 'required|string|max:1000',
        ]);

        $user = auth()->user();
        $businessId = $request->current_business_id;

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('write off stock')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $batch = ProductBatch::with(['product', 'branch'])
            ->where('id', $request->batch_id)
            ->where('business_id', $businessId)
            ->first();

        if (! $batch) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['batch_id' => ['Batch not found or does not belong to this business.']],
            ], 422);
        }

        if (! $this->userHasBranchAccess($user, $businessId, $batch->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        if ($batch->current_quantity <= 0) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['batch_id' => ['Batch has no remaining quantity to write off.']],
            ], 422);
        }

        $branchProduct = BranchProduct::where('branch_id', $batch->branch_id)
            ->where('product_id', $batch->product_id)
            ->first();

        if (! $branchProduct) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['batch_id' => ['Branch product not found for this batch.']],
            ], 422);
        }

        $totalStock = $branchProduct->shelf_quantity + $branchProduct->store_quantity;
        if ($totalStock < $batch->current_quantity) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'batch_id' => [
                        'Insufficient branch stock to write off batch. Batch quantity: '.$batch->current_quantity.', available: '.$totalStock.'.',
                    ],
                ],
            ], 422);
        }

        $quantity = $batch->current_quantity;
        $product = $batch->product;

        $writeoff = DB::transaction(function () use ($request, $businessId, $batch, $branchProduct, $product, $user, $quantity) {
            $shelfQuantityBefore = $branchProduct->shelf_quantity;
            $storeQuantityBefore = $branchProduct->store_quantity;
            $totalQuantityBefore = $shelfQuantityBefore + $storeQuantityBefore;

            $writeoff = StockWriteoff::create([
                'business_id' => $businessId,
                'branch_id' => $batch->branch_id,
                'branch_product_id' => $branchProduct->id,
                'product_id' => $product->id,
                'batch_id' => $batch->id,
                'sku' => $product->sku,
                'quantity' => $quantity,
                'source' => 'batch',
                'reason' => $request->reason,
                'written_off_by' => $user->id,
            ]);

            $batch->allocate($quantity);

            $fromStore = min($quantity, $branchProduct->store_quantity);
            $fromShelf = $quantity - $fromStore;

            $branchProduct->updateStoreQuantity($fromStore, 'subtract');
            $branchProduct->refresh();
            $branchProduct->updateShelfQuantity($fromShelf, 'subtract');
            $branchProduct->refresh();

            $shelfQuantityAfter = $branchProduct->shelf_quantity;
            $storeQuantityAfter = $branchProduct->store_quantity;
            $totalQuantityAfter = $shelfQuantityAfter + $storeQuantityAfter;

            InventoryTransaction::create([
                'uuid' => Str::uuid(),
                'business_id' => $businessId,
                'branch_id' => $batch->branch_id,
                'product_id' => $product->id,
                'user_id' => $user->id,
                'batch_id' => $batch->id,
                'type' => 'damage',
                'quantity' => -$quantity,
                'shelf_quantity' => -$fromShelf,
                'store_quantity' => -$fromStore,
                'quantity_before' => $totalQuantityBefore,
                'shelf_quantity_before' => $shelfQuantityBefore,
                'store_quantity_before' => $storeQuantityBefore,
                'quantity_after' => $totalQuantityAfter,
                'shelf_quantity_after' => $shelfQuantityAfter,
                'store_quantity_after' => $storeQuantityAfter,
                'reference_number' => 'WO-'.str_pad($writeoff->id, 8, '0', STR_PAD_LEFT),
                'notes' => $request->reason,
            ]); 

            return $writeoff;
        });

        $writeoff->load(['product', 'branch', 'branchProduct', 'writtenOffBy', 'batch']);

        return response()->json([
            'message' => 'Batch written off successfully',
            'data' => $writeoff,
        ], 201);
    }

    /**
     * Show a specific write-off
     */
    public function show(Request $request, int $id)
    {
        $request->validate([
            'current_business_id' => 'required|exists:businesses,id',
        ]);

        $user = auth()->user();
        $businessId = $request->current_business_id;

        // Verify user has access to the business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('write off stock')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $writeoff = StockWriteoff::with(['product', 'branch', 'branchProduct', 'writtenOffBy', 'batch'])
            ->where('business_id', $businessId)
            ->findOrFail($id);

        // Verify branch access
        if (! $this->userHasBranchAccess($user, $businessId, $writeoff->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        return response()->json(['data' => $writeoff]);
    }
}
