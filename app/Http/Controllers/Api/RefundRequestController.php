<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\BranchProduct;
use App\Models\InventoryTransaction;
use App\Models\RefundRequest;
use App\Models\RefundRequestItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\InventoryBatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Str;

class RefundRequestController extends Controller
{
    use HasBranchAccess;

    public function __construct(
        protected InventoryBatchService $batchService
    ) {}

    /**
     * List refund requests with filtering
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Check permissions
        setPermissionsTeamId($businessId);
        $canRequest = $user->hasPermissionTo('request refund');
        $canApprove = $business->owner_id === $user->id || $user->hasPermissionTo('approve refund');

        if (! $canRequest && ! $canApprove) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = RefundRequest::with([
            'sale',
            'sale.customer',
            'sale.branch',
            'requestedBy',
            'reviewedBy',
            'items.saleItem.product',
        ])->forBusiness($businessId);

        // Filter by accessible branches
        $accessibleBranches = $user->getBranchesInBusiness($businessId);
        if ($accessibleBranches->isNotEmpty()) {
            $query->whereIn('branch_id', $accessibleBranches->pluck('id'));
        }

        // If user can only request (not approve), show only their requests
        if ($canRequest && ! $canApprove) {
            $query->where('requested_by', $user->id);
        }

        // Apply filters
        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }

        if ($request->filled('branch_id')) {
            $branchId = $request->branch_id;
            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json(['message' => 'Unauthorized access to this branch'], 403);
            }
            $query->where('branch_id', $branchId);
        }

        if ($request->filled('sale_id')) {
            $query->where('sale_id', $request->sale_id);
        }

        $refundRequests = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json($refundRequests);
    }

    /**
     * Create a new refund request
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('request refund')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'sale_id' => 'required|exists:sales,id',
            'reason' => 'required|string|min:10|max:1000',
            'refund_scope' => 'sometimes|string|in:whole_sale,items',
            'items' => 'required_if:refund_scope,items|array',
            'items.*.sale_item_id' => 'required_with:items|integer|exists:sale_items,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.01',
        ]);

        $refundScope = $validated['refund_scope'] ?? RefundRequest::SCOPE_WHOLE_SALE;

        // Get the sale
        $sale = Sale::with(['items', 'branch'])
            ->forBusiness($businessId)
            ->findOrFail($validated['sale_id']);

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $sale->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        // Validate sale is refundable
        if (! $sale->isRefundable()) {
            return response()->json([
                'message' => 'Sale is not eligible for refund',
                'reason' => (float) $sale->refunded_amount >= (float) $sale->total_amount
                    ? 'Sale has already been fully refunded'
                    : ($sale->trashed() ? 'Sale has been deleted' : 'Sale status does not allow refund'),
            ], 400);
        }

        // Check for duplicate pending requests
        if ($sale->hasPendingRefundRequest()) {
            return response()->json([
                'message' => 'A pending refund request already exists for this sale',
            ], 400);
        }

        $amount = (float) $sale->total_amount;
        $refundItems = [];

        if ($refundScope === RefundRequest::SCOPE_ITEMS) {
            $itemsInput = $validated['items'] ?? [];
            if (empty($itemsInput)) {
                return response()->json([
                    'message' => 'When refund_scope is items, at least one item with sale_item_id and quantity is required',
                ], 422);
            }

            $saleItemIds = $sale->items->pluck('id')->all();
            $seenSaleItemIds = [];
            $amount = 0;

            foreach ($itemsInput as $row) {
                $saleItemId = (int) $row['sale_item_id'];
                $qty = (float) $row['quantity'];

                if (! in_array($saleItemId, $saleItemIds, true)) {
                    return response()->json([
                        'message' => "Sale item {$saleItemId} does not belong to this sale",
                    ], 422);
                }
                if (isset($seenSaleItemIds[$saleItemId])) {
                    return response()->json([
                        'message' => 'Duplicate sale_item_id in items',
                    ], 422);
                }
                $seenSaleItemIds[$saleItemId] = true;

                $saleItem = $sale->items->firstWhere('id', $saleItemId);
                $alreadyRefunded = $this->getRefundedQuantityForSaleItem($saleItemId);
                $remaining = (float) $saleItem->quantity - $alreadyRefunded;

                if ($qty > $remaining) {
                    return response()->json([
                        'message' => "Quantity for sale_item_id {$saleItemId} exceeds remaining refundable quantity ({$remaining})",
                    ], 422);
                }

                $refundItems[] = ['sale_item_id' => $saleItemId, 'quantity' => $qty];
                $amount += (float) $saleItem->total * ($qty / (float) $saleItem->quantity);
            }
        }

        DB::beginTransaction();
        try {
            $refundRequest = RefundRequest::create([
                'sale_id' => $sale->id,
                'business_id' => $businessId,
                'branch_id' => $sale->branch_id,
                'refund_scope' => $refundScope,
                'requested_by' => $user->id,
                'amount' => round($amount, 2),
                'reason' => $validated['reason'],
                'status' => RefundRequest::STATUS_PENDING,
            ]);

            foreach ($refundItems as $row) {
                RefundRequestItem::create([
                    'refund_request_id' => $refundRequest->id,
                    'sale_item_id' => $row['sale_item_id'],
                    'quantity' => $row['quantity'],
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Refund request submitted successfully',
                'refund_request' => $refundRequest->load(['sale', 'requestedBy', 'items.saleItem.product']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to create refund request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore inventory for a refunded quantity of a sale item.
     *
     * @param  \App\Models\SaleItem  $saleItem  SaleItem model (has product_id, batch_id, etc.)
     * @param  float  $quantity  Quantity to restore
     */
    private function restoreInventoryForRefund(Sale $sale, SaleItem $saleItem, float $quantity, int $businessId, int $userId): void
    {
        $branchProduct = BranchProduct::where('branch_id', $sale->branch_id)
            ->where('product_id', $saleItem->product_id)
            ->first();

        if (! $branchProduct) {
            return;
        }

        $branchProduct->increment('stock_quantity', $quantity);

        $adjTransaction = InventoryTransaction::create([
            'uuid' => Str::uuid(),
            'business_id' => $businessId,
            'branch_id' => $sale->branch_id,
            'product_id' => $saleItem->product_id,
            'user_id' => $userId,
            'type' => 'adjustment',
            'quantity' => $quantity,
            'quantity_before' => $branchProduct->stock_quantity - $quantity,
            'quantity_after' => $branchProduct->stock_quantity,
            'unit_cost' => $branchProduct->cost_price,
            'total_cost' => $branchProduct->cost_price * $quantity,
            'reference_number' => $sale->sale_number,
            'notes' => "Refund approved for sale: {$sale->sale_number}",
        ]);

        $this->batchService->addStockIn(
            $saleItem->product_id,
            $sale->branch_id,
            $businessId,
            $quantity,
            $adjTransaction,
            $saleItem->batch_id ?? null,
            []
        );
    }

    /**
     * Sum of refunded quantity for a sale item from approved/processed refund requests.
     */
    private function getRefundedQuantityForSaleItem(int $saleItemId): float
    {
        return (float) RefundRequestItem::query()
            ->where('sale_item_id', $saleItemId)
            ->whereHas('refundRequest', function ($q) {
                $q->whereIn('status', [RefundRequest::STATUS_APPROVED, RefundRequest::STATUS_PROCESSED]);
            })
            ->sum('quantity');
    }

    /**
     * View a specific refund request
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);
        $canRequest = $user->hasPermissionTo('request refund');
        $canApprove = $business->owner_id === $user->id || $user->hasPermissionTo('approve refund');

        if (! $canRequest && ! $canApprove) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $refundRequest = RefundRequest::with([
            'sale.items.product',
            'sale.payments.paymentMethod',
            'sale.customer',
            'sale.branch',
            'requestedBy',
            'reviewedBy',
            'items.saleItem.product',
        ])->forBusiness($businessId)->findOrFail($id);

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $refundRequest->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        // If user can only request (not approve), they can only view their own requests
        if ($canRequest && ! $canApprove && $refundRequest->requested_by !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($refundRequest);
    }

    /**
     * Approve and process a refund request
     */
    public function approve(Request $request, $id)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('approve refund')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $refundRequest = RefundRequest::with(['sale.items', 'items.saleItem'])
            ->forBusiness($businessId)
            ->findOrFail($id);

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $refundRequest->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        // Validate request is pending
        if (! $refundRequest->isPending()) {
            return response()->json([
                'message' => 'Only pending refund requests can be approved',
                'current_status' => $refundRequest->status,
            ], 400);
        }

        // Prevent self-approval (commented out for now)
        // if ($refundRequest->requested_by === $user->id) {
        //     return response()->json([
        //         'message' => 'You cannot approve your own refund request',
        //     ], 403);
        // }

        DB::beginTransaction();
        try {
            $sale = $refundRequest->sale;

            if ($refundRequest->refund_scope === RefundRequest::SCOPE_WHOLE_SALE) {
                // Restore inventory for every sale item
                foreach ($sale->items as $item) {
                    $this->restoreInventoryForRefund(
                        $sale,
                        $item,
                        (float) $item->quantity,
                        $businessId,
                        $user->id
                    );
                }
            } else {
                // Restore inventory only for requested items/quantities
                foreach ($refundRequest->items as $refundItem) {
                    $saleItem = $refundItem->saleItem;
                    $this->restoreInventoryForRefund(
                        $sale,
                        $saleItem,
                        (float) $refundItem->quantity,
                        $businessId,
                        $user->id
                    );
                }
            }

            $refundRequest->markAsApproved($user->id);
            $sale->addRefundedAmount((float) $refundRequest->amount);
            $refundRequest->markAsProcessed();

            DB::commit();

            return response()->json([
                'message' => 'Refund request approved and processed successfully',
                'refund_request' => $refundRequest->fresh([
                    'sale',
                    'requestedBy',
                    'reviewedBy',
                    'items.saleItem.product',
                ]),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to process refund',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject a refund request
     */
    public function reject(Request $request, $id)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('approve refund')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|min:10|max:1000',
        ]);

        $refundRequest = RefundRequest::forBusiness($businessId)->findOrFail($id);

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $refundRequest->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        // Validate request is pending
        if (! $refundRequest->isPending()) {
            return response()->json([
                'message' => 'Only pending refund requests can be rejected',
                'current_status' => $refundRequest->status,
            ], 400);
        }

        // Prevent self-rejection (commented out for now)
        // if ($refundRequest->requested_by === $user->id) {
        //     return response()->json([
        //         'message' => 'You cannot reject your own refund request',
        //     ], 403);
        // }

        DB::beginTransaction();
        try {
            $refundRequest->markAsRejected($user->id, $validated['rejection_reason']);

            DB::commit();

            return response()->json([
                'message' => 'Refund request rejected',
                'refund_request' => $refundRequest->fresh([
                    'sale',
                    'requestedBy',
                    'reviewedBy',
                ]),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to reject refund request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
