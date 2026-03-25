<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\BranchProduct;
use App\Models\InventoryTransaction;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\QuickSale;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesShift;
use App\Services\InventoryBatchService;
use App\Services\TieredPricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Str;

class SaleController extends Controller
{
    use HasBranchAccess;

    public function __construct(
        protected InventoryBatchService $batchService,
        protected TieredPricingService $tieredPricingService
    ) {}

    /**
     * List sales with filtering and pagination
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

        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view sales')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Get accessible branches
        $accessibleBranches = $user->getBranchesInBusiness($businessId);

        $query = Sale::with(['customer', 'user', 'branch', 'items.product'])
            ->forBusiness($businessId);

        // Filter by accessible branches
        if ($accessibleBranches->isNotEmpty()) {
            $query->whereIn('branch_id', $accessibleBranches->pluck('id'));
        }

        // Apply filters
        if ($request->filled('branch_id')) {
            $branchId = $request->branch_id;
            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json(['message' => 'Unauthorized access to this branch'], 403);
            }
            $query->where('branch_id', $branchId);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('sale_type')) {
            $query->where('sale_type', $request->sale_type);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->dateRange($request->start_date, $request->end_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('sale_number', 'like', '%'.$search.'%')
                    ->orWhere('reference_id', 'like', '%'.$search.'%');
            });
        }

        $sales = $query->orderBy('sale_date', 'desc')->paginate(15);

        return response()->json($sales);
    }

    /**
     * Create a new sale with items and payments
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

        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('create sales')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'customer_id' => 'nullable|exists:customers,id',
            'shift_id' => 'nullable|exists:sales_shifts,id',
            'sale_type' => 'nullable|in:pos,online,delivery,wholesale',
            'reference_id' => 'nullable|string|max:255',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'nullable|numeric|min:0', // optional: computed from tiers unless override permission
            'items.*.batch_id' => 'nullable|exists:product_batches,id',
            'items.*.description' => 'nullable|string',
            'items.*.discount_percentage' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'payments' => 'nullable|array',
            'payments.*.payment_method_id' => 'required|exists:payment_methods,id',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.reference_number' => 'nullable|string',
        ]);

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $validated['branch_id'])) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        // Get or validate shift
        $shiftId = null;
        if (isset($validated['shift_id'])) {
            // Validate provided shift
            $shift = SalesShift::forBusiness($businessId)
                ->where('id', $validated['shift_id'])
                ->where('status', 'open')
                ->first();

            if (! $shift) {
                return response()->json(['message' => 'Invalid or closed shift'], 400);
            }

            if ($shift->branch_id !== $validated['branch_id']) {
                return response()->json(['message' => 'Shift branch does not match sale branch'], 400);
            }

            $shiftId = $shift->id;
        } else {
            // Try to get current open shift for user in this branch
            $currentShift = SalesShift::forBusiness($businessId)
                ->where('user_id', $user->id)
                ->where('branch_id', $validated['branch_id'])
                ->where('status', 'open')
                ->first();

            if ($currentShift) {
                $shiftId = $currentShift->id;
            }
        }

        DB::beginTransaction();
        try {
            // Generate sale number
            $saleNumber = $this->generateSaleNumber($businessId);

            // Create sale
            $sale = Sale::create([
                'sale_number' => $saleNumber,
                'reference_id' => $validated['reference_id'] ?? null,
                'business_id' => $businessId,
                'branch_id' => $validated['branch_id'],
                'customer_id' => $validated['customer_id'] ?? null,
                'user_id' => $user->id,
                'shift_id' => $shiftId,
                'sale_date' => now(),
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'sale_type' => $validated['sale_type'] ?? 'pos',
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending',
                'payment_status' => 'unpaid',
            ]);

            // Create sale items
            foreach ($validated['items'] as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);
                $branchId = $validated['branch_id'];
                $qty = (float) $itemData['quantity'];

                $branchProduct = BranchProduct::where('branch_id', $branchId)
                    ->where('product_id', $product->id)
                    ->first();

                if (! $branchProduct || $branchProduct->stock_quantity < $qty) {
                    throw new \Exception("Insufficient stock for product: {$product->name}");
                }

                $batch = null;
                $batchId = null;
                $qtyForBatch = (int) round($qty);

                // Prefer quick sale batch when an active quick sale with a batch exists for this product/branch
                $quickSale = QuickSale::getActiveQuickSaleForProduct($product->id, $branchId);
                if ($quickSale && $quickSale->batch_id) {
                    $batch = $quickSale->batch;
                    if ($batch && $batch->current_quantity >= $qtyForBatch) {
                        $batchId = $batch->id;
                    }
                }

                // Else use client-provided batch_id if present
                if ($batchId === null && isset($itemData['batch_id']) && $itemData['batch_id'] !== null) {
                    $batchId = (int) $itemData['batch_id'];
                    $batch = ProductBatch::where('id', $batchId)
                        ->where('product_id', $product->id)
                        ->where('branch_id', $branchId)
                        ->where('business_id', $businessId)
                        ->first();
                    if (! $batch || $batch->current_quantity < $qtyForBatch) {
                        throw new \Exception("Invalid or insufficient batch quantity for product: {$product->name}");
                    }
                }

                $unitPrice = isset($itemData['unit_price']) ? (float) $itemData['unit_price'] : null;
                $metadata = [];

                // Compute expected price based on tiered pricing (product unit/quantity tiers)
                $tierResult = $this->tieredPricingService->getUnitPrice($branchProduct, $qty);
                $expectedUnitPrice = $tierResult['unit_price'];

                if ($unitPrice !== null) {
                    // If client-provided price equals expected price, it's not an override
                    if (abs($unitPrice - $expectedUnitPrice) < 0.00001) {
                        $unitPrice = $expectedUnitPrice;
                        $metadata['tier_type'] = $tierResult['tier_type'];
                        if ($tierResult['product_unit_id'] !== null) {
                            $metadata['product_unit_id'] = $tierResult['product_unit_id'];
                        }
                        if ($tierResult['quantity_tier_id'] !== null) {
                            $metadata['quantity_tier_id'] = $tierResult['quantity_tier_id'];
                        }
                    } else {
                        // Price differs: treat as override and require permission
                        if ($user->hasPermissionTo('override sale price')) {
                            $metadata['is_manual_override'] = true;
                            $metadata['tier_type'] = 'manual';
                        } else {
                            // No permission to override - fall back to expected price
                            $unitPrice = $expectedUnitPrice;
                            $metadata['tier_type'] = $tierResult['tier_type'];
                            if ($tierResult['product_unit_id'] !== null) {
                                $metadata['product_unit_id'] = $tierResult['product_unit_id'];
                            }
                            if ($tierResult['quantity_tier_id'] !== null) {
                                $metadata['quantity_tier_id'] = $tierResult['quantity_tier_id'];
                            }
                        }
                    }
                } else {
                    // No client price - use expected tier price
                    $unitPrice = $expectedUnitPrice;
                    $metadata['tier_type'] = $tierResult['tier_type'];
                    if ($tierResult['product_unit_id'] !== null) {
                        $metadata['product_unit_id'] = $tierResult['product_unit_id'];
                    }
                    if ($tierResult['quantity_tier_id'] !== null) {
                        $metadata['quantity_tier_id'] = $tierResult['quantity_tier_id'];
                    }
                }

                if ($batchId) {
                    $quickSaleForBatch = QuickSale::getActiveQuickSale($product->id, $branchId, null, $batchId);
                    if ($quickSaleForBatch) {
                        $originalPrice = $branchProduct->selling_price ?? $unitPrice;
                        $unitPrice = $quickSaleForBatch->calculateFinalPrice($originalPrice);
                    }
                }

                $item = new SaleItem([
                    'product_id' => $product->id,
                    'batch_id' => $batchId,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'description' => $itemData['description'] ?? null,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'discount_percentage' => $itemData['discount_percentage'] ?? 0,
                    'tax_rate' => $itemData['tax_rate'] ?? 0,
                    'metadata' => $metadata ?: null,
                ]);

                $item->calculateTotals();
                $sale->items()->save($item);

                $deductResult = $branchProduct->deductForSale($qtyForBatch);
                if (! $deductResult['stock_tracked']) {
                    $branchProduct->decrement('stock_quantity', $qtyForBatch);
                    $deductResult['quantity_before'] = $branchProduct->stock_quantity + $qtyForBatch;
                    $deductResult['quantity_after'] = $branchProduct->stock_quantity;
                }

                if ($batchId && $batch) {
                    $batch->allocate($qtyForBatch);
                }

                $invPayload = [
                    'uuid' => Str::uuid(),
                    'business_id' => $businessId,
                    'branch_id' => $branchId,
                    'product_id' => $product->id,
                    'user_id' => $user->id,
                    'type' => 'sale',
                    'quantity' => -$qtyForBatch,
                    'quantity_before' => $deductResult['quantity_before'],
                    'quantity_after' => $deductResult['quantity_after'],
                    'unit_cost' => $branchProduct->cost_price,
                    'total_cost' => $branchProduct->cost_price ? $branchProduct->cost_price * $qty : null,
                    'reference_number' => $saleNumber,
                    'notes' => "Sale: {$saleNumber}",
                ];
                if ($deductResult['stock_tracked']) {
                    $invPayload['shelf_quantity'] = -$deductResult['from_shelf'];
                    $invPayload['store_quantity'] = -$deductResult['from_store'];
                    $invPayload['shelf_quantity_before'] = $deductResult['shelf_quantity_before'];
                    $invPayload['store_quantity_before'] = $deductResult['store_quantity_before'];
                    $invPayload['shelf_quantity_after'] = $deductResult['shelf_quantity_after'];
                    $invPayload['store_quantity_after'] = $deductResult['store_quantity_after'];
                }
                if ($batchId) {
                    $invPayload['batch_id'] = $batchId;
                }
                $invTransaction = InventoryTransaction::create($invPayload);

                if (! $batchId && $qtyForBatch > 0) {
                    $this->batchService->allocateStockOut(
                        $product->id,
                        $branchId,
                        $qtyForBatch,
                        $invTransaction,
                        ['reference_number' => $saleNumber, 'notes' => "Sale: {$saleNumber}"],
                        false
                    );
                }
            }

            // Calculate totals
            $sale->calculateTotals();
            $sale->save();

            // Create payments if provided
            if (! empty($validated['payments'])) {
                foreach ($validated['payments'] as $paymentData) {
                    Payment::create([
                        'sale_id' => $sale->id,
                        'payment_method_id' => $paymentData['payment_method_id'],
                        'amount' => $paymentData['amount'],
                        'reference_number' => $paymentData['reference_number'] ?? null,
                        'payment_date' => now(),
                        'status' => 'completed',
                    ]);
                }
                $sale->updatePaymentStatus();
                $sale->refresh(); // Refresh to get updated payment_status
            }

            // Update sale status if fully paid
            if ($sale->isFullyPaid()) {
                $sale->status = 'completed';
                $sale->save();
            }

            DB::commit();

            return response()->json([
                'message' => 'Sale created successfully',
                'sale' => $sale->load(['items.product', 'payments.paymentMethod', 'customer', 'branch']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Failed to create sale', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * View a specific sale
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

        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view sales')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $sale = Sale::with(['items.product', 'payments.paymentMethod', 'customer', 'branch', 'user'])
            ->forBusiness($businessId)
            ->findOrFail($id);

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $sale->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        return response()->json($sale);
    }

    /**
     * Add payment to a sale
     */
    public function addPayment(Request $request, $id)
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

        $validated = $request->validate([
            'payment_method_id' => 'required|exists:payment_methods,id',
            'amount' => 'required|numeric|min:0.01',
            'reference_number' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $sale = Sale::forBusiness($businessId)->findOrFail($id);

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $sale->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        $isOwner = $business->owner_id === $user->id;
        $canManageSales = $user->hasPermissionTo('manage sales');
        $canCreateSales = $user->hasPermissionTo('create sales');
        $isOwnSale = (int) $sale->user_id === (int) $user->id;

        // Managers/owners can add payments to any sale in accessible branches.
        // Cashiers (create sales permission) can only add payments to their own pending sales.
        if (! $isOwner && ! $canManageSales) {
            if (! $canCreateSales || ! $isOwnSale || $sale->status !== 'pending') {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        DB::beginTransaction();
        try {
            $payment = Payment::create([
                'sale_id' => $sale->id,
                'payment_method_id' => $validated['payment_method_id'],
                'amount' => $validated['amount'],
                'reference_number' => $validated['reference_number'] ?? null,
                'payment_date' => now(),
                'status' => 'completed',
                'notes' => $validated['notes'] ?? null,
            ]);

            $sale->updatePaymentStatus();

            // Update sale status if fully paid
            if ($sale->isFullyPaid() && $sale->status === 'pending') {
                $sale->status = 'completed';
                $sale->save();
            }

            DB::commit();

            return response()->json([
                'message' => 'Payment added successfully',
                'payment' => $payment->load('paymentMethod'),
                'sale' => $sale->fresh(['payments']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Failed to add payment', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Cancel a sale
     */
    public function cancel(Request $request, $id)
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

        $sale = Sale::forBusiness($businessId)->findOrFail($id);

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $sale->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        $isOwner = $business->owner_id === $user->id;
        $canManageSales = $user->hasPermissionTo('manage sales');
        $canCreateSales = $user->hasPermissionTo('create sales');
        $isOwnSale = (int) $sale->user_id === (int) $user->id;

        // Managers/owners can cancel any sale in accessible branches.
        // Cashiers (create sales permission) can only cancel their own pending sales.
        if (! $isOwner && ! $canManageSales) {
            if (! $canCreateSales || ! $isOwnSale || $sale->status !== 'pending') {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        if ($sale->status === 'cancelled') {
            return response()->json(['message' => 'Sale is already cancelled'], 400);
        }

        DB::beginTransaction();
        try {
            // Restore stock for each item
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
                        'notes' => "Sale cancelled: {$sale->sale_number}",
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

            $sale->status = 'cancelled';
            $sale->save();

            DB::commit();

            return response()->json([
                'message' => 'Sale cancelled successfully',
                'sale' => $sale->fresh(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Failed to cancel sale', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Generate unique sale number
     */
    private function generateSaleNumber($businessId): string
    {
        $prefix = 'SAL';
        $date = now()->format('Ymd');
        $lastSale = Sale::forBusiness($businessId)
            ->whereDate('created_at', now())
            ->orderBy('id', 'desc')
            ->first();

        $sequence = $lastSale ? (intval(substr($lastSale->sale_number, -4)) + 1) : 1;

        return sprintf('%s-%s-%04d', $prefix, $date, $sequence);
    }
}
