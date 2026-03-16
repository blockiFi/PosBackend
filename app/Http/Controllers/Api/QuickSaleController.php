<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\QuickSale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QuickSaleController extends Controller
{
    use HasBranchAccess;

    /**
     * List quick sales with filtering
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
        $canRequest = $user->hasPermissionTo('request quick sale');
        $canApprove = $business->owner_id === $user->id || $user->hasPermissionTo('approve quick sale');

        if (! $canRequest && ! $canApprove) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = QuickSale::with([
            'product',
            'branch',
            'batch',
            'requestedBy',
            'approvedBy',
            'endedBy',
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

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        $quickSales = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json($quickSales);
    }

    /**
     * Create a new quick sale request
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
        $canRequest = $user->hasPermissionTo('request quick sale');
        $canApprove = $business->owner_id === $user->id || $user->hasPermissionTo('approve quick sale');
        if (! $canRequest && ! $canApprove) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'branch_id' => 'required|exists:branches,id',
            'batch_id' => 'nullable|exists:product_batches,id',
            'reason' => 'required|string|min:10|max:1000',
            'expiry_date' => 'required|date|after:today',
            'discount_type' => 'nullable|in:percentage,fixed',
            'discount_value' => 'nullable|numeric|min:0',
            'start_time' => 'nullable|date|after_or_equal:now',
            'end_time' => [
                'nullable',
                'date',
                Rule::when($request->filled('start_time'), 'after:start_time'),
            ],
        ]);

        // Verify product belongs to business
        $product = Product::where('id', $validated['product_id'])
            ->where('business_id', $businessId)
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $validated['branch_id'])) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        $batchId = isset($validated['batch_id']) ? (int) $validated['batch_id'] : null;
        $batch = null;
        if ($batchId) {
            $batch = ProductBatch::where('id', $batchId)
                ->where('product_id', $product->id)
                ->where('branch_id', $validated['branch_id'])
                ->where('business_id', $businessId)
                ->first();

            if (! $batch) {
                return response()->json([
                    'message' => 'Batch not found or does not belong to this product and branch',
                    'errors' => ['batch_id' => ['Invalid batch for this product and branch']],
                ], 422);
            }

            if ($batch->current_quantity <= 0) {
                return response()->json([
                    'message' => 'Batch has no remaining quantity',
                    'errors' => ['batch_id' => ['Batch is depleted']],
                ], 422);
            }
        }

        // Verify product exists in this branch and has stock
        $branchProduct = BranchProduct::where('product_id', $product->id)
            ->where('branch_id', $validated['branch_id'])
            ->first();

        if (! $branchProduct) {
            return response()->json([
                'message' => 'Product not available in this branch',
            ], 400);
        }

        if ($branchProduct->stock_quantity <= 0) {
            return response()->json([
                'message' => 'Product is out of stock',
            ], 400);
        }

        // Check for pending quick sale for same product/branch (and batch when batch-scoped)
        $pendingQuery = QuickSale::where('product_id', $product->id)
            ->where('branch_id', $validated['branch_id'])
            ->where('status', QuickSale::STATUS_PENDING);
        if ($batchId !== null) {
            $pendingQuery->where('batch_id', $batchId);
        } else {
            $pendingQuery->whereNull('batch_id');
        }
        if ($pendingQuery->exists()) {
            return response()->json([
                'message' => 'A pending quick sale request already exists for this product in this branch',
            ], 400);
        }

        $hasApprovalFields = isset(
            $validated['discount_type'],
            $validated['discount_value'],
            $validated['start_time'],
            $validated['end_time']
        ) && $validated['discount_type'] !== null && $validated['discount_type'] !== ''
            && $validated['discount_value'] !== null && $validated['discount_value'] !== ''
            && $validated['start_time'] !== null && $validated['start_time'] !== ''
            && $validated['end_time'] !== null && $validated['end_time'] !== '';

        DB::beginTransaction();
        try {
            $quickSale = QuickSale::create([
                'product_id' => $product->id,
                'business_id' => $businessId,
                'branch_id' => $validated['branch_id'],
                'batch_id' => $batchId,
                'requested_by' => $user->id,
                'reason' => $validated['reason'],
                'expiry_date' => $validated['expiry_date'],
                'status' => QuickSale::STATUS_PENDING,
            ]);

            if ($canApprove && $hasApprovalFields) {
                // Same validation as approve()
                if ($validated['discount_type'] === 'percentage' && (float) $validated['discount_value'] > 100) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'Percentage discount cannot exceed 100%',
                        'errors' => ['discount_value' => ['Percentage must be between 0 and 100']],
                    ], 422);
                }

                if ($quickSale->batch_id) {
                    $batchForApproval = $quickSale->batch;
                    if (! $batchForApproval || $batchForApproval->current_quantity <= 0) {
                        DB::rollBack();

                        return response()->json([
                            'message' => 'Batch has no remaining quantity; cannot approve quick sale',
                        ], 400);
                    }
                }

                $hasOverlap = QuickSale::hasOverlappingQuickSale(
                    $quickSale->product_id,
                    $quickSale->branch_id,
                    $validated['start_time'],
                    $validated['end_time'],
                    $quickSale->id,
                    $quickSale->batch_id
                );

                if ($hasOverlap) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'Another quick sale is already scheduled for this product during the selected time period',
                    ], 400);
                }

                if ($validated['discount_type'] === 'fixed') {
                    if ($branchProduct && (float) $validated['discount_value'] >= (float) $branchProduct->selling_price) {
                        DB::rollBack();

                        return response()->json([
                            'message' => 'Fixed discount amount cannot be greater than or equal to the product price',
                            'errors' => ['discount_value' => ['Must be less than product price']],
                        ], 422);
                    }
                }

                $quickSale->markAsApproved(
                    $user->id,
                    $validated['discount_type'],
                    $validated['discount_value'],
                    $validated['start_time'],
                    $validated['end_time']
                );

                if (Carbon::parse($validated['start_time'])->lte(now())) {
                    $quickSale->markAsActive();
                }
            }

            DB::commit();

            $message = ($canApprove && $hasApprovalFields)
                ? 'Quick sale created and approved'
                : 'Quick sale request submitted successfully';

            return response()->json([
                'message' => $message,
                'quick_sale' => $quickSale->fresh([
                    'product',
                    'branch',
                    'batch',
                    'requestedBy',
                    'approvedBy',
                ]),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to create quick sale request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * View a specific quick sale
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
        $canRequest = $user->hasPermissionTo('request quick sale');
        $canApprove = $business->owner_id === $user->id || $user->hasPermissionTo('approve quick sale');

        if (! $canRequest && ! $canApprove) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $quickSale = QuickSale::with([
            'product.branchProducts' => function ($q) use ($id) {
                $quickSale = QuickSale::find($id);
                if ($quickSale) {
                    $q->where('branch_id', $quickSale->branch_id);
                }
            },
            'branch',
            'batch',
            'requestedBy',
            'approvedBy',
            'endedBy',
        ])->forBusiness($businessId)->findOrFail($id);

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $quickSale->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        // If user can only request (not approve), they can only view their own requests
        if ($canRequest && ! $canApprove && $quickSale->requested_by !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($quickSale);
    }

    /**
     * Approve a quick sale request
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('approve quick sale')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'discount_type' => 'required|in:percentage,fixed',
            'discount_value' => 'required|numeric|min:0',
            'start_time' => 'required|date|after_or_equal:now',
            'end_time' => 'required|date|after:start_time',
        ]);

        // Additional validation for percentage
        if ($validated['discount_type'] === 'percentage' && $validated['discount_value'] > 100) {
            return response()->json([
                'message' => 'Percentage discount cannot exceed 100%',
                'errors' => ['discount_value' => ['Percentage must be between 0 and 100']],
            ], 422);
        }

        $quickSale = QuickSale::forBusiness($businessId)->findOrFail($id);

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $quickSale->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        // Validate request is pending
        if (! $quickSale->isPending()) {
            return response()->json([
                'message' => 'Only pending quick sale requests can be approved',
                'current_status' => $quickSale->status,
            ], 400);
        }

        // Prevent self-approval (commented out for now)
        // if ($quickSale->requested_by === $user->id) {
        //     return response()->json([
        //         'message' => 'You cannot approve your own quick sale request',
        //     ], 403);
        // }

        // When batch-scoped, re-check batch still has stock
        if ($quickSale->batch_id) {
            $batch = $quickSale->batch;
            if (! $batch || $batch->current_quantity <= 0) {
                return response()->json([
                    'message' => 'Batch has no remaining quantity; cannot approve quick sale',
                ], 400);
            }
        }

        // Check for overlapping quick sales (same product/branch and, when batch-scoped, same batch)
        $hasOverlap = QuickSale::hasOverlappingQuickSale(
            $quickSale->product_id,
            $quickSale->branch_id,
            $validated['start_time'],
            $validated['end_time'],
            null,
            $quickSale->batch_id
        );

        if ($hasOverlap) {
            return response()->json([
                'message' => 'Another quick sale is already scheduled for this product during the selected time period',
            ], 400);
        }

        // For fixed discount, verify it doesn't exceed product price
        if ($validated['discount_type'] === 'fixed') {
            $branchProduct = BranchProduct::where('product_id', $quickSale->product_id)
                ->where('branch_id', $quickSale->branch_id)
                ->first();

            if ($branchProduct && $validated['discount_value'] >= $branchProduct->selling_price) {
                return response()->json([
                    'message' => 'Fixed discount amount cannot be greater than or equal to the product price',
                    'errors' => ['discount_value' => ['Must be less than product price']],
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            $quickSale->markAsApproved(
                $user->id,
                $validated['discount_type'],
                $validated['discount_value'],
                $validated['start_time'],
                $validated['end_time']
            );

            // If start time is now or in the past, activate immediately
            if (Carbon::parse($validated['start_time'])->lte(now())) {
                $quickSale->markAsActive();
            }

            DB::commit();

            return response()->json([
                'message' => 'Quick sale approved successfully',
                'quick_sale' => $quickSale->fresh([
                    'product',
                    'branch',
                    'batch',
                    'requestedBy',
                    'approvedBy',
                ]),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to approve quick sale',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject a quick sale request
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('approve quick sale')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|min:10|max:1000',
        ]);

        $quickSale = QuickSale::forBusiness($businessId)->findOrFail($id);

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $quickSale->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        // Validate request is pending
        if (! $quickSale->isPending()) {
            return response()->json([
                'message' => 'Only pending quick sale requests can be rejected',
                'current_status' => $quickSale->status,
            ], 400);
        }

        // Prevent self-rejection (commented out for now)
        // if ($quickSale->requested_by === $user->id) {
        //     return response()->json([
        //         'message' => 'You cannot reject your own quick sale request',
        //     ], 403);
        // }

        DB::beginTransaction();
        try {
            $quickSale->markAsRejected($user->id, $validated['rejection_reason']);

            DB::commit();

            return response()->json([
                'message' => 'Quick sale request rejected',
                'quick_sale' => $quickSale->fresh([
                    'product',
                    'branch',
                    'requestedBy',
                    'approvedBy',
                ]),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to reject quick sale request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * End an active quick sale before scheduled end time
     */
    public function end(Request $request, $id)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('approve quick sale')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $quickSale = QuickSale::forBusiness($businessId)->findOrFail($id);

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $quickSale->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        // Validate quick sale is active or approved
        if (! $quickSale->isActive() && ! $quickSale->isApproved()) {
            return response()->json([
                'message' => 'Only active or approved quick sales can be ended',
                'current_status' => $quickSale->status,
            ], 400);
        }

        DB::beginTransaction();
        try {
            $quickSale->markAsEnded($user->id);

            DB::commit();

            return response()->json([
                'message' => 'Quick sale ended successfully',
                'quick_sale' => $quickSale->fresh([
                    'product',
                    'branch',
                    'requestedBy',
                    'approvedBy',
                    'endedBy',
                ]),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to end quick sale',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
