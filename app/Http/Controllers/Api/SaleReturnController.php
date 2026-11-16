<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ReturnRefused;
use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\InventoryTransaction;
use App\Models\ManagerActivity;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesShift;
use App\Models\StockWriteoff;
use App\Models\User;
use App\Services\InventoryBatchService;
use App\Services\TieredPricingService;
use App\Support\BusinessQuantityPolicy;
use App\Support\Quantity;
use App\Support\ReportingTimezone;
use App\Support\SaleReturns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Returns and swaps done by the cashier on the device, approved on the spot with a manager's
 * own PIN. See App\Support\SaleReturns for how a return is stored and counted.
 */
class SaleReturnController extends Controller
{
    use HasBranchAccess;

    /** Units are compared with this much slack (quantities are stored with 2 decimals). */
    private const EPSILON = 0.001;

    public function __construct(
        protected TieredPricingService $pricing,
        protected InventoryBatchService $batchService,
    ) {}

    /**
     * Past sales the cashier can take items back from: by receipt number, customer name or
     * phone, item name or amount, optionally on one day. With nothing typed, the latest sales.
     */
    public function searchSales(Request $request): JsonResponse
    {
        [$user, $business, $error] = $this->cashierContext($request);
        if ($error) {
            return $error;
        }

        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $branchId = (int) $validated['branch_id'];
        if (! $this->userHasBranchAccess($user, $business->id, $branchId)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        $q = trim((string) ($validated['q'] ?? ''));
        $amount = preg_replace('/[^0-9.]/', '', $q);
        $isAmount = $amount !== '' && is_numeric($amount) && preg_match('/^[\s₦N0-9,.]+$/u', $q);

        $query = Sale::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branchId)
            ->where('status', 'completed')
            ->where('sale_type', '!=', SaleReturns::TYPE)
            ->with(['items', 'customer', 'user:id,name', 'payments.paymentMethod'])
            ->orderByDesc('sale_date')
            ->orderByDesc('id');

        if (! empty($validated['date'])) {
            $query->whereBetween('sale_date', ReportingTimezone::utcBoundsForLocalDay(
                $validated['date'],
                ReportingTimezone::forBusiness($business->id)
            ));
        }

        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(function ($where) use ($like, $isAmount, $amount) {
                $where->where('sale_number', 'like', $like)
                    ->orWhere('reference_id', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)->orWhere('phone', 'like', $like))
                    ->orWhereHas('items', fn ($i) => $i->where('product_name', 'like', $like));
                if ($isAmount) {
                    $where->orWhere('total_amount', round((float) $amount, 2));
                }
            });
        }

        $sales = $query->limit(30)->get();

        return response()->json([
            'data' => $sales->map(fn (Sale $sale) => $this->presentSaleForReturn($sale))->values(),
        ]);
    }

    /** One sale with what can still come back. */
    public function showSale(Request $request, int $id): JsonResponse
    {
        [$user, $business, $error] = $this->cashierContext($request);
        if ($error) {
            return $error;
        }

        $sale = Sale::query()
            ->where('business_id', $business->id)
            ->with(['items', 'customer', 'user:id,name', 'payments.paymentMethod'])
            ->find($id);
        if (! $sale || ! $this->userHasBranchAccess($user, $business->id, (int) $sale->branch_id)) {
            return response()->json(['message' => 'Sale not found'], 404);
        }

        return response()->json(['data' => $this->presentSaleForReturn($sale)]);
    }

    /**
     * Checks a manager's PIN typed on the cashier's device, so the PIN box can say "Wrong PIN"
     * straight away. The return itself checks it again.
     */
    public function checkManagerPin(Request $request): JsonResponse
    {
        [$user, $business, $error] = $this->cashierContext($request);
        if ($error) {
            return $error;
        }

        $validated = $request->validate([
            'pin' => ['required', 'string', 'max:12'],
            'branch_id' => ['required', 'integer'],
        ]);

        [$manager, $role, $pinError] = $this->findManager($user, $business, (int) $validated['branch_id'], $validated['pin']);
        if ($pinError) {
            return $pinError;
        }

        return response()->json(['manager' => ['id' => $manager->id, 'name' => $manager->name, 'role' => $role]]);
    }

    /** Take items back: give money back, or swap them for other items. */
    public function store(Request $request): JsonResponse
    {
        [$user, $business, $error] = $this->cashierContext($request);
        if ($error) {
            return $error;
        }

        $validated = $request->validate([
            'client_uuid' => ['nullable', 'string', 'max:36'],
            'sale_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.condition' => ['required', 'string', 'in:good,damaged'],
            'new_items' => ['nullable', 'array'],
            'new_items.*.product_id' => ['required', 'integer'],
            'new_items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'new_items.*.description' => ['nullable', 'string', 'max:255'],
            'payment_method_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'manager_pin' => ['required', 'string', 'max:12'],
        ]);

        // The same return sent twice (a double tap, or a retry after a timeout) is done once
        if (! empty($validated['client_uuid'])) {
            $existing = Sale::query()->where('client_uuid', $validated['client_uuid'])->first();
            if ($existing) {
                if ($existing->sale_type !== SaleReturns::TYPE || (int) $existing->business_id !== (int) $business->id) {
                    return response()->json(['message' => 'This return was already used for something else.'], 409);
                }

                return response()->json($this->storedResponse($existing, 'Already saved'));
            }
        }

        $sale = Sale::query()
            ->where('business_id', $business->id)
            ->with('items')
            ->find($validated['sale_id']);
        if (! $sale || ! $this->userHasBranchAccess($user, $business->id, (int) $sale->branch_id)) {
            return response()->json(['message' => 'Sale not found'], 404);
        }
        if ($sale->sale_type === SaleReturns::TYPE) {
            return response()->json(['message' => 'This is a return. Find the first sale.'], 422);
        }
        if ($sale->status !== 'completed') {
            return response()->json(['message' => 'Only finished sales can be returned.'], 422);
        }

        [$manager, $managerRole, $pinError] = $this->findManager($user, $business, (int) $sale->branch_id, $validated['manager_pin']);
        if ($pinError) {
            return $pinError;
        }

        $shift = $this->currentShift($user, (int) $business->id, (int) $sale->branch_id);
        if (! $shift) {
            $paused = SalesShift::query()
                ->where('business_id', $business->id)
                ->where('user_id', $user->id)
                ->where('branch_id', $sale->branch_id)
                ->where('status', 'paused')
                ->exists();

            return response()->json([
                'message' => $paused ? 'Your shift is paused. Resume it first.' : 'Start a shift first.',
            ], 400);
        }

        $newItems = [];
        foreach ($validated['new_items'] ?? [] as $row) {
            $branchProduct = BranchProduct::query()
                ->where('branch_id', $sale->branch_id)
                ->where('product_id', $row['product_id'])
                ->whereHas('product', fn ($p) => $p->where('business_id', $business->id))
                ->with('product')
                ->first();
            if (! $branchProduct) {
                return response()->json(['message' => 'That new item is not sold at this branch.'], 422);
            }
            $quantity = BusinessQuantityPolicy::normalizeForBusiness($business, (float) $row['quantity']);
            $price = $this->pricing->getUnitPrice($branchProduct, $quantity);
            $newItems[] = [
                'branch_product' => $branchProduct,
                'quantity' => $quantity,
                'unit_price' => (float) $price['unit_price'],
                'total' => round((float) $price['unit_price'] * $quantity, 2),
                'tier' => $price,
                'description' => $row['description'] ?? null,
            ];
        }

        try {
            $return = DB::transaction(function () use ($validated, $sale, $user, $business, $manager, $managerRole, $shift, $newItems) {
                // One return at a time per sale, so two devices can't give the same item back twice
                $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
                $sale->load('items');
                $returned = SaleReturns::returnedQuantities($sale);

                $backLines = [];
                foreach ($validated['items'] as $row) {
                    $item = $sale->items->firstWhere('id', (int) $row['sale_item_id']);
                    if (! $item) {
                        throw new ReturnRefused('That item is not on this sale.');
                    }
                    $quantity = Quantity::normalize((float) $row['quantity']);
                    $left = Quantity::normalize((float) $item->quantity - ($returned[$item->id] ?? 0.0));
                    if ($quantity > $left + self::EPSILON) {
                        throw new ReturnRefused($left <= self::EPSILON
                            ? "{$item->product_name} was already returned."
                            : "Only {$this->qty($left)} of {$item->product_name} can still be returned.");
                    }
                    $backLines[] = [
                        'item' => $item,
                        'quantity' => $quantity,
                        'value' => SaleReturns::paidFor($sale, $item, $quantity),
                        'condition' => $row['condition'],
                    ];
                }

                $returnedValue = round(collect($backLines)->sum('value'), 2);
                $newValue = round(collect($newItems)->sum('total'), 2);
                $difference = round($newValue - $returnedValue, 2);

                $method = null;
                if (abs($difference) >= 0.005) {
                    $method = PaymentMethod::query()
                        ->where('business_id', $business->id)
                        ->find($validated['payment_method_id'] ?? 0);
                    if (! $method) {
                        throw new ReturnRefused($difference < 0 ? 'Choose how the money is given back.' : 'Choose how the customer pays.');
                    }
                }

                $kind = $newItems === [] ? 'refund' : 'swap';
                $return = Sale::create([
                    'client_uuid' => $validated['client_uuid'] ?? null,
                    'sale_number' => $this->returnNumber((int) $business->id),
                    'reference_id' => $sale->sale_number,
                    'business_id' => $business->id,
                    'branch_id' => $sale->branch_id,
                    'customer_id' => $sale->customer_id,
                    'user_id' => $user->id,
                    'shift_id' => $shift->id,
                    'sale_date' => now(),
                    'subtotal' => $difference,
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                    'total_amount' => $difference,
                    'paid_amount' => $difference,
                    'status' => 'completed',
                    'payment_status' => 'paid',
                    'sale_type' => SaleReturns::TYPE,
                    'notes' => $validated['notes'] ?? null,
                    'device_id' => $shift->device_id,
                    'origin' => 'online',
                    'metadata' => ['return' => [
                        'kind' => $kind,
                        'of_sale_id' => $sale->id,
                        'of_sale_date' => optional($sale->sale_date)->toIso8601String(),
                        'of_shift_id' => $sale->shift_id,
                        'manager_id' => $manager->id,
                        'manager_name' => $manager->name,
                        'manager_role' => $managerRole,
                        'reason' => $validated['reason'],
                        'returned_value' => $returnedValue,
                        'new_value' => $newValue,
                    ]],
                ]);

                foreach ($backLines as $line) {
                    $this->takeBack($return, $line, (int) $business->id, (int) $user->id);
                }
                foreach ($newItems as $line) {
                    $this->giveOut($return, $line, (int) $business->id, (int) $user->id);
                }

                if ($method) {
                    Payment::create([
                        'sale_id' => $return->id,
                        'shift_id' => $shift->id,
                        'payment_method_id' => $method->id,
                        'amount' => $difference,
                        'reference_number' => $return->sale_number,
                        'payment_date' => now(),
                        'status' => 'completed',
                        'notes' => $difference < 0 ? "Money given back for {$sale->sale_number}" : "Paid more on a swap for {$sale->sale_number}",
                        'metadata' => ['kind' => $difference < 0 ? 'return_money_back' : 'return_paid_more'],
                    ]);
                }

                $sale->addRefundedAmount($returnedValue);
                $return->load(['items', 'payments.paymentMethod', 'shift.group', 'user:id,name', 'customer']);
                $this->recordManagerActivity($return, $manager, $managerRole, $user);

                return $return;
            });
        } catch (ReturnRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Return failed', ['sale_id' => $sale->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Could not save the return. Try again.', 'error' => $e->getMessage()], 500);
        }

        return response()->json($this->storedResponse($return, 'Return saved'), 201);
    }

    /**
     * Returns and swaps for the owner and managers, newest first, with totals for the filter.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $business = $this->activeBusiness($user, $request->current_business_id);
        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }
        setPermissionsTeamId($business->id);
        if ((int) $business->owner_id !== (int) $user->id
            && ! $user->hasPermissionTo('view sales')
            && ! $user->hasPermissionTo('manage sales')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'kind' => ['nullable', 'in:refund,swap'],
            'user_id' => ['nullable', 'integer'],
            'shift_id' => ['nullable', 'integer'],
            'sale_number' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if (! empty($validated['branch_id']) && ! $this->userHasBranchAccess($user, $business->id, (int) $validated['branch_id'])) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        $timezone = ReportingTimezone::forBusiness($business->id);
        $query = Sale::query()
            ->where('business_id', $business->id)
            ->where('sale_type', SaleReturns::TYPE)
            ->when(! empty($validated['branch_id']), fn ($q) => $q->where('branch_id', $validated['branch_id']))
            ->when(! empty($validated['user_id']), fn ($q) => $q->where('user_id', $validated['user_id']))
            ->when(! empty($validated['shift_id']), fn ($q) => $q->where('shift_id', $validated['shift_id']))
            ->when(! empty($validated['sale_number']), fn ($q) => $q->where('reference_id', $validated['sale_number']))
            ->when(! empty($validated['start_date']), fn ($q) => $q->where(
                'sale_date', '>=', ReportingTimezone::utcBoundsForLocalDay($validated['start_date'], $timezone)[0]
            ))
            ->when(! empty($validated['end_date']), fn ($q) => $q->where(
                'sale_date', '<=', ReportingTimezone::utcBoundsForLocalDay($validated['end_date'], $timezone)[1]
            ));

        $all = (clone $query)->with('items')->get();
        $rows = $all->map(fn (Sale $r) => SaleReturns::present($r));
        if (! empty($validated['kind'])) {
            $keep = $rows->where('kind', $validated['kind'])->pluck('id')->all();
            $query->whereIn('id', $keep);
            $rows = $rows->whereIn('id', $keep);
        }

        $page = $query
            ->with(['items', 'payments.paymentMethod', 'shift.group', 'user:id,name', 'customer'])
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Sale $r) => SaleReturns::present($r))->values(),
            'summary' => [
                'count' => $rows->count(),
                'refunds_count' => $rows->where('kind', 'refund')->count(),
                'swaps_count' => $rows->where('kind', 'swap')->count(),
                'returned_value' => round($rows->sum('returned_value'), 2),
                'new_value' => round($rows->sum('new_value'), 2),
                'money_back' => round($rows->sum('money_back'), 2),
                'money_in' => round($rows->sum('money_in'), 2),
                'damaged_count' => $rows->sum(fn ($r) => collect($r['items_back'])->where('condition', 'damaged')->count()),
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    // ------------------------------------------------------------------

    /** @return array{0: ?User, 1: ?Business, 2: ?JsonResponse} */
    private function cashierContext(Request $request): array
    {
        $user = $request->user();
        $business = $this->activeBusiness($user, $request->current_business_id);
        if (! $business) {
            return [$user, null, response()->json(['message' => 'Business not found or access denied'], 404)];
        }
        setPermissionsTeamId($business->id);
        if ((int) $business->owner_id !== (int) $user->id
            && ! $user->hasPermissionTo('create sales')
            && ! $user->hasPermissionTo('manage sales')) {
            return [$user, $business, response()->json(['message' => 'Unauthorized'], 403)];
        }

        return [$user, $business, null];
    }

    private function activeBusiness(User $user, mixed $businessId): ?Business
    {
        if (! $businessId) {
            return null;
        }

        return $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();
    }

    /**
     * The manager (or owner) whose own PIN this is. PINs are unique across users, so the PIN
     * alone says who approved.
     *
     * @return array{0: ?User, 1: ?string, 2: ?JsonResponse}
     */
    private function findManager(User $cashier, Business $business, int $branchId, string $pin): array
    {
        $key = 'manager-pin:'.$cashier->id;
        if (RateLimiter::tooManyAttempts($key, (int) config('staff_pin.max_attempts', 5))) {
            return [null, null, response()->json([
                'message' => 'Too many wrong PINs. Wait a minute and try again.',
                'code' => 'pin_locked',
            ], 429)];
        }

        $pin = trim($pin);
        $candidate = $pin === '' ? null : User::query()->where('pin_code', $pin)->first();
        $role = $candidate ? $this->approverRole($candidate, $business, $branchId) : null;

        if (! $candidate || ! $role) {
            RateLimiter::hit($key, 60);

            return [null, null, response()->json([
                'message' => $candidate ? 'This is not a manager\'s PIN.' : 'Wrong PIN.',
                'code' => $candidate ? 'not_manager' : 'pin_wrong',
            ], 422)];
        }

        RateLimiter::clear($key);

        return [$candidate, $role, null];
    }

    /** 'Owner' or 'Manager' when the user may approve returns at the branch, else null. */
    private function approverRole(User $user, Business $business, int $branchId): ?string
    {
        if ((int) $business->owner_id === (int) $user->id) {
            return 'Owner';
        }

        if (! $this->activeBusiness($user, $business->id)) {
            return null;
        }

        setPermissionsTeamId($business->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        if (! $user->hasRole('Manager') || ! $this->userHasBranchAccess($user, $business->id, $branchId)) {
            return null;
        }

        return 'Manager';
    }

    private function currentShift(User $user, int $businessId, int $branchId): ?SalesShift
    {
        return SalesShift::query()
            ->where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            // A cashier who left an old shift open on another device: use the one started last
            ->orderByDesc('start_time')
            ->orderByDesc('id')
            ->first();
    }

    /** RET-20261116-0001 */
    private function returnNumber(int $businessId): string
    {
        $prefix = SaleReturns::PREFIX.'-'.now()->format('Ymd').'-';
        $last = Sale::query()
            ->where('sale_number', 'like', $prefix.'%')
            ->orderByDesc('sale_number')
            ->value('sale_number');
        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * An item coming back: a minus line at what the customer paid. A good item goes back on the
     * shelf; a damaged one is written off.
     *
     * @param  array{item: SaleItem, quantity: float, value: float, condition: string}  $line
     */
    private function takeBack(Sale $return, array $line, int $businessId, int $userId): void
    {
        /** @var SaleItem $item */
        $item = $line['item'];
        $quantity = $line['quantity'];
        $unit = $quantity > 0 ? round($line['value'] / $quantity, 2) : 0.0;

        $return->items()->create([
            'product_id' => $item->product_id,
            'batch_id' => $item->batch_id,
            'product_name' => $item->product_name,
            'product_sku' => $item->product_sku,
            'description' => $item->description,
            'quantity' => -$quantity,
            'unit_price' => $unit,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'subtotal' => -$line['value'],
            'total' => -$line['value'],
            'metadata' => [
                'returned_sale_item_id' => $item->id,
                'condition' => $line['condition'],
            ],
        ]);

        $branchProduct = BranchProduct::query()
            ->where('branch_id', $return->branch_id)
            ->where('product_id', $item->product_id)
            ->first();
        if (! $branchProduct) {
            return;
        }

        if ($line['condition'] === 'damaged') {
            StockWriteoff::create([
                'business_id' => $businessId,
                'branch_id' => $return->branch_id,
                'branch_product_id' => $branchProduct->id,
                'product_id' => $item->product_id,
                'batch_id' => $item->batch_id,
                'sku' => $item->product_sku ?: (string) (Product::query()->whereKey($item->product_id)->value('sku') ?? ''),
                'quantity' => max(1, (int) round($quantity)),
                'source' => 'return',
                'reason' => "Damaged: brought back by the customer ({$return->sale_number}, sale {$return->reference_id})",
                'written_off_by' => $userId,
                'written_off_at' => now(),
            ]);

            return;
        }

        $shelfBefore = (float) $branchProduct->shelf_quantity;
        $storeBefore = (float) $branchProduct->store_quantity;
        $totalBefore = (float) $branchProduct->stock_quantity;
        $branchProduct->shelf_quantity = Quantity::normalize($shelfBefore + $quantity);
        $branchProduct->stock_quantity = Quantity::normalize((float) $branchProduct->shelf_quantity + $storeBefore);
        $branchProduct->save();

        $transaction = InventoryTransaction::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $businessId,
            'branch_id' => $return->branch_id,
            'product_id' => $item->product_id,
            'user_id' => $userId,
            'type' => 'return',
            'quantity' => $quantity,
            'shelf_quantity' => $quantity,
            'store_quantity' => 0,
            'quantity_before' => $totalBefore,
            'quantity_after' => $branchProduct->stock_quantity,
            'shelf_quantity_before' => $shelfBefore,
            'shelf_quantity_after' => $branchProduct->shelf_quantity,
            'store_quantity_before' => $storeBefore,
            'store_quantity_after' => $storeBefore,
            'unit_cost' => $branchProduct->cost_price,
            'total_cost' => $branchProduct->cost_price ? $branchProduct->cost_price * $quantity : null,
            'reference_number' => $return->sale_number,
            'notes' => "Returned by the customer: {$return->reference_id}",
        ]);

        $this->batchService->addStockIn(
            (int) $item->product_id,
            (int) $return->branch_id,
            $businessId,
            $quantity,
            $transaction,
            $item->batch_id,
            []
        );
    }

    /**
     * An item going out on a swap: a normal line at today's price (promotion included), taken
     * off the shelf like a sale.
     *
     * @param  array{branch_product: BranchProduct, quantity: float, unit_price: float, total: float, tier: array, description: ?string}  $line
     */
    private function giveOut(Sale $return, array $line, int $businessId, int $userId): void
    {
        /** @var BranchProduct $branchProduct */
        $branchProduct = $line['branch_product'];
        /** @var Product $product */
        $product = $branchProduct->product;
        $quantity = $line['quantity'];

        $item = new SaleItem([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'description' => $line['description'],
            'quantity' => $quantity,
            'unit_price' => $line['unit_price'],
            'discount_percentage' => 0,
            'tax_rate' => 0,
            'metadata' => array_filter([
                'tier_type' => $line['tier']['tier_type'] ?? null,
                'product_unit_id' => $line['tier']['product_unit_id'] ?? null,
                'quantity_tier_id' => $line['tier']['quantity_tier_id'] ?? null,
                'swap' => true,
            ], fn ($value) => $value !== null),
        ]);
        $item->calculateTotals();
        $return->items()->save($item);

        $deduct = $branchProduct->deductForSale($quantity);
        $transaction = InventoryTransaction::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $businessId,
            'branch_id' => $return->branch_id,
            'product_id' => $product->id,
            'user_id' => $userId,
            'type' => 'sale',
            'quantity' => -$quantity,
            'shelf_quantity' => -$deduct['from_shelf'],
            'store_quantity' => -$deduct['from_store'],
            'quantity_before' => $deduct['quantity_before'],
            'quantity_after' => $deduct['quantity_after'],
            'shelf_quantity_before' => $deduct['shelf_quantity_before'],
            'shelf_quantity_after' => $deduct['shelf_quantity_after'],
            'store_quantity_before' => $deduct['store_quantity_before'],
            'store_quantity_after' => $deduct['store_quantity_after'],
            'unit_cost' => $branchProduct->cost_price,
            'total_cost' => $branchProduct->cost_price ? $branchProduct->cost_price * $quantity : null,
            'reference_number' => $return->sale_number,
            'notes' => "Swap: {$return->sale_number} (sale {$return->reference_id})",
        ]);

        $this->batchService->allocateStockOut(
            (int) $product->id,
            (int) $return->branch_id,
            $quantity,
            $transaction,
            ['reference_number' => $return->sale_number, 'notes' => "Swap: {$return->sale_number}"],
            false
        );
    }

    private function recordManagerActivity(Sale $return, User $manager, string $role, User $cashier): void
    {
        $row = SaleReturns::present($return);
        $money = $row['money_back'] > 0
            ? $this->naira($row['money_back']).' given back'
            : ($row['money_in'] > 0 ? 'customer paid '.$this->naira($row['money_in']).' more' : 'same price');
        $method = $row['payment_method']['name'] ?? null;
        $what = $row['kind'] === 'swap' ? 'swap' : 'return';
        $items = fn (array $lines) => collect($lines)->map(fn ($l) => $this->qty($l['quantity']).' × '.$l['name']
            .(($l['condition'] ?? 'good') === 'damaged' ? ' (damaged)' : ''))->implode(', ');

        $changes = array_filter([
            'cashier' => ['to' => $cashier->name],
            'sale' => ['to' => $return->reference_id],
            'items_back' => ['to' => $items($row['items_back'])],
            'items_out' => $row['items_out'] !== [] ? ['to' => $items($row['items_out'])] : null,
            'money_back' => $row['money_back'] > 0 ? ['to' => $row['money_back']] : null,
            'money_in' => $row['money_in'] > 0 ? ['to' => $row['money_in']] : null,
            'method' => $method ? ['to' => $method] : null,
            'reason' => ['to' => $row['reason']],
        ]);

        try {
            ManagerActivity::create([
                'business_id' => $return->business_id,
                'branch_id' => $return->branch_id,
                'user_id' => $manager->id,
                'role' => $role,
                'action' => $row['kind'] === 'swap' ? 'approved_swap' : 'approved_return',
                'description' => "Approved a {$what} for {$return->reference_id}: {$money}"
                    .($method && $row['money_back'] + $row['money_in'] > 0 ? " ({$method})" : '')
                    .". Cashier {$cashier->name}",
                'subject_type' => 'Sale',
                'subject_id' => $return->id,
                'changes' => $changes,
                'pin_ok' => true,
                'method' => 'POST',
                'path' => 'returns',
            ]);
        } catch (Throwable $e) {
            // Never fail the return because the log could not be written
            Log::warning('Could not record return in manager activity', ['error' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    private function storedResponse(Sale $return, string $message): array
    {
        $return->loadMissing(['items', 'payments.paymentMethod', 'shift.group', 'user:id,name', 'customer']);

        return ['message' => $message, 'return' => SaleReturns::present($return)];
    }

    /** @return array<string, mixed> */
    private function presentSaleForReturn(Sale $sale): array
    {
        $returned = SaleReturns::returnedQuantities($sale);
        $timezone = ReportingTimezone::forBusiness($sale->business_id);
        $saleDate = $sale->sale_date ?? $sale->created_at;

        return [
            'id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'sale_date' => optional($saleDate)->toIso8601String(),
            'days_old' => $saleDate
                ? (int) Carbon::parse(ReportingTimezone::localDay($saleDate, $timezone))
                    ->diffInDays(Carbon::parse(ReportingTimezone::localDay(now(), $timezone)))
                : null,
            'sale_type' => $sale->sale_type,
            'status' => $sale->status,
            'total_amount' => round((float) $sale->total_amount, 2),
            'customer' => $sale->customer ? [
                'id' => $sale->customer->id,
                'name' => $sale->customer->name,
                'phone' => $sale->customer->phone,
            ] : null,
            'cashier' => $sale->user ? ['id' => $sale->user->id, 'name' => $sale->user->name] : null,
            'payment_methods' => $sale->payments->where('status', 'completed')
                ->map(fn (Payment $p) => $p->paymentMethod->name ?? null)->filter()->unique()->values()->all(),
            'items' => $sale->items->map(function (SaleItem $item) use ($sale, $returned) {
                $quantity = (float) $item->quantity;
                $back = min($quantity, $returned[$item->id] ?? 0.0);
                $left = Quantity::normalize(max(0.0, $quantity - $back));

                return [
                    'id' => $item->id,
                    'product_id' => (int) $item->product_id,
                    'product_name' => $item->product_name,
                    'description' => $item->description,
                    'quantity' => $quantity,
                    'unit_paid' => $quantity > 0 ? SaleReturns::paidFor($sale, $item, 1) : 0.0,
                    'total_paid' => SaleReturns::paidFor($sale, $item, $quantity),
                    'returned_quantity' => $back,
                    'returnable_quantity' => $left,
                ];
            })->values()->all(),
            'returns' => SaleReturns::forSale($sale)->map(fn (Sale $r) => [
                'id' => $r->id,
                'return_number' => $r->sale_number,
                'created_at' => optional($r->sale_date)->toIso8601String(),
                'returned_value' => SaleReturns::returnedValue($r),
            ])->values()->all(),
        ];
    }

    private function qty(float $quantity): string
    {
        return floor($quantity) === $quantity ? (string) (int) $quantity : (string) $quantity;
    }

    private function naira(float $amount): string
    {
        return '₦'.number_format($amount, floor($amount) === $amount ? 0 : 2);
    }
}
