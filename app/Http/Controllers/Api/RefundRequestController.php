<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\BranchProduct;
use App\Models\InventoryTransaction;
use App\Models\RefundRequest;
use App\Models\Sale;
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
        ]);

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
                'reason' => $sale->is_refunded
                    ? 'Sale has already been refunded'
                    : ($sale->trashed() ? 'Sale has been deleted' : 'Sale status does not allow refund'),
            ], 400);
        }

        // Check for duplicate pending requests
        if ($sale->hasPendingRefundRequest()) {
            return response()->json([
                'message' => 'A pending refund request already exists for this sale',
            ], 400);
        }

        DB::beginTransaction();
        try {
            $refundRequest = RefundRequest::create([
                'sale_id' => $sale->id,
                'business_id' => $businessId,
                'branch_id' => $sale->branch_id,
                'requested_by' => $user->id,
                'amount' => $sale->total_amount,
                'reason' => $validated['reason'],
                'status' => RefundRequest::STATUS_PENDING,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Refund request submitted successfully',
                'refund_request' => $refundRequest->load(['sale', 'requestedBy']),
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

        $refundRequest = RefundRequest::with('sale.items')
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

        // Prevent self-approval
        if ($refundRequest->requested_by === $user->id) {
            return response()->json([
                'message' => 'You cannot approve your own refund request',
            ], 403);
        }

        DB::beginTransaction();
        try {
            $sale = $refundRequest->sale;

            // Restore inventory for each item
            foreach ($sale->items as $item) {
                $branchProduct = BranchProduct::where('branch_id', $sale->branch_id)
                    ->where('product_id', $item->product_id)
                    ->first();

                if ($branchProduct) {
                    $branchProduct->increment('stock_quantity', $item->quantity);

                    $adjTransaction = InventoryTransaction::create([
                        'uuid' => Str::uuid(),
                        'business_id' => $businessId,
                        'branch_id' => $sale->branch_id,
                        'product_id' => $item->product_id,
                        'user_id' => $user->id,
                        'type' => 'adjustment',
                        'quantity' => $item->quantity,
                        'quantity_before' => $branchProduct->stock_quantity - $item->quantity,
                        'quantity_after' => $branchProduct->stock_quantity,
                        'unit_cost' => $branchProduct->cost_price,
                        'total_cost' => $branchProduct->cost_price * $item->quantity,
                        'reference_number' => $sale->sale_number,
                        'notes' => "Refund approved for sale: {$sale->sale_number}",
                    ]);

                    $this->batchService->addStockIn(
                        $item->product_id,
                        $sale->branch_id,
                        $businessId,
                        $item->quantity,
                        $adjTransaction,
                        $item->batch_id,
                        []
                    );
                }
            }

            // Mark refund request as approved
            $refundRequest->markAsApproved($user->id);

            // Mark sale as refunded
            $sale->markAsRefunded();

            // Mark refund as processed
            $refundRequest->markAsProcessed();

            DB::commit();

            return response()->json([
                'message' => 'Refund request approved and processed successfully',
                'refund_request' => $refundRequest->fresh([
                    'sale',
                    'requestedBy',
                    'reviewedBy',
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

        // Prevent self-rejection
        if ($refundRequest->requested_by === $user->id) {
            return response()->json([
                'message' => 'You cannot reject your own refund request',
            ], 403);
        }

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
