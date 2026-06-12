<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\BranchProduct;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Supplier;
use App\Services\InventoryBatchService;
use App\Support\BusinessQuantityPolicy;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InventoryController extends Controller
{
    use HasBranchAccess;

    public function __construct(
        protected InventoryBatchService $batchService
    ) {}

    /**
     * List inventory transactions
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view inventory')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = InventoryTransaction::where('business_id', $businessId)
            ->with(['product', 'branch', 'user', 'relatedBranch']);

        // Filter by branch (with access check)
        if ($request->has('branch_id')) {
            $branchId = $request->input('branch_id');

            // Verify user has access to this branch
            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json([
                    'message' => 'You do not have access to this branch',
                ], 403);
            }

            $query->where('branch_id', $branchId);
        } else {
            // If no branch specified, filter by accessible branches
            $accessibleBranches = $user->getBranchesInBusiness($businessId);
            if ($accessibleBranches->isNotEmpty()) {
                $query->whereIn('branch_id', $accessibleBranches);
            }
        }

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->input('start_date'));
        }

        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->input('end_date'));
        }

        // Filter by reference number
        if ($request->has('reference_number')) {
            $query->where('reference_number', 'like', '%'.$request->input('reference_number').'%');
        }

        $perPage = $request->input('per_page', 15);
        $transactions = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $data = $transactions->map(function ($transaction) {
            return $this->formatTransaction($transaction);
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    /**
     * Create a new inventory transaction
     */
    public function store(Request $request)
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

        // Get transaction type early for permission check
        $transactionType = $request->input('type');

        // Check permissions based on transaction type (owners bypass permission check)
        $hasPermission = $business->owner_id === $user->id;
        if (! $hasPermission) {
            if ($transactionType === 'adjustment') {
                // For adjustments, allow either 'manage inventory' or 'adjust inventory' permission
                $hasPermission = $user->hasPermissionTo('manage inventory') || $user->hasPermissionTo('adjust inventory');
            } else {
                // For other transaction types, require 'manage inventory' permission
                $hasPermission = $user->hasPermissionTo('manage inventory');
            }
        }

        if (! $hasPermission) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $stockQtyRules = BusinessQuantityPolicy::stockQuantityRules($business);

        $data = $request->all();
        $validator = Validator::make($data, [
            'branch_id' => ['required', 'integer', 'exists:branches,id,business_id,'.$businessId],
            'product_id' => ['required', 'integer', 'exists:products,id,business_id,'.$businessId],
            'type' => ['required', 'in:purchase,sale,adjustment,transfer_out,transfer_in,return,damage,initial'],
            'quantity' => array_merge($stockQtyRules, ['not_in:0']),
            'shelf_quantity' => ['nullable', 'numeric', 'min:0'],
            'store_quantity' => ['nullable', 'numeric', 'min:0'],
            'location' => ['nullable', 'in:shelf,store,both'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'related_branch_id' => ['nullable', 'integer', 'exists:branches,id,business_id,'.$businessId],
            'notes' => ['nullable', 'string'],
            'meta_data' => ['nullable', 'array'],
            // Batch tracking fields (for purchases)
            'batch_number' => ['required_if:type,purchase', 'string', 'max:255'],
            'lot_number' => ['nullable', 'string', 'max:255'],
            'manufacturing_date' => ['required_if:type,purchase', 'date'],
            'expiry_date' => ['required_if:type,purchase', 'date', 'after:manufacturing_date'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id,business_id,'.$businessId],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'supplier_reference' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['quantity'] = BusinessQuantityPolicy::normalizeForBusiness($business, (float) $data['quantity']);
        $minStockIn = BusinessQuantityPolicy::minStockQuantity($business);
        if (in_array($data['type'], ['purchase', 'initial', 'return', 'transfer_in'], true)
            && $data['quantity'] < $minStockIn) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => ['quantity' => ["Quantity must be at least {$minStockIn} for stock-in transactions."]],
            ], 422);
        }
        if (isset($data['shelf_quantity'])) {
            $data['shelf_quantity'] = BusinessQuantityPolicy::normalizeForBusiness($business, (float) $data['shelf_quantity']);
        }
        if (isset($data['store_quantity'])) {
            $data['store_quantity'] = BusinessQuantityPolicy::normalizeForBusiness($business, (float) $data['store_quantity']);
        }

        // Verify user has access to the branch
        if (! $this->userHasBranchAccess($user, $businessId, $data['branch_id'])) {
            return response()->json([
                'message' => 'You do not have access to this branch',
            ], 403);
        }

        // Verify related branch access for transfers
        if (! empty($data['related_branch_id'])) {
            if (! in_array($data['type'], ['transfer_out', 'transfer_in'])) {
                return response()->json([
                    'message' => 'Related branch is only allowed for transfer transactions',
                ], 422);
            }

            if (! $this->userHasBranchAccess($user, $businessId, $data['related_branch_id'])) {
                return response()->json([
                    'message' => 'You do not have access to the related branch',
                ], 403);
            }
        }

        try {
            DB::beginTransaction();

            // Get current stock level
            $branchProduct = BranchProduct::where('product_id', $data['product_id'])
                ->where('branch_id', $data['branch_id'])
                ->first();

            $quantityBefore = $branchProduct ? $branchProduct->stock_quantity : 0;
            $shelfQuantityBefore = $branchProduct ? $branchProduct->shelf_quantity : 0;
            $storeQuantityBefore = $branchProduct ? $branchProduct->store_quantity : 0;

            // Normalize quantity based on transaction type
            $normalizedQuantity = $this->normalizeQuantity($data['type'], $data['quantity']);

            // Determine where to add/remove stock
            $location = $data['location'] ?? 'shelf'; // Default to shelf
            $shelfChange = 0;
            $storeChange = 0;

            if (isset($data['shelf_quantity']) && isset($data['store_quantity'])) {
                // Explicit shelf and store quantities provided
                $shelfChange = $this->normalizeQuantity($data['type'], $data['shelf_quantity']);
                $storeChange = $this->normalizeQuantity($data['type'], $data['store_quantity']);
                $normalizedQuantity = $shelfChange + $storeChange;
            } elseif ($location === 'both' && isset($data['shelf_quantity'])) {
                // Split specified
                $shelfChange = $this->normalizeQuantity($data['type'], $data['shelf_quantity']);
                $storeChange = $normalizedQuantity - $shelfChange;
            } elseif ($location === 'shelf') {
                $shelfChange = $normalizedQuantity;
            } elseif ($location === 'store') {
                $storeChange = $normalizedQuantity;
            } else {
                // Default: put on shelf
                $shelfChange = $normalizedQuantity;
            }

            // Calculate new stock levels
            $quantityAfter = $quantityBefore + $normalizedQuantity;
            $shelfQuantityAfter = $shelfQuantityBefore + $shelfChange;
            $storeQuantityAfter = $storeQuantityBefore + $storeChange;

            // Prevent negative stock for stock-out transactions
            if ($quantityAfter < 0 && in_array($data['type'], ['sale', 'transfer_out', 'damage'])) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Insufficient stock. Current stock: '.$quantityBefore,
                ], 422);
            }

            // Prevent negative shelf stock
            if ($shelfQuantityAfter < 0) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Insufficient shelf stock. Current shelf stock: '.$shelfQuantityBefore,
                ], 422);
            }

            // Prevent negative store stock
            if ($storeQuantityAfter < 0) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Insufficient store stock. Current store stock: '.$storeQuantityBefore,
                ], 422);
            }

            // Create transaction
            $supplier = null;
            if (! empty($data['supplier_id'])) {
                $supplier = Supplier::query()
                    ->where('business_id', $businessId)
                    ->where('id', (int) $data['supplier_id'])
                    ->first();
            }

            $transaction = InventoryTransaction::create([
                'uuid' => Str::uuid(),
                'business_id' => $businessId,
                'branch_id' => $data['branch_id'],
                'product_id' => $data['product_id'],
                'user_id' => $user->id,
                'type' => $data['type'],
                'quantity' => $normalizedQuantity,
                'shelf_quantity' => $shelfChange,
                'store_quantity' => $storeChange,
                'quantity_before' => $quantityBefore,
                'shelf_quantity_before' => $shelfQuantityBefore,
                'store_quantity_before' => $storeQuantityBefore,
                'quantity_after' => $quantityAfter,
                'shelf_quantity_after' => $shelfQuantityAfter,
                'store_quantity_after' => $storeQuantityAfter,
                'unit_cost' => $data['unit_cost'] ?? null,
                'total_cost' => isset($data['unit_cost']) ? abs($normalizedQuantity) * $data['unit_cost'] : null,
                'related_branch_id' => $data['related_branch_id'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'meta_data' => $data['meta_data'] ?? null,
                'supplier_id' => $supplier?->id ?? null,
            ]);

            // Update branch product stock
            if ($branchProduct) {
                $branchProduct->update([
                    'stock_quantity' => $quantityAfter,
                    'shelf_quantity' => $shelfQuantityAfter,
                    'store_quantity' => $storeQuantityAfter,
                ]);
                $branchProduct->refresh();
            } else {
                $branchProduct = BranchProduct::create([
                    'product_id' => $data['product_id'],
                    'branch_id' => $data['branch_id'],
                    'stock_quantity' => $quantityAfter,
                    'shelf_quantity' => $shelfQuantityAfter,
                    'store_quantity' => $storeQuantityAfter,
                ]);
            }

            // Handle transfer - create corresponding transaction in related branch
            if (in_array($data['type'], ['transfer_out', 'transfer_in']) && ! empty($data['related_branch_id'])) {
                $this->createTransferPair($transaction, $data, $user, $businessId);
            }

            // Create batch for purchases with batch tracking
            if ($data['type'] === 'purchase' && abs($normalizedQuantity) > 0) {
                try {
                    $batch = ProductBatch::create([
                        'uuid' => Str::uuid(),
                        'business_id' => $businessId,
                        'branch_id' => $data['branch_id'],
                        'product_id' => $data['product_id'],
                        'batch_number' => $data['batch_number'] ?? 'BATCH-'.strtoupper(Str::random(8)),
                        'lot_number' => $data['lot_number'] ?? null,
                        'manufacturing_date' => $data['manufacturing_date'] ?? null,
                        'expiry_date' => $data['expiry_date'] ?? null,
                        'received_quantity' => abs($normalizedQuantity),
                        'current_quantity' => abs($normalizedQuantity),
                        'unit_cost' => $data['unit_cost'] ?? 0,
                        'supplier_id' => $supplier?->id ?? null,
                        'supplier_name' => $supplier?->name ?? ($data['supplier_name'] ?? null),
                        'supplier_reference' => $data['supplier_reference'] ?? null,
                        'inventory_transaction_id' => $transaction->id,
                        'status' => 'active',
                    ]);

                    // Link batch to transaction
                    $transaction->update(['batch_id' => $batch->id]);
                } catch (\Exception $batchError) {
                    // Log batch creation error but don't fail the transaction
                    \Log::warning("Failed to create batch for purchase transaction {$transaction->id}: ".$batchError->getMessage());
                }
            }

            // Allocate batches for stock-out transactions using FEFO
            $isStockOut = in_array($data['type'], ['sale', 'transfer_out', 'damage'])
                || ($data['type'] === 'adjustment' && $normalizedQuantity < 0);

            if ($isStockOut && abs($normalizedQuantity) > 0) {
                $this->batchService->allocateStockOut(
                    $data['product_id'],
                    $data['branch_id'],
                    abs($normalizedQuantity),
                    $transaction,
                    [
                        'reference_number' => $data['reference_number'] ?? null,
                        'notes' => $transaction->notes,
                    ]
                );
            }

            // For positive adjustments, add to batch (create or existing)
            if ($data['type'] === 'adjustment' && $normalizedQuantity > 0 && abs($normalizedQuantity) > 0) {
                $this->batchService->addStockIn(
                    $data['product_id'],
                    $data['branch_id'],
                    $businessId,
                    abs($normalizedQuantity),
                    $transaction,
                    null,
                    [
                        'batch_number' => 'ADJ-'.strtoupper(Str::random(8)),
                        'lot_number' => $data['lot_number'] ?? null,
                        'manufacturing_date' => $data['manufacturing_date'] ?? null,
                        'expiry_date' => $data['expiry_date'] ?? null,
                        'unit_cost' => $data['unit_cost'] ?? 0,
                        'supplier_reference' => $data['reference_number'] ?? null,
                    ]
                );
            }

            DB::commit();

            // Refresh transaction with relationships
            $transaction->refresh();
            $transaction->load(['product', 'branch', 'user', 'relatedBranch']);

            // Get updated branch product
            $updatedBranchProduct = BranchProduct::where('product_id', $data['product_id'])
                ->where('branch_id', $data['branch_id'])
                ->first();

            return response()->json([
                'message' => 'Inventory transaction created successfully',
                'data' => [
                    'transaction' => $this->formatTransaction($transaction),
                    'updated_stock' => [
                        'product_id' => $data['product_id'],
                        'branch_id' => $data['branch_id'],
                        'previous_stock' => $quantityBefore,
                        'previous_shelf' => $shelfQuantityBefore,
                        'previous_store' => $storeQuantityBefore,
                        'current_stock' => $updatedBranchProduct->stock_quantity,
                        'current_shelf' => $updatedBranchProduct->shelf_quantity,
                        'current_store' => $updatedBranchProduct->store_quantity,
                        'quantity_changed' => $normalizedQuantity,
                    ],
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to create inventory transaction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * View inventory transaction
     */
    public function show(Request $request, int $id)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view inventory')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $transaction = InventoryTransaction::where('id', $id)
            ->where('business_id', $businessId)
            ->with(['product', 'branch', 'user', 'relatedBranch', 'relatedTransaction'])
            ->first();

        if (! $transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        // Verify branch access
        if (! $this->userHasBranchAccess($user, $businessId, $transaction->branch_id)) {
            return response()->json([
                'message' => 'You do not have access to this branch',
            ], 403);
        }

        return response()->json([
            'data' => $this->formatTransaction($transaction, true),
        ]);
    }

    /**
     * Get current stock summary
     */
    public function stockSummary(Request $request)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view inventory')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $branchId = $request->input('branch_id');

        if ($branchId) {
            // Verify user has access to this branch
            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json([
                    'message' => 'You do not have access to this branch',
                ], 403);
            }

            $query = BranchProduct::where('branch_id', $branchId);
        } else {
            // Get accessible branches
            $accessibleBranches = $user->getBranchesInBusiness($businessId);
            if ($accessibleBranches->isNotEmpty()) {
                $query = BranchProduct::whereIn('branch_id', $accessibleBranches);
            } else {
                $query = BranchProduct::whereHas('branch', function ($q) use ($businessId) {
                    $q->where('business_id', $businessId);
                });
            }
        }

        $summary = $query->with(['product', 'branch'])
            ->get()
            ->map(function ($branchProduct) {
                return [
                    'product_id' => $branchProduct->product_id,
                    'product_name' => $branchProduct->product->name ?? null,
                    'product_sku' => $branchProduct->product->sku ?? null,
                    'branch_id' => $branchProduct->branch_id,
                    'branch_name' => $branchProduct->branch->name ?? null,
                    'stock_quantity' => $branchProduct->stock_quantity,
                    'low_stock_threshold' => $branchProduct->low_stock_threshold,
                    'is_low_stock' => $branchProduct->isLowStock(),
                    'is_out_of_stock' => $branchProduct->isOutOfStock(),
                ];
            });

        return response()->json([
            'data' => $summary,
        ]);
    }

    /**
     * Normalize quantity based on transaction type
     */
    private function normalizeQuantity(string $type, float $quantity): float
    {
        $quantity = \App\Support\Quantity::normalize($quantity);
        $stockInTypes = ['purchase', 'transfer_in', 'return', 'initial'];
        $stockOutTypes = ['sale', 'transfer_out', 'damage'];

        if (in_array($type, $stockInTypes)) {
            return abs($quantity);
        } elseif (in_array($type, $stockOutTypes)) {
            return -abs($quantity);
        } else {
            // adjustment can be positive or negative
            return $quantity;
        }
    }

    /**
     * Create transfer pair transaction
     */
    private function createTransferPair($originalTransaction, $data, $user, $businessId)
    {
        $oppositeType = $data['type'] === 'transfer_out' ? 'transfer_in' : 'transfer_out';

        // Get current stock in related branch
        $relatedBranchProduct = BranchProduct::where('product_id', $data['product_id'])
            ->where('branch_id', $data['related_branch_id'])
            ->first();

        $relatedQuantityBefore = $relatedBranchProduct ? $relatedBranchProduct->stock_quantity : 0;
        $relatedNormalizedQuantity = $this->normalizeQuantity($oppositeType, abs($data['quantity']));
        $relatedQuantityAfter = $relatedQuantityBefore + $relatedNormalizedQuantity;

        // Create corresponding transaction
        $relatedTransaction = InventoryTransaction::create([
            'uuid' => Str::uuid(),
            'business_id' => $businessId,
            'branch_id' => $data['related_branch_id'],
            'product_id' => $data['product_id'],
            'user_id' => $user->id,
            'type' => $oppositeType,
            'quantity' => $relatedNormalizedQuantity,
            'quantity_before' => $relatedQuantityBefore,
            'quantity_after' => $relatedQuantityAfter,
            'unit_cost' => $data['unit_cost'] ?? null,
            'total_cost' => isset($data['unit_cost']) ? abs($data['quantity']) * $data['unit_cost'] : null,
            'related_branch_id' => $data['branch_id'],
            'related_transaction_id' => $originalTransaction->id,
            'reference_number' => $data['reference_number'] ?? null,
            'notes' => 'Transfer from '.($data['type'] === 'transfer_out' ? 'branch' : 'to branch'),
            'meta_data' => $data['meta_data'] ?? null,
        ]);

        // Link transactions
        $originalTransaction->update(['related_transaction_id' => $relatedTransaction->id]);

        // Update related branch stock
        if ($relatedBranchProduct) {
            $relatedBranchProduct->update(['stock_quantity' => $relatedQuantityAfter]);
        } else {
            BranchProduct::create([
                'product_id' => $data['product_id'],
                'branch_id' => $data['related_branch_id'],
                'stock_quantity' => $relatedQuantityAfter,
            ]);
        }

        // Add batch for transfer_in (create or add to existing)
        $this->batchService->addStockIn(
            $data['product_id'],
            $data['related_branch_id'],
            $businessId,
            abs($data['quantity']),
            $relatedTransaction,
            null,
            []
        );
    }

    /**
     * Format transaction for response
     */
    private function formatTransaction(InventoryTransaction $transaction, bool $detailed = false): array
    {
        $data = [
            'id' => $transaction->id,
            'uuid' => $transaction->uuid,
            'business_id' => $transaction->business_id,
            'branch_id' => $transaction->branch_id,
            'branch_name' => $transaction->branch->name ?? null,
            'product_id' => $transaction->product_id,
            'product_name' => $transaction->product->name ?? null,
            'product_sku' => $transaction->product->sku ?? null,
            'user_id' => $transaction->user_id,
            'user_name' => $transaction->user->name ?? null,
            'type' => $transaction->type,
            'quantity' => $transaction->quantity,
            'quantity_before' => $transaction->quantity_before,
            'quantity_after' => $transaction->quantity_after,
            'unit_cost' => $transaction->unit_cost,
            'total_cost' => $transaction->total_cost,
            'reference_number' => $transaction->reference_number,
            'notes' => $transaction->notes,
            'created_at' => $transaction->created_at,
            'updated_at' => $transaction->updated_at,
        ];

        if ($detailed) {
            $data['related_branch_id'] = $transaction->related_branch_id;
            $data['related_branch_name'] = $transaction->relatedBranch->name ?? null;
            $data['related_transaction_id'] = $transaction->related_transaction_id;
            $data['meta_data'] = $transaction->meta_data;

            if ($transaction->relatedTransaction) {
                $data['related_transaction'] = [
                    'id' => $transaction->relatedTransaction->id,
                    'uuid' => $transaction->relatedTransaction->uuid,
                    'type' => $transaction->relatedTransaction->type,
                    'quantity' => $transaction->relatedTransaction->quantity,
                ];
            }
        }

        return $data;
    }
}
