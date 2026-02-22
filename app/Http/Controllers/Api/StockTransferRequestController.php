<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\BranchProduct;
use App\Models\StockTransferRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StockTransferRequestController extends Controller
{
    use HasBranchAccess;

    /**
     * List stock transfer requests
     */
    public function index(Request $request)
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

        // Set permission context
        setPermissionsTeamId($businessId);

        // Check if user can view requests (either requester or approver, or owner)
        $canView = $business->owner_id === $user->id ||
                    $user->hasPermissionTo('request stock transfer') ||
                    $user->hasPermissionTo('approve stock transfer');

        if (! $canView) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = StockTransferRequest::where('business_id', $businessId)
            ->with(['branch', 'branchFrom', 'branchTo', 'branchProduct.product', 'requestedBy', 'reviewedBy', 'confirmedBy', 'transferInRequest', 'transferOutRequest']);

        // Filters: branch_id shows requests where branch is source (from) or destination (to)
        if ($request->has('branch_id')) {
            $branchId = (int) $request->input('branch_id');

            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json(['message' => 'You do not have access to this branch'], 403);
            }

            $query->where(function ($q) use ($branchId) {
                $q->where('branch_from_id', $branchId)->orWhere('branch_to_id', $branchId);
            });
        } else {
            // Scope to user's permitted branches (from or to)
            $permittedBranches = $this->getPermittedBranches($user, $businessId);
            if ($permittedBranches->isNotEmpty()) {
                $query->where(function ($q) use ($permittedBranches) {
                    $q->whereIn('branch_from_id', $permittedBranches)->orWhereIn('branch_to_id', $permittedBranches);
                });
            }
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        // Filter by requester
        if ($request->boolean('my_requests', false)) {
            $query->where('requested_by', $user->id);
        }

        // Filter pending approvals (for approvers)
        if ($request->boolean('pending_approval', false)) {
            $query->where('status', StockTransferRequest::STATUS_PENDING);
        }

        // Filter approved pending confirmation (out-requests)
        if ($request->boolean('pending_confirmation', false)) {
            $query->where('status', StockTransferRequest::STATUS_APPROVED)->where('direction', StockTransferRequest::DIRECTION_OUT);
        }

        // Filter pending acceptance (in-requests at receiving branch)
        if ($request->boolean('pending_acceptance', false)) {
            $query->where('status', StockTransferRequest::STATUS_PENDING_ACCEPTANCE)->where('direction', StockTransferRequest::DIRECTION_IN);
        }

        $perPage = $request->input('per_page', 15);
        $requests = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $requests->map(fn ($r) => $this->formatRequest($r)),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    /**
     * Create a new stock transfer request
     */
    public function store(Request $request)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('request stock transfer')) {
            return response()->json(['message' => 'You do not have permission to request stock transfers'], 403);
        }

        $validator = Validator::make($request->all(), [
            'branch_from_id' => ['required', 'integer', 'exists:branches,id,business_id,'.$businessId],
            'branch_to_id' => ['required', 'integer', 'exists:branches,id,business_id,'.$businessId],
            'branch_product_id' => ['required', 'integer', 'exists:branch_products,id'],
            'quantity_requested' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->branch_from_id == $request->branch_to_id) {
            return response()->json(['message' => 'Source and destination branch must be different'], 422);
        }

        if (! $this->userHasBranchAccess($user, $businessId, $request->branch_from_id)) {
            return response()->json(['message' => 'You do not have access to  this branch'], 403);
        }

        $branchProduct = BranchProduct::where('id', $request->branch_product_id)
            ->where('branch_id', $request->branch_from_id)
            ->first();

        if (! $branchProduct) {
            return response()->json(['message' => 'Product not found in source branch'], 404);
        }

        $totalAvailable = $branchProduct->store_quantity + $branchProduct->shelf_quantity;
        if ($totalAvailable < $request->quantity_requested) {
            return response()->json([
                'message' => 'Insufficient stock',
                'available' => $totalAvailable,
                'requested' => $request->quantity_requested,
            ], 422);
        }

        $transferRequest = StockTransferRequest::create([
            'business_id' => $businessId,
            'branch_id' => $request->branch_from_id,
            'branch_from_id' => $request->branch_from_id,
            'branch_to_id' => $request->branch_to_id,
            'direction' => StockTransferRequest::DIRECTION_OUT,
            'branch_product_id' => $request->branch_product_id,
            'quantity_requested' => $request->quantity_requested,
            'reason' => $request->reason,
            'priority' => $request->priority ?? 'normal',
            'status' => StockTransferRequest::STATUS_PENDING,
            'requested_by' => $user->id,
            'requested_at' => now(),
        ]);

        return response()->json([
            'message' => 'Stock transfer request created successfully',
            'data' => $this->formatRequest($transferRequest->load(['branch', 'branchFrom', 'branchTo', 'branchProduct.product', 'requestedBy'])),
        ], 201);
    }

    /**
     * Show a specific request
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

        $transferRequest = StockTransferRequest::where('id', $id)
            ->where('business_id', $businessId)
            ->with(['branch', 'branchFrom', 'branchTo', 'branchProduct.product', 'requestedBy', 'reviewedBy', 'confirmedBy', 'transferInRequest', 'transferOutRequest.branchProduct.product'])
            ->first();

        if (! $transferRequest) {
            return response()->json(['message' => 'Request not found'], 404);
        }

        setPermissionsTeamId($businessId);

        $canView = $transferRequest->requested_by === $user->id ||
                   $business->owner_id === $user->id ||
                   $user->hasPermissionTo('approve stock transfer') ||
                   $user->hasPermissionTo('request stock transfer') ||
                   $user->hasPermissionTo('accept stock transfer');

        if (! $canView) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $branchFromId = $transferRequest->branch_from_id ?? $transferRequest->branch_id;
        $branchToId = $transferRequest->branch_to_id;
        $hasAccess = $this->userHasBranchAccess($user, $businessId, $branchFromId);
        if ($branchToId && ! $hasAccess) {
            $hasAccess = $this->userHasBranchAccess($user, $businessId, $branchToId);
        } elseif ($branchToId) {
            $hasAccess = true;
        }
        if (! $hasAccess) {
            return response()->json(['message' => 'You do not have access to this transfer\'s branches'], 403);
        }

        return response()->json([
            'data' => $this->formatRequest($transferRequest),
        ]);
    }

    /**
     * Approve a stock transfer request
     */
    public function approve(Request $request, int $id)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('approve stock transfer')) {
            return response()->json(['message' => 'You do not have permission to approve stock transfers'], 403);
        }

        $transferRequest = StockTransferRequest::where('id', $id)
            ->where('business_id', $businessId)
            ->where('direction', StockTransferRequest::DIRECTION_OUT)
            ->with('branchProduct.product')
            ->lockForUpdate()
            ->first();

        if (! $transferRequest) {
            return response()->json(['message' => 'Request not found'], 404);
        }

        $branchFromId = $transferRequest->branch_from_id ?? $transferRequest->branch_id;
        if (! $this->userHasBranchAccess($user, $businessId, $branchFromId)) {
            return response()->json(['message' => 'You do not have access to the sending branch'], 403);
        }

        // // Prevent self-approval
        // if ($transferRequest->requested_by === $user->id) {
        //     return response()->json(['message' => 'You cannot approve your own request'], 403);
        // }

        $validator = Validator::make($request->all(), [
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $transferRequest->approve($user, $request->notes);

            $fresh = $transferRequest->fresh(['requestedBy', 'reviewedBy', 'branchFrom', 'branchTo', 'transferInRequest']);

            return response()->json([
                'message' => 'Request approved successfully. A transfer-in request has been created for the receiving branch.',
                'data' => $this->formatRequest($fresh),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Accept transfer at receiving branch (in-request only).
     */
    public function accept(Request $request, int $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json(['message' => 'Business context is required'], 400);
        }

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('accept stock transfer')) {
            return response()->json(['message' => 'You do not have permission to accept stock transfers'], 403);
        }

        $transferRequest = StockTransferRequest::where('id', $id)
            ->where('business_id', $businessId)
            ->where('direction', StockTransferRequest::DIRECTION_IN)
            ->with(['transferOutRequest.branchProduct.product'])
            ->lockForUpdate()
            ->first();

        if (! $transferRequest) {
            return response()->json(['message' => 'Request not found'], 404);
        }

        if (! $this->userHasBranchAccess($user, $businessId, $transferRequest->branch_to_id)) {
            return response()->json(['message' => 'You do not have access to the receiving branch'], 403);
        }

        try {
            $transferRequest->acceptInRequest($user);

            return response()->json([
                'message' => 'Transfer accepted and stock updated successfully',
                'data' => $this->formatRequest($transferRequest->fresh(['transferOutRequest', 'confirmedBy', 'branchFrom', 'branchTo'])),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reject transfer at receiving branch (in-request only).
     */
    public function rejectIn(Request $request, int $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json(['message' => 'Business context is required'], 400);
        }

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('accept stock transfer')) {
            return response()->json(['message' => 'You do not have permission to accept or reject stock transfers at receiving branch'], 403);
        }

        $transferRequest = StockTransferRequest::where('id', $id)
            ->where('business_id', $businessId)
            ->where('direction', StockTransferRequest::DIRECTION_IN)
            ->with(['transferOutRequest.branchProduct'])
            ->lockForUpdate()
            ->first();

        if (! $transferRequest) {
            return response()->json(['message' => 'Request not found'], 404);
        }

        if (! $this->userHasBranchAccess($user, $businessId, $transferRequest->branch_to_id)) {
            return response()->json(['message' => 'You do not have access to the receiving branch'], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $transferRequest->rejectInRequest($user, $request->reason);

            return response()->json([
                'message' => 'Transfer rejected. Stock has been reversed at the sending branch.',
                'data' => $this->formatRequest($transferRequest->fresh(['transferOutRequest', 'reviewedBy', 'branchFrom', 'branchTo'])),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reject a stock transfer request (out-request, at sending branch)
     */
    public function reject(Request $request, int $id)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('approve stock transfer')) {
            return response()->json(['message' => 'You do not have permission to reject stock transfers'], 403);
        }

        $transferRequest = StockTransferRequest::where('id', $id)
            ->where('business_id', $businessId)
            ->lockForUpdate()
            ->first();

        if (! $transferRequest) {
            return response()->json(['message' => 'Request not found'], 404);
        }

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $transferRequest->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $transferRequest->reject($user, $request->reason);

            return response()->json([
                'message' => 'Request rejected successfully',
                'data' => $this->formatRequest($transferRequest->fresh(['requestedBy', 'reviewedBy'])),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Confirm a stock transfer request (perform the actual transfer)
     */
    public function confirm(Request $request, int $id)
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

        // Set permission context
        setPermissionsTeamId($businessId);

        $transferRequest = StockTransferRequest::where('id', $id)
            ->where('business_id', $businessId)
            ->with('branchProduct')
            ->lockForUpdate()
            ->first();

        if (! $transferRequest) {
            return response()->json(['message' => 'Request not found'], 404);
        }

        // Only the requester, owner, or someone with special permission can confirm
        $canConfirm = $business->owner_id === $user->id ||
                      $transferRequest->requested_by === $user->id ||
                      $user->hasPermissionTo('approve stock transfer');

        if (! $canConfirm) {
            return response()->json(['message' => 'Only the requester can confirm this transfer'], 403);
        }

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $transferRequest->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $validator = Validator::make($request->all(), [
            'actual_quantity' => ['nullable', 'integer', 'min:1', 'max:'.$transferRequest->quantity_requested],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $actualQuantity = $request->actual_quantity ?? $transferRequest->quantity_requested;

            $transferRequest->confirm($user, $actualQuantity, $request->notes);

            return response()->json([
                'message' => 'Transfer confirmed and completed successfully',
                'data' => $this->formatRequest($transferRequest->fresh(['requestedBy', 'reviewedBy', 'confirmedBy', 'branchProduct'])),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function cancel(Request $request, int $id)
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

        $transferRequest = StockTransferRequest::where('id', $id)
            ->where('business_id', $businessId)
            ->lockForUpdate()
            ->first();

        if (! $transferRequest) {
            return response()->json(['message' => 'Request not found'], 404);
        }

        setPermissionsTeamId($businessId);

        // Only the requester or owner can cancel
        if ($business->owner_id !== $user->id && $transferRequest->requested_by !== $user->id) {
            return response()->json(['message' => 'Only the requester can cancel this request'], 403);
        }

        $branchId = $transferRequest->branch_from_id ?? $transferRequest->branch_id;
        if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $transferRequest->cancel($user, $request->reason);

            return response()->json([
                'message' => 'Request cancelled successfully',
                'data' => $this->formatRequest($transferRequest->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Format request for response
     */
    private function formatRequest(StockTransferRequest $transferRequest): array
    {
        $product = null;
        if ($transferRequest->branchProduct && $transferRequest->branchProduct->relationLoaded('product') && $transferRequest->branchProduct->product) {
            $product = [
                'id' => $transferRequest->branchProduct->product->id,
                'name' => $transferRequest->branchProduct->product->name,
                'sku' => $transferRequest->branchProduct->product->sku,
                'current_shelf_quantity' => $transferRequest->branchProduct->shelf_quantity,
                'current_store_quantity' => $transferRequest->branchProduct->store_quantity,
            ];
        } elseif ($transferRequest->transferOutRequest && $transferRequest->transferOutRequest->relationLoaded('branchProduct') && $transferRequest->transferOutRequest->branchProduct) {
            $bp = $transferRequest->transferOutRequest->branchProduct;
            $product = [
                'id' => $bp->product_id,
                'name' => $bp->product->name ?? null,
                'sku' => $bp->product->sku ?? null,
                'current_shelf_quantity' => $bp->shelf_quantity,
                'current_store_quantity' => $bp->store_quantity,
            ];
        }

        $data = [
            'id' => $transferRequest->id,
            'request_number' => $transferRequest->request_number,
            'business_id' => $transferRequest->business_id,
            'direction' => $transferRequest->direction ?? 'out',
            'branch' => $transferRequest->branch ? [
                'id' => $transferRequest->branch->id,
                'name' => $transferRequest->branch->name,
                'code' => $transferRequest->branch->code,
            ] : null,
            'branch_from' => $transferRequest->branchFrom ? [
                'id' => $transferRequest->branchFrom->id,
                'name' => $transferRequest->branchFrom->name,
                'code' => $transferRequest->branchFrom->code,
            ] : null,
            'branch_to' => $transferRequest->branchTo ? [
                'id' => $transferRequest->branchTo->id,
                'name' => $transferRequest->branchTo->name,
                'code' => $transferRequest->branchTo->code,
            ] : null,
            'product' => $product,
            'quantity_requested' => $transferRequest->quantity_requested,
            'quantity_transferred' => $transferRequest->quantity_transferred,
            'reason' => $transferRequest->reason,
            'priority' => $transferRequest->priority,
            'status' => $transferRequest->status,
            'requested_by' => $transferRequest->requestedBy ? [
                'id' => $transferRequest->requestedBy->id,
                'name' => $transferRequest->requestedBy->name,
                'email' => $transferRequest->requestedBy->email,
            ] : null,
            'requested_at' => $transferRequest->requested_at,
            'reviewed_by' => $transferRequest->reviewedBy ? [
                'id' => $transferRequest->reviewedBy->id,
                'name' => $transferRequest->reviewedBy->name,
                'email' => $transferRequest->reviewedBy->email,
            ] : null,
            'reviewed_at' => $transferRequest->reviewed_at,
            'review_notes' => $transferRequest->review_notes,
            'confirmed_by' => $transferRequest->confirmedBy ? [
                'id' => $transferRequest->confirmedBy->id,
                'name' => $transferRequest->confirmedBy->name,
                'email' => $transferRequest->confirmedBy->email,
            ] : null,
            'confirmed_at' => $transferRequest->confirmed_at,
            'confirmation_notes' => $transferRequest->confirmation_notes,
            'version' => $transferRequest->version,
            'created_at' => $transferRequest->created_at,
            'updated_at' => $transferRequest->updated_at,
        ];

        if ($transferRequest->relationLoaded('transferInRequest') && $transferRequest->transferInRequest) {
            $data['transfer_in_request'] = [
                'id' => $transferRequest->transferInRequest->id,
                'request_number' => $transferRequest->transferInRequest->request_number,
                'status' => $transferRequest->transferInRequest->status,
            ];
        }
        if ($transferRequest->relationLoaded('transferOutRequest') && $transferRequest->transferOutRequest) {
            $data['transfer_out_request'] = [
                'id' => $transferRequest->transferOutRequest->id,
                'request_number' => $transferRequest->transferOutRequest->request_number,
                'status' => $transferRequest->transferOutRequest->status,
            ];
        }

        return $data;
    }
}
