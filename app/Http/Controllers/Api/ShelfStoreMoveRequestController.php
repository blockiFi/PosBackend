<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\BranchProduct;
use App\Models\ShelfStoreMoveRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ShelfStoreMoveRequestController extends Controller
{
    use HasBranchAccess;

    /**
     * List shelf/store move requests
     */
    public function index(Request $request)
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
        $canView = $business->owner_id === $user->id
            || $user->hasPermissionTo('request shelf store move')
            || $user->hasPermissionTo('approve shelf store move');

        if (! $canView) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = ShelfStoreMoveRequest::where('business_id', $businessId)
            ->with(['branch', 'branchProduct.product', 'requestedBy', 'reviewedBy']);

        if ($request->filled('branch_id')) {
            $branchId = (int) $request->input('branch_id');
            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json(['message' => 'You do not have access to this branch'], 403);
            }
            $query->where('branch_id', $branchId);
        } else {
            $permittedBranches = $this->getPermittedBranches($user, $businessId);
            if ($permittedBranches->isNotEmpty()) {
                $query->whereIn('branch_id', $permittedBranches);
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->boolean('my_requests', false)) {
            $query->where('requested_by', $user->id);
        }

        if ($request->boolean('pending_approval', false)) {
            $query->where('status', ShelfStoreMoveRequest::STATUS_PENDING);
        }

        $perPage = $request->input('per_page', 15);
        $requests = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $requests->getCollection()->map(fn ($r) => $this->formatRequest($r)),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    /**
     * Create a shelf/store move request
     */
    public function store(Request $request)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('request shelf store move')) {
            return response()->json(['message' => 'You do not have permission to request shelf/store moves'], 403);
        }

        $validator = Validator::make($request->all(), [
            'branch_product_id' => ['required', 'integer', 'exists:branch_products,id'],
            'direction' => ['required', 'in:to_shelf,to_store'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchProduct = BranchProduct::with('branch')->find($request->branch_product_id);

        if (! $branchProduct || $branchProduct->branch->business_id != $businessId) {
            return response()->json(['message' => 'Branch product not found or does not belong to this business'], 404);
        }

        if (! $this->userHasBranchAccess($user, $businessId, $branchProduct->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        if ($request->direction === ShelfStoreMoveRequest::DIRECTION_TO_SHELF) {
            if ($request->quantity > $branchProduct->store_quantity) {
                return response()->json([
                    'message' => 'Insufficient quantity in store',
                    'available_in_store' => $branchProduct->store_quantity,
                ], 422);
            }
        } else {
            if ($request->quantity > $branchProduct->shelf_quantity) {
                return response()->json([
                    'message' => 'Insufficient quantity on shelf',
                    'available_on_shelf' => $branchProduct->shelf_quantity,
                ], 422);
            }
        }

        $moveRequest = ShelfStoreMoveRequest::create([
            'business_id' => $businessId,
            'branch_id' => $branchProduct->branch_id,
            'branch_product_id' => $branchProduct->id,
            'direction' => $request->direction,
            'quantity' => $request->quantity,
            'reason' => $request->reason,
            'status' => ShelfStoreMoveRequest::STATUS_PENDING,
            'requested_by' => $user->id,
            'requested_at' => now(),
        ]);

        $moveRequest->load(['branch', 'branchProduct.product', 'requestedBy']);

        return response()->json([
            'message' => 'Shelf/store move request created successfully',
            'data' => $this->formatRequest($moveRequest),
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

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        $moveRequest = ShelfStoreMoveRequest::where('id', $id)
            ->where('business_id', $businessId)
            ->with(['branch', 'branchProduct.product', 'requestedBy', 'reviewedBy'])
            ->first();

        if (! $moveRequest) {
            return response()->json(['message' => 'Request not found'], 404);
        }

        setPermissionsTeamId($businessId);
        $canView = $moveRequest->requested_by === $user->id
            || $business->owner_id === $user->id
            || $user->hasPermissionTo('request shelf store move')
            || $user->hasPermissionTo('approve shelf store move');

        if (! $canView) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! $this->userHasBranchAccess($user, $businessId, $moveRequest->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        return response()->json(['data' => $this->formatRequest($moveRequest)]);
    }

    /**
     * Approve a shelf/store move request and perform the move
     */
    public function approve(Request $request, int $id)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('approve shelf store move')) {
            return response()->json(['message' => 'You do not have permission to approve shelf/store moves'], 403);
        }

        $moveRequest = ShelfStoreMoveRequest::where('id', $id)
            ->where('business_id', $businessId)
            ->where('status', ShelfStoreMoveRequest::STATUS_PENDING)
            ->with('branchProduct')
            ->lockForUpdate()
            ->first();

        if (! $moveRequest) {
            return response()->json(['message' => 'Request not found or not pending'], 404);
        }

        if (! $this->userHasBranchAccess($user, $businessId, $moveRequest->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $validator = Validator::make($request->all(), [
            'quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $approvedQuantity = (int) ($request->input('quantity') ?? $moveRequest->quantity);
        

        $branchProduct = $moveRequest->branchProduct;

        if ($moveRequest->direction === ShelfStoreMoveRequest::DIRECTION_TO_SHELF) {
            if ($approvedQuantity > $branchProduct->store_quantity) {
                return response()->json([
                    'message' => 'Insufficient quantity in store (stock may have changed). Request cannot be approved.',
                    'available_in_store' => $branchProduct->store_quantity,
                ], 422);
            }
        } else {
            if ($approvedQuantity > $branchProduct->shelf_quantity) {
                return response()->json([
                    'message' => 'Insufficient quantity on shelf (stock may have changed). Request cannot be approved.',
                    'available_on_shelf' => $branchProduct->shelf_quantity,
                ], 422);
            }
        }

        try {
            DB::transaction(function () use ($moveRequest, $user, $approvedQuantity): void {
                if ($moveRequest->direction === ShelfStoreMoveRequest::DIRECTION_TO_SHELF) {
                    $moveRequest->branchProduct->moveToShelf($approvedQuantity);
                } else {
                    $moveRequest->branchProduct->moveToStore($approvedQuantity);
                }

                $moveRequest->update([
                    'status' => ShelfStoreMoveRequest::STATUS_APPROVED,
                    'reviewed_by' => $user->id,
                    'reviewed_at' => now(),
                    'review_notes' => $approvedQuantity === (int) $moveRequest->quantity
                        ? $moveRequest->review_notes
                        : "Approved quantity: {$approvedQuantity} (requested {$moveRequest->quantity})",
                ]);
            });
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to perform move: '.$e->getMessage(),
            ], 422);
        }

        $moveRequest->load(['branch', 'branchProduct.product', 'requestedBy', 'reviewedBy']);

        return response()->json([
            'message' => 'Request approved and stock moved successfully',
            'data' => $this->formatRequest($moveRequest->fresh()),
        ]);
    }

    /**
     * Reject a shelf/store move request
     */
    public function reject(Request $request, int $id)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('approve shelf store move')) {
            return response()->json(['message' => 'You do not have permission to reject shelf/store moves'], 403);
        }

        $moveRequest = ShelfStoreMoveRequest::where('id', $id)
            ->where('business_id', $businessId)
            ->where('status', ShelfStoreMoveRequest::STATUS_PENDING)
            ->lockForUpdate()
            ->first();

        if (! $moveRequest) {
            return response()->json(['message' => 'Request not found or not pending'], 404);
        }

        if (! $this->userHasBranchAccess($user, $businessId, $moveRequest->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $moveRequest->update([
            'status' => ShelfStoreMoveRequest::STATUS_REJECTED,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'review_notes' => $request->input('reason'),
        ]);

        $moveRequest->load(['branch', 'branchProduct.product', 'requestedBy', 'reviewedBy']);

        return response()->json([
            'message' => 'Request rejected successfully',
            'data' => $this->formatRequest($moveRequest->fresh()),
        ]);
    }

    private function formatRequest(ShelfStoreMoveRequest $moveRequest): array
    {
        $data = [
            'id' => $moveRequest->id,
            'request_number' => $moveRequest->request_number,
            'business_id' => $moveRequest->business_id,
            'branch_id' => $moveRequest->branch_id,
            'branch_product_id' => $moveRequest->branch_product_id,
            'direction' => $moveRequest->direction,
            'quantity' => $moveRequest->quantity,
            'reason' => $moveRequest->reason,
            'status' => $moveRequest->status,
            'requested_by' => $moveRequest->requestedBy ? [
                'id' => $moveRequest->requestedBy->id,
                'name' => $moveRequest->requestedBy->name,
                'email' => $moveRequest->requestedBy->email,
            ] : null,
            'requested_at' => $moveRequest->requested_at?->toIso8601String(),
            'reviewed_by' => $moveRequest->reviewedBy ? [
                'id' => $moveRequest->reviewedBy->id,
                'name' => $moveRequest->reviewedBy->name,
                'email' => $moveRequest->reviewedBy->email,
            ] : null,
            'reviewed_at' => $moveRequest->reviewed_at?->toIso8601String(),
            'review_notes' => $moveRequest->review_notes,
            'created_at' => $moveRequest->created_at?->toIso8601String(),
            'updated_at' => $moveRequest->updated_at?->toIso8601String(),
        ];

        if ($moveRequest->relationLoaded('branch')) {
            $data['branch'] = $moveRequest->branch ? [
                'id' => $moveRequest->branch->id,
                'name' => $moveRequest->branch->name,
            ] : null;
        }

        if ($moveRequest->relationLoaded('branchProduct') && $moveRequest->branchProduct) {
            $data['branch_product'] = [
                'id' => $moveRequest->branchProduct->id,
                'shelf_quantity' => $moveRequest->branchProduct->shelf_quantity,
                'store_quantity' => $moveRequest->branchProduct->store_quantity,
            ];
            if ($moveRequest->branchProduct->relationLoaded('product') && $moveRequest->branchProduct->product) {
                $data['branch_product']['product'] = [
                    'id' => $moveRequest->branchProduct->product->id,
                    'name' => $moveRequest->branchProduct->product->name,
                    'sku' => $moveRequest->branchProduct->product->sku,
                ];
            }
        }

        return $data;
    }
}
