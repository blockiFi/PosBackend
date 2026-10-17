<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\BranchProduct;
use App\Models\DeviceGroup;
use App\Models\InventoryTransaction;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\QuickSale;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesShift;
use App\Models\User;
use App\Services\InventoryBatchService;
use App\Services\TieredPricingService;
use App\Support\BusinessQuantityPolicy;
use App\Support\BusinessSettings;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Str;

class SaleController extends Controller
{
    use HasBranchAccess;

    private const DEPOSIT_STOCK_MODES = BusinessSettings::DEPOSIT_STOCK_MODES;

    private const DEFAULT_DEPOSIT_STOCK_MODE = BusinessSettings::DEFAULT_DEPOSIT_STOCK_MODE;

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

        $aggregateQuery = Sale::query()->forBusiness($businessId);
        if ($filterError = $this->applySaleIndexFilters($aggregateQuery, $request, $user, $businessId, $accessibleBranches)) {
            return $filterError;
        }

        $matchingGrossTotal = (float) $aggregateQuery->sum('total_amount');

        $query = Sale::with(['customer', 'user', 'branch', 'items.product'])
            ->forBusiness($businessId);
        if ($filterError = $this->applySaleIndexFilters($query, $request, $user, $businessId, $accessibleBranches)) {
            return $filterError;
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 15)));
        $sales = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $totalMatching = $sales->total();
        $avgAcrossMatching = $totalMatching > 0
            ? round($matchingGrossTotal / $totalMatching, 4)
            : 0.0;

        return response()->json(array_merge(
            $sales->toArray(),
            [
                'matching_gross_total' => $matchingGrossTotal,
                'summary' => [
                    'gross_total' => $matchingGrossTotal,
                    'avg_sale' => $avgAcrossMatching,
                ],
            ],
        ));
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
            'sale_type' => 'nullable|in:pos,online,delivery,wholesale,deposit',
            'reference_id' => 'nullable|string|max:255',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => BusinessQuantityPolicy::saleQuantityRules($business),
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

        $saleType = $validated['sale_type'] ?? 'pos';
        $isDeposit = $saleType === 'deposit';
        $depositStockMode = $isDeposit
            ? $this->resolveDepositStockMode($business)
            : null;
        $deferStockToCompletion = $isDeposit && $depositStockMode === 'deduct_on_complete';

        if ($isDeposit && empty($validated['customer_id'])) {
            return response()->json(['message' => 'A customer is required for deposit sales'], 422);
        }

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $validated['branch_id'])) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        // Get or validate shift
        $shiftId = null;
        $shift = null;
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
                $shift = $currentShift;
            }
        }

        DB::beginTransaction();
        try {
            // Generate sale number (DEP- prefix when sale_type=deposit, otherwise SAL-)
            $saleNumber = $this->generateSaleNumber($businessId, $saleType);

            // Create sale
            $saleMetadata = [];
            if ($isDeposit) {
                // Stamp the resolved stock mode so future top-ups / completion / cancel
                // behave consistently even if the business setting changes.
                $saleMetadata['deposit_stock_mode'] = $depositStockMode;
            }

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
                'sale_type' => $saleType,
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'metadata' => $saleMetadata ?: null,
            ]);

            // Create sale items
            foreach ($validated['items'] as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);
                $branchId = $validated['branch_id'];
                $qty = BusinessQuantityPolicy::normalizeForBusiness($business, (float) $itemData['quantity']);

                $branchProduct = BranchProduct::where('branch_id', $branchId)
                    ->where('product_id', $product->id)
                    ->first();

                if (! $branchProduct) {
                    throw new \Exception("Product not stocked at this branch: {$product->name}");
                }

                if (! $deferStockToCompletion && $branchProduct->availableForSale() < $qty) {
                    throw new \Exception("Insufficient stock for product: {$product->name}");
                }

                $batch = null;
                $batchId = null;

                if (! $deferStockToCompletion) {
                    // Prefer quick sale batch when an active quick sale with a batch exists for this product/branch
                    $quickSale = QuickSale::getActiveQuickSaleForProduct($product->id, $branchId);
                    if ($quickSale && $quickSale->batch_id) {
                        $batch = $quickSale->batch;
                        if ($batch && (float) $batch->current_quantity >= $qty) {
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
                        if (! $batch || (float) $batch->current_quantity < $qty) {
                            throw new \Exception("Invalid or insufficient batch quantity for product: {$product->name}");
                        }
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

                if ($deferStockToCompletion) {
                    // Deposit in deduct_on_complete mode: stock stays on the shelf until handover.
                    continue;
                }

                $deductResult = $branchProduct->deductForSale($qty);
                if (! $deductResult['stock_tracked']) {
                    $branchProduct->decrement('stock_quantity', $qty);
                    $deductResult['quantity_before'] = (float) $branchProduct->stock_quantity + $qty;
                    $deductResult['quantity_after'] = (float) $branchProduct->stock_quantity;
                }

                if ($batchId && $batch) {
                    $batch->allocate($qty);
                }

                $invPayload = [
                    'uuid' => Str::uuid(),
                    'business_id' => $businessId,
                    'branch_id' => $branchId,
                    'product_id' => $product->id,
                    'user_id' => $user->id,
                    'type' => 'sale',
                    'quantity' => -$qty,
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

                if (! $batchId && Quantity::isPositive($qty)) {
                    $this->batchService->allocateStockOut(
                        $product->id,
                        $branchId,
                        $qty,
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
                        'shift_id' => $shiftId,
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

            // Auto-flip to completed only for non-deposit sales. Deposits require an
            // explicit completeDeposit() call so the cashier controls handover.
            if (! $isDeposit && $sale->isFullyPaid()) {
                $sale->status = 'completed';
                $sale->save();
            }
            $amountPaid = $sale->payments->sum('amount');
            $sale->paid_amount = $amountPaid;
            $sale->save();
            DB::commit();

            return response()->json([
                'message' => $isDeposit ? 'Deposit sale created successfully' : 'Sale created successfully',
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
        // Cashiers (create sales permission) can only add payments to their own pending sales,
        // or to any pending deposit: customers often come back on another cashier's shift.
        $isDepositSale = $sale->sale_type === 'deposit';
        if (! $isOwner && ! $canManageSales) {
            if (! $canCreateSales || $sale->status !== 'pending' || (! $isOwnSale && ! $isDepositSale)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        $paymentShiftId = $this->resolveCurrentShiftIdForPayment($user, $businessId, $sale->branch_id);
        if ($paymentShiftId === null) {
            return response()->json([
                'message' => 'No open shift for this cashier on the sale\'s branch. Open a shift before recording a payment.',
            ], 400);
        }

        DB::beginTransaction();
        try {
            $payment = Payment::create([
                'sale_id' => $sale->id,
                'shift_id' => $paymentShiftId,
                'payment_method_id' => $validated['payment_method_id'],
                'amount' => $validated['amount'],
                'reference_number' => $validated['reference_number'] ?? null,
                'payment_date' => now(),
                'status' => 'completed',
                'notes' => $validated['notes'] ?? null,
            ]);

            $sale->updatePaymentStatus();

            // Auto-flip to completed only for non-deposit sales. Deposits require an
            // explicit completeDeposit() call so the cashier controls handover.
            $isDeposit = $sale->sale_type === 'deposit';
            if (! $isDeposit && $sale->isFullyPaid() && $sale->status === 'pending') {
                $sale->status = 'completed';
                $sale->save();
            }
            $amountPaid = $sale->payments->sum('amount');
            $sale->paid_amount = $amountPaid;
            $sale->save();
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
     * Complete a deposit sale: optionally collect a final payment, deduct stock if
     * the deposit was opened in deduct_on_complete mode, then flip status to completed.
     */
    public function completeDeposit(Request $request, $id)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        $validated = $request->validate([
            'payments' => 'nullable|array',
            'payments.*.payment_method_id' => 'required|exists:payment_methods,id',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.reference_number' => 'nullable|string',
            'payments.*.notes' => 'nullable|string',
            'closing_notes' => 'nullable|string',
            // Method the change is given back with when the customer paid more than the price
            'change_payment_method_id' => 'nullable|exists:payment_methods,id',
        ]);

        $sale = Sale::with(['items.product'])
            ->forBusiness($businessId)
            ->findOrFail($id);

        if (! $this->userHasBranchAccess($user, $businessId, $sale->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        $isOwner = $business->owner_id === $user->id;
        $canManageSales = $user->hasPermissionTo('manage sales');
        $canCreateSales = $user->hasPermissionTo('create sales');

        // Any cashier on the branch may finish a deposit, not only the one who took it
        if (! $isOwner && ! $canManageSales && ! $canCreateSales) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($sale->sale_type !== 'deposit') {
            return response()->json(['message' => 'Only deposit sales can be completed via this endpoint'], 422);
        }

        if ($sale->status !== 'pending') {
            return response()->json(['message' => 'Deposit is not pending; cannot complete'], 422);
        }

        if ($sale->items->isEmpty()) {
            return response()->json(['message' => 'Choose an item for this deposit first'], 422);
        }

        $paymentShiftId = null;
        if (! empty($validated['payments']) || ! empty($validated['change_payment_method_id'])) {
            $paymentShiftId = $this->resolveCurrentShiftIdForPayment($user, $businessId, $sale->branch_id);
            if ($paymentShiftId === null) {
                return response()->json([
                    'message' => 'No open shift for this cashier on the sale\'s branch. Open a shift before recording a payment.',
                ], 400);
            }
        }

        $depositMode = $this->resolveDepositStockMode($business, $sale);
        $deferred = $depositMode === 'deduct_on_complete';

        DB::beginTransaction();
        try {
            // Apply final payments (if any) before checking the balance.
            if (! empty($validated['payments'])) {
                foreach ($validated['payments'] as $paymentData) {
                    Payment::create([
                        'sale_id' => $sale->id,
                        'shift_id' => $paymentShiftId,
                        'payment_method_id' => $paymentData['payment_method_id'],
                        'amount' => $paymentData['amount'],
                        'reference_number' => $paymentData['reference_number'] ?? null,
                        'payment_date' => now(),
                        'status' => 'completed',
                        'notes' => $paymentData['notes'] ?? null,
                    ]);
                }
                $sale->updatePaymentStatus();
                $sale->refresh();
                $amountPaid = $sale->payments->sum('amount');
                $sale->paid_amount = $amountPaid;
                $sale->save();
            }

            // The item may cost less than the customer has paid: give the difference back
            // from this shift's drawer so the sale settles at the item's price.
            $sale->updatePaymentStatus();
            $changeDue = round((float) $sale->paid_amount - (float) $sale->total_amount, 2);
            $changeGiven = 0.0;
            if ($changeDue > 0) {
                if (empty($validated['change_payment_method_id']) || $paymentShiftId === null) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'The customer paid more than the price. Give back the difference.',
                        'change_due' => $changeDue,
                    ], 422);
                }

                Payment::create([
                    'sale_id' => $sale->id,
                    'shift_id' => $paymentShiftId,
                    'payment_method_id' => $validated['change_payment_method_id'],
                    'amount' => -$changeDue,
                    'reference_number' => $sale->sale_number,
                    'payment_date' => now(),
                    'status' => 'completed',
                    'notes' => 'Change given back',
                    'metadata' => ['kind' => 'change_given'],
                ]);
                $sale->updatePaymentStatus();
                $changeGiven = $changeDue;
            }

            if (! $sale->isFullyPaid()) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Deposit is not fully paid; cannot complete',
                    'paid_amount' => (float) $sale->paid_amount,
                    'total_amount' => (float) $sale->total_amount,
                    'balance' => (float) $sale->balance,
                ], 422);
            }

            // If stock was deferred, deduct it now (with availability check).
            if ($deferred) {
                foreach ($sale->items as $item) {
                    $branchProduct = BranchProduct::where('branch_id', $sale->branch_id)
                        ->where('product_id', $item->product_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $branchProduct) {
                        DB::rollBack();

                        return response()->json([
                            'message' => "Product no longer stocked at this branch: {$item->product_name}",
                        ], 409);
                    }

                    $qty = Quantity::normalize((float) $item->quantity);
                    if ($branchProduct->availableForSale() < $qty) {
                        DB::rollBack();

                        return response()->json([
                            'message' => "Insufficient stock for product: {$item->product_name}",
                            'product_id' => $item->product_id,
                            'available' => $branchProduct->availableForSale(),
                            'required' => $qty,
                        ], 409);
                    }

                    $deductResult = $branchProduct->deductForSale($qty);
                    if (! $deductResult['stock_tracked']) {
                        $branchProduct->decrement('stock_quantity', $qty);
                        $deductResult['quantity_before'] = (float) $branchProduct->stock_quantity + $qty;
                        $deductResult['quantity_after'] = (float) $branchProduct->stock_quantity;
                    }

                    $invPayload = [
                        'uuid' => Str::uuid(),
                        'business_id' => $businessId,
                        'branch_id' => $sale->branch_id,
                        'product_id' => $item->product_id,
                        'user_id' => $user->id,
                        'type' => 'sale',
                        'quantity' => -$qty,
                        'quantity_before' => $deductResult['quantity_before'],
                        'quantity_after' => $deductResult['quantity_after'],
                        'unit_cost' => $branchProduct->cost_price,
                        'total_cost' => $branchProduct->cost_price ? $branchProduct->cost_price * $qty : null,
                        'reference_number' => $sale->sale_number,
                        'notes' => "Deposit completed: {$sale->sale_number}",
                    ];
                    if ($deductResult['stock_tracked']) {
                        $invPayload['shelf_quantity'] = -$deductResult['from_shelf'];
                        $invPayload['store_quantity'] = -$deductResult['from_store'];
                        $invPayload['shelf_quantity_before'] = $deductResult['shelf_quantity_before'];
                        $invPayload['store_quantity_before'] = $deductResult['store_quantity_before'];
                        $invPayload['shelf_quantity_after'] = $deductResult['shelf_quantity_after'];
                        $invPayload['store_quantity_after'] = $deductResult['store_quantity_after'];
                    }

                    $invTransaction = InventoryTransaction::create($invPayload);

                    if (Quantity::isPositive($qty)) {
                        $this->batchService->allocateStockOut(
                            $item->product_id,
                            $sale->branch_id,
                            $qty,
                            $invTransaction,
                            ['reference_number' => $sale->sale_number, 'notes' => "Deposit completed: {$sale->sale_number}"],
                            false
                        );
                    }
                }
            }

            $sale->status = 'completed';
            if (! empty($validated['closing_notes'])) {
                $existingNotes = trim((string) $sale->notes);
                $closing = 'Deposit completed: '.$validated['closing_notes'];
                $sale->notes = $existingNotes === '' ? $closing : ($existingNotes."\n".$closing);
            }
            $sale->save();

            DB::commit();

            return response()->json([
                'message' => 'Deposit completed successfully',
                'sale' => $sale->fresh(['items.product', 'payments.paymentMethod', 'customer', 'branch', 'user']),
                'change_given' => $changeGiven,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Failed to complete deposit', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Find open deposits by reference, customer phone or customer name.
     * Used by the POS "Find Deposit" screen so a customer who lost the reference can be found.
     */
    public function searchDeposits(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view sales') && ! $user->hasPermissionTo('create sales')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'q' => 'required|string|min:2|max:100',
            'branch_id' => 'nullable|integer',
        ]);

        $term = trim($validated['q']);
        $digits = preg_replace('/\D+/', '', $term);
        // Match the end of the number so "0803..." and "+234803..." find the same customer
        $phoneTail = strlen($digits) >= 7 ? substr($digits, -10) : null;

        $query = Sale::with(['items.product', 'payments.paymentMethod', 'customer', 'branch', 'user'])
            ->forBusiness($businessId)
            ->where('sale_type', 'deposit')
            ->where('status', 'pending')
            ->where(function ($q) use ($term, $phoneTail) {
                $q->where('sale_number', $term)
                    ->orWhere('reference_id', $term)
                    // The last digits printed on the deposit slip are enough
                    ->when(strlen($term) >= 4, fn ($q) => $q->orWhere('sale_number', 'like', '%'.addcslashes($term, '%_')))
                    ->orWhereHas('customer', function ($customer) use ($term, $phoneTail) {
                        $customer->where('name', 'like', '%'.$term.'%');
                        if ($phoneTail !== null) {
                            // Ignore spaces, dashes and "+" people type into phone numbers
                            $customer->orWhereRaw(
                                "REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE ?",
                                ['%'.$phoneTail.'%']
                            );
                        }
                    });
            });

        if (! empty($validated['branch_id'])) {
            $query->where('branch_id', $validated['branch_id']);
        }

        $deposits = $query->orderByDesc('id')
            ->limit(20)
            ->get()
            ->filter(fn (Sale $sale) => $this->userHasBranchAccess($user, (int) $businessId, (int) $sale->branch_id))
            ->values();

        return response()->json(['deposits' => $deposits]);
    }

    /**
     * Choose (or change) the item(s) on a pending deposit. Prices come from the branch's
     * current price, so the price is fixed when the item is chosen. Every change is logged
     * on the sale's metadata.
     */
    public function setDepositItems(Request $request, $id)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => BusinessQuantityPolicy::saleQuantityRules($business),
            'items.*.description' => 'nullable|string',
        ]);

        $sale = Sale::with(['items'])
            ->forBusiness($businessId)
            ->findOrFail($id);

        if (! $this->userHasBranchAccess($user, $businessId, $sale->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        if ($business->owner_id !== $user->id
            && ! $user->hasPermissionTo('manage sales')
            && ! $user->hasPermissionTo('create sales')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($sale->sale_type !== 'deposit') {
            return response()->json(['message' => 'Only deposits can have their item chosen later'], 422);
        }

        if ($sale->status !== 'pending') {
            return response()->json(['message' => 'This deposit is already finished'], 422);
        }

        // A deposit that reserved stock when it was taken would need that stock moved
        // between items; only deposits that take stock on completion can change items.
        if ($this->resolveDepositStockMode($business, $sale) !== 'deduct_on_complete') {
            return response()->json(['message' => 'This deposit is holding its stock, so its item cannot be changed'], 422);
        }

        $branchId = (int) $sale->branch_id;

        DB::beginTransaction();
        try {
            $before = $this->depositItemsSummary($sale);

            $sale->items()->delete();

            foreach ($validated['items'] as $itemData) {
                $productId = (int) $itemData['product_id'];
                $qty = BusinessQuantityPolicy::normalizeForBusiness((int) $businessId, (float) $itemData['quantity']);

                $branchProduct = BranchProduct::with('product')
                    ->where('branch_id', $branchId)
                    ->where('product_id', $productId)
                    ->first();
                $product = $branchProduct?->product;

                if (! $branchProduct || ! $product || (int) $product->business_id !== (int) $businessId) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'This item is not sold at this branch',
                        'product_id' => $productId,
                    ], 422);
                }

                $tierResult = $this->tieredPricingService->getUnitPrice($branchProduct, $qty);
                $metadata = ['tier_type' => $tierResult['tier_type']];
                if ($tierResult['product_unit_id'] !== null) {
                    $metadata['product_unit_id'] = $tierResult['product_unit_id'];
                }
                if ($tierResult['quantity_tier_id'] !== null) {
                    $metadata['quantity_tier_id'] = $tierResult['quantity_tier_id'];
                }

                $saleItem = new SaleItem([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'description' => $itemData['description'] ?? null,
                    'quantity' => $qty,
                    'unit_price' => $tierResult['unit_price'],
                    'discount_percentage' => 0,
                    'tax_rate' => 0,
                    'metadata' => $metadata,
                ]);
                $saleItem->calculateTotals();
                $sale->items()->save($saleItem);
            }

            $sale->load('items');
            // A discount given on the earlier item does not carry over to the new one
            $sale->discount_amount = 0;
            $sale->calculateTotals();

            $after = $this->depositItemsSummary($sale);
            $metadata = is_array($sale->metadata) ? $sale->metadata : [];
            $metadata['item_changes'] = array_values(array_merge($metadata['item_changes'] ?? [], [[
                'at' => now()->toIso8601String(),
                'user_id' => $user->id,
                'user_name' => $user->name,
                'from' => $before['items'],
                'from_total' => $before['total'],
                'to' => $after['items'],
                'to_total' => $after['total'],
            ]]));
            $sale->metadata = $metadata;
            $sale->save();
            $sale->updatePaymentStatus();

            DB::commit();

            return response()->json([
                'message' => 'Deposit item saved',
                'sale' => $sale->fresh(['items.product', 'payments.paymentMethod', 'customer', 'branch', 'user']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Failed to save the deposit item', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Cancel a pending deposit and give the customer all their money back from the acting
     * cashier's drawer. Needs a supervisor, manager or owner PIN. The earlier payments stay
     * on the shifts that took them; the refund is a negative payment on the current shift.
     */
    public function cancelDeposit(Request $request, $id)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        $validated = $request->validate([
            'approver_pin' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
            'payment_method_id' => 'nullable|exists:payment_methods,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $sale = Sale::with(['items'])
            ->forBusiness($businessId)
            ->findOrFail($id);

        if (! $this->userHasBranchAccess($user, $businessId, $sale->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        if ($business->owner_id !== $user->id
            && ! $user->hasPermissionTo('manage sales')
            && ! $user->hasPermissionTo('create sales')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($sale->sale_type !== 'deposit' || $sale->status !== 'pending') {
            return response()->json(['message' => 'Only an open deposit can be cancelled'], 422);
        }

        $approver = $this->findDepositApprover($business, $validated['approver_pin']);
        if (! $approver) {
            return response()->json(['message' => 'Wrong PIN. A supervisor must approve this.'], 403);
        }

        $sale->updatePaymentStatus();
        $paid = round((float) $sale->paid_amount, 2);

        $shiftId = null;
        if ($paid > 0) {
            if (empty($validated['payment_method_id'])) {
                return response()->json(['message' => 'Choose how the money is given back'], 422);
            }
            $shiftId = $this->resolveCurrentShiftIdForPayment($user, $businessId, $sale->branch_id);
            if ($shiftId === null) {
                return response()->json([
                    'message' => 'No open shift for this cashier on the sale\'s branch. Open a shift before giving money back.',
                ], 400);
            }
        }

        $depositMode = $this->resolveDepositStockMode($business, $sale);

        DB::beginTransaction();
        try {
            if ($depositMode !== 'deduct_on_complete' && $sale->items->isNotEmpty()) {
                $this->restoreSaleStock($sale, (int) $businessId, $user->id, "Deposit cancelled: {$sale->sale_number}");
            }

            if ($paid > 0) {
                Payment::create([
                    'sale_id' => $sale->id,
                    'shift_id' => $shiftId,
                    'payment_method_id' => $validated['payment_method_id'],
                    'amount' => -$paid,
                    'reference_number' => $sale->sale_number,
                    'payment_date' => now(),
                    'status' => 'completed',
                    'notes' => 'Money given back: deposit cancelled',
                    'metadata' => ['kind' => 'deposit_refund', 'approved_by' => $approver->id],
                ]);
            }

            $metadata = is_array($sale->metadata) ? $sale->metadata : [];
            $metadata['cancelled'] = [
                'at' => now()->toIso8601String(),
                'user_id' => $user->id,
                'user_name' => $user->name,
                'approved_by' => $approver->id,
                'approved_by_name' => $approver->name,
                'reason' => $validated['reason'] ?? null,
                'money_given_back' => $paid,
            ];
            $sale->metadata = $metadata;
            // "refunded" (not "cancelled") keeps the earlier payments on the shifts that took
            // them; the negative payment above takes the money out of this shift.
            $sale->status = $paid > 0 ? 'refunded' : 'cancelled';
            $sale->is_refunded = $paid > 0;
            $sale->refunded_amount = $paid;
            $sale->refunded_at = $paid > 0 ? now() : null;
            $sale->save();
            $sale->updatePaymentStatus();

            DB::commit();

            return response()->json([
                'message' => 'Deposit cancelled',
                'sale' => $sale->fresh(['items.product', 'payments.paymentMethod', 'customer', 'branch', 'user']),
                'money_given_back' => $paid,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Failed to cancel deposit', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Look up a sale by its sale_number or reference_id within the current business.
     * Used by the POS "Recall deposit" flow.
     */
    public function findByReference(Request $request, string $reference)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view sales') && ! $user->hasPermissionTo('create sales')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $sale = Sale::with([
            'items.product',
            'payments.paymentMethod',
            'payments.shift',
            'customer',
            'branch',
            'user',
            'shift',
        ])
            ->forBusiness($businessId)
            ->where(function ($q) use ($reference) {
                $q->where('sale_number', $reference)
                    ->orWhere('reference_id', $reference);
            })
            ->first();

        if (! $sale) {
            return response()->json(['message' => 'Sale not found for this reference'], 404);
        }

        if (! $this->userHasBranchAccess($user, $businessId, $sale->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }
        $amountPaid = $sale->payments->sum('amount');
        $sale->paid_amount = $amountPaid;

        return response()->json(['sale' => $sale]);
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

        // A deposit opened in deduct_on_complete mode that never reached `completed`
        // never had stock deducted, so there's nothing to restore. Cancelling a
        // completed deposit (or a deposit in reserve_on_create mode) still restores stock.
        $isDeposit = $sale->sale_type === 'deposit';
        $depositMode = $isDeposit ? $this->resolveDepositStockMode($business, $sale) : null;
        $skipStockRestore = $isDeposit
            && $depositMode === 'deduct_on_complete'
            && $sale->status !== 'completed';

        DB::beginTransaction();
        try {
            if ($skipStockRestore) {
                $sale->status = 'cancelled';
                $sale->save();

                DB::commit();

                return response()->json([
                    'message' => 'Sale cancelled successfully',
                    'sale' => $sale->fresh(),
                ]);
            }

            $this->restoreSaleStock($sale, $businessId, $user->id, "Sale cancelled: {$sale->sale_number}");

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
     * Apply the same filters as the sales index list (branch access, query params).
     *
     * @param  Builder<Sale>  $query
     * @param  \Illuminate\Support\Collection<int, mixed>  $accessibleBranches
     */
    private function applySaleIndexFilters(
        Builder $query,
        Request $request,
        $user,
        mixed $businessId,
        $accessibleBranches,
    ): ?\Illuminate\Http\JsonResponse {
        if ($accessibleBranches->isNotEmpty()) {
            $query->whereIn('branch_id', $accessibleBranches->pluck('id'));
        }

        if ($request->filled('branch_id')) {
            $branchId = $request->branch_id;
            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json(['message' => 'Unauthorized access to this branch'], 403);
            }
            $query->where('branch_id', $branchId);
        }

        if ($request->filled('group_id')) {
            $groupId = $request->group_id;
            $groupOk = DeviceGroup::query()
                ->whereKey($groupId)
                ->where('business_id', $businessId)
                ->exists();
            if (! $groupOk) {
                return response()->json(['message' => 'Invalid or inaccessible device group'], 403);
            }
            $query->whereHas('shift', function ($q) use ($groupId, $businessId) {
                $q->withTrashed()
                    ->where('business_id', $businessId)
                    ->where('group_id', $groupId);
            });
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('status')) {
            // Accepts a comma-separated list, e.g. "cancelled,refunded"
            $query->whereIn('status', array_filter(explode(',', (string) $request->status)));
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

        return null;
    }

    /**
     * Generate a unique sale number, prefixed by sale type. Sequencing is per-prefix
     * so DEP- and SAL- numbers grow independently and never collide.
     */
    private function generateSaleNumber($businessId, string $type = 'sale'): string
    {
        $prefix = $type === 'deposit' ? 'DEP' : 'SAL';
        $date = now()->format('Ymd');

        $lastSale = Sale::forBusiness($businessId)
            ->whereDate('created_at', now())
            ->where('sale_number', 'like', $prefix.'-%')
            ->orderBy('id', 'desc')
            ->first();

        $sequence = $lastSale ? (intval(substr($lastSale->sale_number, -4)) + 1) : 1;

        return sprintf('%s-%s-%04d', $prefix, $date, $sequence);
    }

    /**
     * Resolve the deposit stock mode. Prefers the value stamped on the sale's
     * metadata (so a mid-deposit setting toggle doesn't change behaviour for
     * an already-open deposit) and falls back to the business setting.
     */
    private function resolveDepositStockMode($business, ?Sale $sale = null): string
    {
        if ($sale) {
            $meta = is_array($sale->metadata) ? $sale->metadata : [];
            $stamped = $meta['deposit_stock_mode'] ?? null;
            if (is_string($stamped) && in_array($stamped, self::DEPOSIT_STOCK_MODES, true)) {
                return $stamped;
            }
        }

        $settings = is_array($business->settings ?? null) ? $business->settings : [];
        $mode = $settings['deposit_stock_mode'] ?? null;
        if (is_string($mode) && in_array($mode, self::DEPOSIT_STOCK_MODES, true)) {
            return $mode;
        }

        return self::DEFAULT_DEPOSIT_STOCK_MODE;
    }

    /**
     * Put a sale's items back into branch stock (used when a sale that took stock is cancelled).
     */
    private function restoreSaleStock(Sale $sale, int $businessId, int $userId, string $note): void
    {
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
                    'user_id' => $userId,
                    'type' => 'adjustment',
                    'quantity' => $item->quantity,
                    'quantity_before' => $branchProduct->stock_quantity - $item->quantity,
                    'quantity_after' => $branchProduct->stock_quantity,
                    'unit_cost' => $branchProduct->cost_price,
                    'total_cost' => $branchProduct->cost_price * $item->quantity,
                    'reference_number' => $sale->sale_number,
                    'notes' => $note,
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
    }

    /**
     * @return array{items: list<array{name: string, quantity: float}>, total: float}
     */
    private function depositItemsSummary(Sale $sale): array
    {
        return [
            'items' => $sale->items->map(fn (SaleItem $item) => [
                'name' => $item->product_name,
                'quantity' => (float) $item->quantity,
            ])->values()->all(),
            'total' => (float) $sale->items->sum('total'),
        ];
    }

    /**
     * The owner, or a member of the business who can manage sales or shifts (supervisor or
     * manager), identified by their 6-digit PIN.
     */
    private function findDepositApprover($business, string $pin): ?User
    {
        $approver = User::where('pin_code', $pin)->first();
        if (! $approver) {
            return null;
        }

        if ((int) $business->owner_id === (int) $approver->id) {
            return $approver;
        }

        $isMember = $approver->businesses()
            ->where('businesses.id', $business->id)
            ->wherePivot('is_active', true)
            ->exists();
        if (! $isMember) {
            return null;
        }

        setPermissionsTeamId($business->id);

        // Supervisor-level permissions; a plain cashier has none of these.
        foreach (['approve refund', 'manage sales', 'manage shifts', 'view all shifts'] as $permission) {
            if ($approver->checkPermissionTo($permission)) {
                return $approver;
            }
        }

        return null;
    }

    /**
     * Find the open shift for the acting cashier on the given branch. Used to stamp
     * payments with the shift in which the cash was actually received (cash-basis
     * shift accounting).
     */
    private function resolveCurrentShiftIdForPayment($user, $businessId, $branchId): ?int
    {
        $shift = SalesShift::forBusiness($businessId)
            ->where('user_id', $user->id)
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->first();

        return $shift?->id;
    }
}
