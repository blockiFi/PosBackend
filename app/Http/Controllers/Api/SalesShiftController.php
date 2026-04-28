<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\Branch;
use App\Models\DeviceRegistration;
use App\Models\SalesShift;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesShiftController extends Controller
{
    use HasBranchAccess;

    /**
     * List shifts with filtering and statistics
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

        $canViewAll = $business->owner_id === $user->id || $user->hasPermissionTo('view all shifts');
        $canViewOwn = $user->hasPermissionTo('view user shift');

        if (! $canViewAll && ! $canViewOwn) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = SalesShift::with(['user', 'branch', 'group'])
            ->forBusiness($businessId);

        // If user can only view their own shifts, filter by user_id
        if (! $canViewAll && $canViewOwn) {
            $query->where('user_id', $user->id);
        }

        // Filter by branch
        if ($request->filled('branch_id')) {
            $branchId = $request->branch_id;
            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json(['message' => 'Unauthorized access to this branch'], 403);
            }
            $query->where('branch_id', $branchId);
        } else {
            // Filter by accessible branches
            $accessibleBranches = $user->getBranchesInBusiness($businessId);
            if ($accessibleBranches->isNotEmpty()) {
                $query->whereIn('branch_id', $accessibleBranches->pluck('id'));
            }
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('device_id')) {
            $query->where('device_id', $request->device_id);
        }

        if ($request->filled('group_id')) {
            $query->where('group_id', $request->group_id);
        }

        // Filter by discrepancies (variance on closed shifts or opening balance discrepancy)
        if ($request->boolean('has_discrepancy')) {
            $query->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', 'closed')
                        ->whereRaw('ABS(variance) >= 0.01');
                })->orWhere(function ($sub) {
                    $sub->whereNotNull('opening_balance_discrepancy')
                        ->whereRaw('ABS(opening_balance_discrepancy) >= 0.01');
                });
            });
        }

        // Date filtering
        if ($request->filled('filter')) {
            switch ($request->filter) {
                case 'today':
                    $query->whereDate('start_time', today());
                    break;
                case 'last_7_days':
                    $query->whereBetween('start_time', [now()->subDays(7), now()]);
                    break;
            }
        }

        // Custom date range
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->dateRange($request->start_date, $request->end_date);
        }

        // Statistics over all matching shifts (same filters, no pagination)
        $statsRow = (clone $query)->selectRaw(
            'COUNT(*) as total_shifts, COALESCE(SUM(total_sales),0) as total_gross_sales, COALESCE(SUM(transactions_count),0) as total_transactions, COALESCE(SUM(cash_sales),0) as total_cash_sales, COALESCE(SUM(card_sales),0) as total_card_sales, COALESCE(SUM(other_sales),0) as total_other_sales'
        )->first();

        $statusCounts = (clone $query)->selectRaw('status, COUNT(*) as cnt')->groupBy('status')->pluck('cnt', 'status');
        $shiftsByStatus = [
            'open' => (int) ($statusCounts->get('open') ?? 0),
            'closed' => (int) ($statusCounts->get('closed') ?? 0),
            'paused' => (int) ($statusCounts->get('paused') ?? 0),
        ];

        $totalGrossSales = (float) $statsRow->total_gross_sales;
        $totalTransactions = (int) $statsRow->total_transactions;
        $totalCashSales = (float) $statsRow->total_cash_sales;
        $totalCardSales = (float) $statsRow->total_card_sales;
        $totalOtherSales = (float) $statsRow->total_other_sales;
        $totalForPercent = $totalGrossSales > 0 ? $totalGrossSales : 1;
        $cashPercentage = round(($totalCashSales / $totalForPercent) * 100, 2);
        $cardPercentage = round(($totalCardSales / $totalForPercent) * 100, 2);
        $otherPercentage = round(($totalOtherSales / $totalForPercent) * 100, 2);
        $averageBasketValue = $totalTransactions > 0
            ? round($totalGrossSales / $totalTransactions, 2)
            : 0;

        $statistics = [
            'total_shifts_count' => (int) $statsRow->total_shifts,
            'total_gross_sales' => $totalGrossSales,
            'total_transactions' => $totalTransactions,
            'shifts_by_status' => $shiftsByStatus,
            'average_basket_value' => $averageBasketValue,
            'sales_by_payment_type' => [
                'cash' => ['amount' => $totalCashSales, 'percentage' => $cashPercentage],
                'card' => ['amount' => $totalCardSales, 'percentage' => $cardPercentage],
                'other' => ['amount' => $totalOtherSales, 'percentage' => $otherPercentage],
            ],
        ];

        $perPage = (int) $request->input('per_page', 15);
        if ($perPage < 1) {
            $perPage = 15;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        $shifts = $query->orderBy('start_time', 'desc')->paginate($perPage);

        // Enhance each shift with statistics
        $shifts->getCollection()->transform(function ($shift) {
            return $this->enrichShiftWithStats($shift);
        });

        return response()->json([
            'shifts' => $shifts,
            'statistics' => $statistics,
        ]);
    }

    /**
     * All-shifts summary for a branch with optional filters: start_date, end_date, user_id.
     * Aggregates gross sales, total transactions, and counts across matching shifts.
     */
    public function branchShiftsSummary(Request $request)
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

        $canViewAll = $business->owner_id === $user->id || $user->hasPermissionTo('view all shifts');
        $canViewOwn = $user->hasPermissionTo('view user shift');

        if (! $canViewAll && ! $canViewOwn) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'user_id' => 'nullable|exists:users,id',
        ]);

        $branchId = (int) $validated['branch_id'];
        if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        $query = SalesShift::with(['branch', 'user'])
            ->forBusiness($businessId)
            ->forBranch($branchId);

        if (! $canViewAll && $canViewOwn) {
            $query->where('user_id', $user->id);
        } elseif (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        if (! empty($validated['start_date']) && ! empty($validated['end_date'])) {
            $query->dateRange($validated['start_date'], $validated['end_date']);
        }

        $shifts = $query->get();

        $totalGrossSales = (float) $shifts->sum('total_sales');
        $totalTransactions = (int) $shifts->sum('transactions_count');
        $totalCashSales = (float) $shifts->sum('cash_sales');
        $totalCardSales = (float) $shifts->sum('card_sales');
        $totalOtherSales = (float) $shifts->sum('other_sales');

        $shiftsByStatus = [
            'open' => $shifts->where('status', 'open')->count(),
            'closed' => $shifts->where('status', 'closed')->count(),
            'paused' => $shifts->where('status', 'paused')->count(),
        ];

        $totalForPercent = $totalGrossSales > 0 ? $totalGrossSales : 1;
        $cashPercentage = round(($totalCashSales / $totalForPercent) * 100, 2);
        $cardPercentage = round(($totalCardSales / $totalForPercent) * 100, 2);
        $otherPercentage = round(($totalOtherSales / $totalForPercent) * 100, 2);

        $averageBasketValue = $totalTransactions > 0
            ? round($totalGrossSales / $totalTransactions, 2)
            : 0;

        $branch = $shifts->first()?->branch
            ?? Branch::where('id', $branchId)->where('business_id', $businessId)->first();

        $summary = [
            'branch_id' => $branchId,
            'branch' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
            ] : null,
            'filters' => [
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'user_id' => $validated['user_id'] ?? null,
            ],
            'total_gross_sales' => $totalGrossSales,
            'total_transactions' => $totalTransactions,
            'total_shifts_count' => $shifts->count(),
            'shifts_by_status' => $shiftsByStatus,
            'average_basket_value' => $averageBasketValue,
            'sales_by_payment_type' => [
                'cash' => [
                    'amount' => $totalCashSales,
                    'percentage' => $cashPercentage,
                ],
                'card' => [
                    'amount' => $totalCardSales,
                    'percentage' => $cardPercentage,
                ],
                'other' => [
                    'amount' => $totalOtherSales,
                    'percentage' => $otherPercentage,
                ],
            ],
        ];

        return response()->json(['data' => $summary]);
    }

    /**
     * Backfill group_id on shifts where it is null, using the device's current group_id.
     */
    public function backfillGroups(Request $request)
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

        $canManage = $business->owner_id === $user->id
            || $user->hasPermissionTo('view all shifts')
            || $user->hasPermissionTo('create shift');

        if (! $canManage) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $payload = DB::transaction(function () use ($businessId) {
            $shifts = SalesShift::forBusiness($businessId)
                ->whereNull('group_id')
                ->get(['id', 'device_id']);

            $scanned = $shifts->count();

            $shiftsWithDevice = $shifts->filter(fn ($s) => $s->device_id !== null && $s->device_id !== '');
            $deviceIds = $shiftsWithDevice->pluck('device_id')->unique()->values();

            $devices = DeviceRegistration::query()
                ->where('business_id', $businessId)
                ->whereIn('device_id', $deviceIds)
                ->get(['device_id', 'group_id'])
                ->keyBy('device_id');

            $updated = 0;
            $skippedNoDevice = 0;
            $skippedDeviceHasNoGroup = 0;
            $missingDeviceIds = [];

            foreach ($shiftsWithDevice->groupBy('device_id') as $deviceId => $group) {
                $device = $devices->get($deviceId);
                if (! $device) {
                    $skippedNoDevice += $group->count();
                    $missingDeviceIds[] = $deviceId;

                    continue;
                }
                if ($device->group_id === null) {
                    $skippedDeviceHasNoGroup += $group->count();

                    continue;
                }

                $updated += SalesShift::forBusiness($businessId)
                    ->whereNull('group_id')
                    ->where('device_id', $deviceId)
                    ->update(['group_id' => $device->group_id]);
            }

            $nullDeviceShifts = $shifts->filter(fn ($s) => $s->device_id === null || $s->device_id === '');
            $skippedNoDevice += $nullDeviceShifts->count();

            return [
                'scanned' => $scanned,
                'updated' => $updated,
                'skipped_no_device' => $skippedNoDevice,
                'skipped_device_has_no_group' => $skippedDeviceHasNoGroup,
                'missing_device_ids' => array_values(array_unique($missingDeviceIds)),
            ];
        });

        return response()->json($payload);
    }

    /**
     * Open a new shift
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

        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('create shift')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // device_id: required; body takes precedence over X-Device-Id header
        $request->merge([
            'device_id' => $request->input('device_id') ?? $request->header('X-Device-Id'),
        ]);

        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'device_id' => 'required|string|max:50',
            'opening_balance' => 'required|numeric|min:0',
            'opening_notes' => 'nullable|string',
        ]);

        $device = DeviceRegistration::query()
            ->where('business_id', $businessId)
            ->where('device_id', $validated['device_id'])
            ->first();

        if (! $device) {
            return response()->json(['message' => 'Device is not registered for this business'], 400);
        }

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $validated['branch_id'])) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        // Business Rule: Each device can only have ONE active shift at a time (open or paused)
        $deviceActiveShift = SalesShift::forBusiness($businessId)
            ->where('device_id', $validated['device_id'])
            ->active()
            ->first();

        if ($deviceActiveShift) {
            return response()->json([
                'message' => 'This device already has an active shift (open or paused). Please close it before opening a new one.',
                'current_shift' => [
                    'id' => $deviceActiveShift->id,
                    'shift_number' => $deviceActiveShift->shift_number,
                    'branch_id' => $deviceActiveShift->branch_id,
                    'branch_name' => $deviceActiveShift->branch->name ?? null,
                    'status' => $deviceActiveShift->status,
                    'opened_at' => $deviceActiveShift->start_time->toIso8601String(),
                ],
            ], 400);
        }

        // Business Rule: Each user can only have ONE active shift at a time (open or paused) across all branches
        $activeShift = SalesShift::forBusiness($businessId)
            ->where('user_id', $user->id)
            ->active()
            ->first();

        if ($activeShift) {
            return response()->json([
                'message' => 'You already have an active shift (open or paused). Please close or resume it before opening a new one.',
                'current_shift' => [
                    'id' => $activeShift->id,
                    'shift_number' => $activeShift->shift_number,
                    'branch_id' => $activeShift->branch_id,
                    'branch_name' => $activeShift->branch->name ?? null,
                    'status' => $activeShift->status,
                    'opened_at' => $activeShift->start_time->toIso8601String(),
                ],
            ], 400);
        }

        DB::beginTransaction();
        try {
            $shift = null;
            $startTime = now();

            // Guard against rare collisions (unique index is final guardrail).
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $shiftNumber = $this->generateShiftNumber($businessId, $startTime);

                try {
                    $shift = SalesShift::create([
                        'shift_number' => $shiftNumber,
                        'business_id' => $businessId,
                        'branch_id' => $validated['branch_id'],
                        'user_id' => $user->id,
                        'device_id' => $validated['device_id'],
                        'group_id' => $device->group_id,
                        'start_time' => $startTime,
                        'opening_balance' => $validated['opening_balance'],
                        'opening_notes' => $validated['opening_notes'] ?? null,
                        'status' => 'open',
                    ]);
                    break;
                } catch (QueryException $e) {
                    if (! $this->isDuplicateShiftNumberException($e)) {
                        throw $e;
                    }
                    // collision: loop and try next sequence
                }
            }

            if ($shift === null) {
                throw new \RuntimeException('Unable to generate unique shift number. Please retry.');
            }

            // Opening balance discrepancy: compare to last closed shift on same device
            $previousShift = SalesShift::forBusiness($businessId)
                ->where('device_id', $shift->device_id)
                ->where('status', 'closed')
                ->where('id', '!=', $shift->id)
                ->orderByDesc('end_time')
                ->orderByDesc('id')
                ->first();

            if ($previousShift !== null && $previousShift->actual_cash !== null) {
                $openingBalanceDiscrepancy = (float) $shift->opening_balance - (float) $previousShift->actual_cash;
                if (abs($openingBalanceDiscrepancy) >= 0.01) {
                    $shift->update([
                        'opening_balance_discrepancy' => $openingBalanceDiscrepancy,
                        'previous_shift_id' => $previousShift->id,
                    ]);
                    $shift->refresh();
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Shift opened successfully',
                'shift' => $shift->load(['user', 'branch', 'previousShift', 'group']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Failed to open shift', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * View a specific shift
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

        $canViewAll = $business->owner_id === $user->id || $user->hasPermissionTo('view all shifts');
        $canViewOwn = $user->hasPermissionTo('view user shift');

        if (! $canViewAll && ! $canViewOwn) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $shift = SalesShift::with(['user', 'branch', 'group', 'previousShift', 'sales' => function ($query) {
            $query->with(['payments.paymentMethod', 'customer', 'items.product'])
                ->withTrashed(); // Include voided/cancelled sales
        }])
            ->forBusiness($businessId)
            ->findOrFail($id);

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $shift->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        // If user can only view their own shifts, verify ownership
        if (! $canViewAll && $canViewOwn && $shift->user_id !== $user->id) {
            return response()->json(['message' => 'You can only view your own shifts'], 403);
        }

        // Enrich with statistics and sales details
        $shift = $this->enrichShiftWithStats($shift);
        $shift = $this->enrichShiftWithSalesDetails($shift);

        return response()->json($shift);
    }

    /**
     * Get shift summary: gross sales, total transactions, and key metrics.
     * Uses live sales data for open/paused shifts; uses stored totals for closed shifts.
     */
    public function summary(Request $request, $id)
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

        $canViewAll = $business->owner_id === $user->id || $user->hasPermissionTo('view all shifts');
        $canViewOwn = $user->hasPermissionTo('view user shift');

        if (! $canViewAll && ! $canViewOwn) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $shift = SalesShift::with(['user', 'branch', 'group'])
            ->forBusiness($businessId)
            ->findOrFail($id);

        if (! $this->userHasBranchAccess($user, $businessId, $shift->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        if (! $canViewAll && $canViewOwn && $shift->user_id !== $user->id) {
            return response()->json(['message' => 'You can only view your own shifts'], 403);
        }

        $summary = $this->buildShiftSummary($shift);

        return response()->json(['data' => $summary]);
    }

    /**
     * Close a shift
     */
    public function close(Request $request, $id)
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

        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('close shift')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'actual_cash' => 'required|numeric|min:0',
            'closing_notes' => 'nullable|string',
            'pin_code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ]);

        $shift = SalesShift::forBusiness($businessId)->findOrFail($id);

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $shift->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        if ($shift->status === 'closed') {
            return response()->json(['message' => 'Shift is already closed'], 400);
        }

        // Only the shift owner or someone with manage shifts permission (or owner) can close
        if ($shift->user_id !== $user->id && $business->owner_id !== $user->id && ! $user->hasPermissionTo('manage shifts')) {
            return response()->json(['message' => 'You can only close your own shifts'], 403);
        }

        if (empty($user->pin_code)) {
            return response()->json([
                'message' => 'PIN verification required to close a shift. Set a PIN in your account first.',
            ], 400);
        }

        if ($user->pin_code !== $validated['pin_code']) {
            return response()->json(['message' => 'Invalid PIN code'], 401);
        }

        DB::beginTransaction();
        try {
            // Update sales metrics from actual sales
            $shift->updateSalesMetrics();

            // Calculate expected cash and variance
            $shift->calculateExpectedCash();
            $shift->actual_cash = $validated['actual_cash'];
            $shift->calculateVariance();

            $shift->end_time = now();
            $shift->closing_notes = $validated['closing_notes'] ?? null;
            $shift->status = 'closed';
            $shift->save();

            DB::commit();

            return response()->json([
                'message' => 'Shift closed successfully',
                'shift' => $shift->fresh(['user', 'branch']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Failed to close shift', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Pause an open shift (no sales allowed while paused)
     */
    public function pause(Request $request, $id)
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

        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('create shift')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $shift = SalesShift::forBusiness($businessId)->findOrFail($id);

        if (! $this->userHasBranchAccess($user, $businessId, $shift->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        if ($shift->user_id !== $user->id && $business->owner_id !== $user->id && ! $user->hasPermissionTo('manage shifts')) {
            return response()->json(['message' => 'You can only pause your own shifts'], 403);
        }

        if ($shift->status === 'closed') {
            return response()->json(['message' => 'Cannot pause a closed shift'], 400);
        }

        if ($shift->status === 'paused') {
            return response()->json(['message' => 'Shift is already paused'], 400);
        }

        $shift->status = 'paused';
        $shift->paused_at = now();
        $shift->save();

        return response()->json([
            'message' => 'Shift paused successfully',
            'shift' => $shift->fresh(['user', 'branch']),
        ]);
    }

    /**
     * Resume a paused shift
     */
    public function resume(Request $request, $id)
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

        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('create shift')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $shift = SalesShift::forBusiness($businessId)->findOrFail($id);

        if (! $this->userHasBranchAccess($user, $businessId, $shift->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        if ($shift->user_id !== $user->id && $business->owner_id !== $user->id && ! $user->hasPermissionTo('manage shifts')) {
            return response()->json(['message' => 'You can only resume your own shifts'], 403);
        }

        if ($shift->status !== 'paused') {
            return response()->json(['message' => 'Only a paused shift can be resumed'], 400);
        }

        $validated = $request->validate([
            'pin_code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ]);

        if (empty($user->pin_code)) {
            return response()->json([
                'message' => 'PIN verification required to resume a shift. Set a PIN in your account first.',
            ], 400);
        }

        if ($user->pin_code !== $validated['pin_code']) {
            return response()->json(['message' => 'Invalid PIN code'], 401);
        }

        $shift->status = 'open';
        $shift->paused_at = null;
        $shift->save();

        return response()->json([
            'message' => 'Shift resumed successfully',
            'shift' => $shift->fresh(['user', 'branch']),
        ]);
    }

    /**
     * Get current open or paused shift for user
     */
    public function current(Request $request)
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

        // Check permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view user shift') && ! $user->hasPermissionTo('view all shifts')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $shift = SalesShift::with(['user', 'branch', 'group'])
            ->forBusiness($businessId)
            ->where('user_id', $user->id)
            ->active()
            ->first();

        if (! $shift) {
            return response()->json(['message' => 'No active shift found (open or paused)'], 404);
        }

        // Verify branch access
        if (! $this->userHasBranchAccess($user, $businessId, $shift->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        return response()->json($shift);
    }

    /**
     * Mark a shift discrepancy as resolved
     */
    public function resolveDiscrepancy(Request $request, $id)
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

        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage shifts')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'resolution_notes' => 'required|string|max:1000',
        ]);

        $shift = SalesShift::forBusiness($businessId)->findOrFail($id);

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $shift->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        if ($shift->status !== 'closed') {
            return response()->json(['message' => 'Only closed shifts can have discrepancies resolved'], 400);
        }

        if (abs($shift->variance ?? 0) < 0.01) {
            return response()->json(['message' => 'This shift has no discrepancy to resolve'], 400);
        }

        DB::beginTransaction();
        try {
            $shift->discrepancy_resolved = true;
            $shift->discrepancy_resolved_at = now();
            $shift->discrepancy_resolved_by = $user->id;
            $shift->resolution_notes = $validated['resolution_notes'];
            $shift->save();

            DB::commit();

            return response()->json([
                'message' => 'Shift discrepancy marked as resolved',
                'shift' => $shift->fresh(['user', 'branch']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Failed to resolve discrepancy', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get sales for a specific shift
     */
    public function sales(Request $request, $id)
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

        $canViewAll = $business->owner_id === $user->id || $user->hasPermissionTo('view all shifts');
        $canViewOwn = $user->hasPermissionTo('view user shift');

        if (! $canViewAll && ! $canViewOwn) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $shift = SalesShift::forBusiness($businessId)->findOrFail($id);

        // Check branch access
        if (! $this->userHasBranchAccess($user, $businessId, $shift->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        // If user can only view their own shifts, verify ownership
        if (! $canViewAll && $canViewOwn && $shift->user_id !== $user->id) {
            return response()->json(['message' => 'You can only view your own shifts'], 403);
        }

        // Get sales with detailed information
        $query = $shift->sales()
            ->with(['payments.paymentMethod', 'customer', 'items.product'])
            ->withTrashed();

        // Filter by status if provided
        if ($request->filled('status')) {
            if ($request->status === 'voided') {
                $query->onlyTrashed();
            } elseif ($request->status === 'active') {
                $query->withoutTrashed();
            }
        }

        // Filter by payment method if provided
        if ($request->filled('payment_method')) {
            $query->whereHas('payments.paymentMethod', function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->payment_method.'%');
            });
        }

        $sales = $query->orderBy('created_at', 'desc')->paginate(20);

        // Enhance each sale with payment info
        $sales->getCollection()->transform(function ($sale) {
            $paymentMethods = $sale->payments->map(function ($payment) {
                return [
                    'method' => $payment->paymentMethod->name ?? 'Unknown',
                    'amount' => (float) $payment->amount,
                    'reference' => $payment->reference_number,
                ];
            });

            return [
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'total' => (float) $sale->total_amount,
                'subtotal' => (float) $sale->subtotal,
                'tax' => (float) $sale->tax_amount,
                'discount' => (float) $sale->discount_amount,
                'status' => $sale->trashed() ? 'voided' : $sale->status,
                'is_voided' => $sale->trashed(),
                'payment_methods' => $paymentMethods,
                'customer' => $sale->customer ? [
                    'id' => $sale->customer->id,
                    'name' => $sale->customer->name,
                    'phone' => $sale->customer->phone,
                ] : null,
                'items_count' => $sale->items->count(),
                'created_at' => $sale->created_at->toIso8601String(),
                'voided_at' => $sale->deleted_at ? $sale->deleted_at->toIso8601String() : null,
            ];
        });

        return response()->json($sales);
    }

    /**
     * Build shift summary with gross sales, total transactions, and key metrics.
     * Uses live sales for open/paused shifts; stored totals for closed shifts.
     *
     * @return array<string, mixed>
     */
    private function buildShiftSummary(SalesShift $shift): array
    {
        $isClosed = $shift->status === 'closed';

        if ($isClosed) {
            $grossSales = (float) ($shift->total_sales ?? 0);
            $totalTransactions = (int) ($shift->transactions_count ?? 0);
            $cashSales = (float) ($shift->cash_sales ?? 0);
            $cardSales = (float) ($shift->card_sales ?? 0);
            $otherSales = (float) ($shift->other_sales ?? 0);
        } else {
            // Open / paused: same cash-basis logic as updateSalesMetrics() so pending deposit
            // installments (sale still pending) count toward expected cash and totals.
            $m = $shift->computeMetricsFromShiftPayments();
            $grossSales = $m['total_sales'];
            $totalTransactions = $m['transactions_count'];
            $cashSales = $m['cash_sales'];
            $cardSales = $m['card_sales'];
            $otherSales = $m['other_sales'];
        }

        $expectedCash = (float) ($shift->opening_balance ?? 0) + $cashSales;
        $actualCash = $shift->actual_cash !== null ? (float) $shift->actual_cash : null;
        $variance = $actualCash !== null ? $actualCash - $expectedCash : null;

        $averageBasketValue = $totalTransactions > 0
            ? round($grossSales / $totalTransactions, 2)
            : 0;

        $totalForPercent = $grossSales > 0 ? $grossSales : 1;
        $cashPercentage = round(($cashSales / $totalForPercent) * 100, 2);
        $cardPercentage = round(($cardSales / $totalForPercent) * 100, 2);
        $otherPercentage = round(($otherSales / $totalForPercent) * 100, 2);

        $hasDiscrepancy = $variance !== null && abs($variance) >= 0.01;
        $reconciliationStatus = $variance === null
            ? 'pending'
            : (! $hasDiscrepancy ? 'balanced' : ($shift->discrepancy_resolved ? 'resolved' : 'discrepancy'));

        $startTime = $shift->start_time;
        $endTime = $shift->end_time ?? ($shift->status === 'closed' ? $shift->end_time : now());
        $durationMinutes = $endTime ? $startTime->diffInMinutes($endTime) : 0;

        $voidedCount = $shift->sales()->onlyTrashed()->count();

        return [
            'shift_id' => $shift->id,
            'shift_number' => $shift->shift_number,
            'status' => $shift->status,
            'group_id' => $shift->group_id,
            'group' => $shift->group ? [
                'id' => $shift->group->id,
                'name' => $shift->group->name,
                'code' => $shift->group->code,
            ] : null,
            'branch' => $shift->branch ? [
                'id' => $shift->branch->id,
                'name' => $shift->branch->name,
            ] : null,
            'user' => $shift->user ? [
                'id' => $shift->user->id,
                'name' => $shift->user->name,
            ] : null,
            'gross_sales' => $grossSales,
            'total_transactions' => $totalTransactions,
            'voided_transactions_count' => $voidedCount,
            'average_basket_value' => $averageBasketValue,
            'sales_by_payment_type' => [
                'cash' => [
                    'amount' => $cashSales,
                    'percentage' => $cashPercentage,
                ],
                'card' => [
                    'amount' => $cardSales,
                    'percentage' => $cardPercentage,
                ],
                'other' => [
                    'amount' => $otherSales,
                    'percentage' => $otherPercentage,
                ],
            ],
            'opening_balance' => (float) ($shift->opening_balance ?? 0),
            'expected_cash' => $expectedCash,
            'actual_cash' => $actualCash,
            'variance' => $variance,
            'reconciliation_status' => $reconciliationStatus,
            'has_discrepancy' => $hasDiscrepancy,
            'discrepancy_resolved' => (bool) ($shift->discrepancy_resolved ?? false),
            'discrepancy_resolved_at' => $shift->discrepancy_resolved_at?->toIso8601String(),
            'resolution_notes' => $shift->resolution_notes,
            'shift_duration' => [
                'start_time' => $shift->start_time->toIso8601String(),
                'end_time' => $shift->end_time?->toIso8601String(),
                'duration_minutes' => $durationMinutes,
                'duration_formatted' => sprintf('%dh %dm', (int) floor($durationMinutes / 60), $durationMinutes % 60),
            ],
            'opening_notes' => $shift->opening_notes,
            'closing_notes' => $shift->closing_notes,
            'device_id' => $shift->device_id,
            'opening_balance_discrepancy' => $shift->opening_balance_discrepancy !== null ? (float) $shift->opening_balance_discrepancy : null,
            'previous_shift_id' => $shift->previous_shift_id,
            'has_opening_balance_discrepancy' => $shift->hasOpeningBalanceDiscrepancy(),
        ];
    }

    /**
     * Generate unique shift number
     */
    private function generateShiftNumber($businessId, $time = null): string
    {
        $prefix = 'SHIFT';

        // Use UTC so sorting/format is consistent across servers.
        $t = $time ? \Illuminate\Support\Carbon::parse($time) : now();
        $t = $t->copy()->setTimezone('UTC');

        // Example: 20260406-103012-482 (ms)
        $timestamp = $t->format('Ymd-His-v');

        // Reduce cross-business collision chance and keep it readable.
        $biz = strtoupper(base_convert((int) $businessId, 10, 36));

        // Random suffix makes collisions practically impossible even within same millisecond.
        $rand = strtoupper(bin2hex(random_bytes(2))); // 4 hex chars

        return sprintf('%s-%s-%s-%s', $prefix, $timestamp, $biz, $rand);
    }

    private function isDuplicateShiftNumberException(QueryException $e): bool
    {
        // MySQL duplicate key error: 1062
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            $msg = (string) $e->getMessage();

            return str_contains($msg, 'sales_shifts_shift_number_unique') || str_contains($msg, 'shift_number');
        }

        return false;
    }

    /**
     * Enrich shift with statistics
     */
    private function enrichShiftWithStats($shift)
    {
        if ($shift->status === 'closed') {
            $totalSales = $shift->total_sales ?? 0;
            $cashSales = $shift->cash_sales ?? 0;
            $cardSales = $shift->card_sales ?? 0;
            $transactionsCount = $shift->transactions_count ?? 0;
        } else {
            $m = $shift->computeMetricsFromShiftPayments();
            $totalSales = $m['total_sales'];
            $cashSales = $m['cash_sales'];
            $cardSales = $m['card_sales'];
            $transactionsCount = $m['transactions_count'];
        }

        // Calculate average basket value
        $averageBasketValue = $transactionsCount > 0
            ? round($totalSales / $transactionsCount, 2)
            : 0;

        // Calculate payment method breakdown
        $posPercentage = $totalSales > 0
            ? round(($cardSales / $totalSales) * 100, 2)
            : 0;
        $cashPercentage = $totalSales > 0
            ? round(($cashSales / $totalSales) * 100, 2)
            : 0;

        // Determine reconciliation status
        $variance = $shift->variance ?? 0;
        $hasDiscrepancy = abs($variance) >= 0.01;
        $reconciliationStatus = ! $hasDiscrepancy ? 'balanced' : ($shift->discrepancy_resolved ? 'resolved' : 'discrepancy');

        // Calculate shift duration
        $startTime = $shift->start_time;
        $endTime = $shift->end_time ?? now();
        $durationInMinutes = $startTime->diffInMinutes($endTime);
        $durationFormatted = sprintf('%dh %dm', floor($durationInMinutes / 60), $durationInMinutes % 60);

        // Add statistics to shift (device_id and opening balance discrepancy are model attributes, included in JSON)
        $shift->statistics = [
            'device_id' => $shift->device_id,
            'group_id' => $shift->group_id,
            'group' => $shift->relationLoaded('group') && $shift->group ? [
                'id' => $shift->group->id,
                'name' => $shift->group->name,
                'code' => $shift->group->code,
            ] : null,
            'opening_balance_discrepancy' => $shift->opening_balance_discrepancy !== null ? (float) $shift->opening_balance_discrepancy : null,
            'previous_shift_id' => $shift->previous_shift_id,
            'has_opening_balance_discrepancy' => $shift->hasOpeningBalanceDiscrepancy(),
            'gross_sales' => (float) $totalSales,
            'total_transactions' => $transactionsCount,
            'average_basket_value' => $averageBasketValue,
            'payment_breakdown' => [
                'pos_percentage' => $posPercentage,
                'cash_percentage' => $cashPercentage,
                'pos_amount' => (float) $cardSales,
                'cash_amount' => (float) $cashSales,
            ],
            'reconciliation_status' => $reconciliationStatus,
            'variance' => (float) $variance,
            'has_discrepancy' => $hasDiscrepancy,
            'discrepancy_resolved' => $shift->discrepancy_resolved ?? false,
            'discrepancy_resolved_at' => $shift->discrepancy_resolved_at ? $shift->discrepancy_resolved_at->toIso8601String() : null,
            'resolution_notes' => $shift->resolution_notes,
            'shift_duration' => [
                'start_time' => $startTime->toIso8601String(),
                'end_time' => $shift->end_time ? $shift->end_time->toIso8601String() : null,
                'duration_minutes' => $durationInMinutes,
                'duration_formatted' => $durationFormatted,
            ],
        ];

        return $shift;
    }

    /**
     * Enrich shift with detailed sales information
     */
    private function enrichShiftWithSalesDetails($shift)
    {
        if (! $shift->relationLoaded('sales')) {
            return $shift;
        }

        $sales = $shift->sales;
        $activeSales = $sales->filter(fn ($sale) => ! $sale->trashed());
        $voidedSales = $sales->filter(fn ($sale) => $sale->trashed());

        // Categorize sales by payment method
        $salesByPaymentMethod = $activeSales->map(function ($sale) {
            $paymentMethods = $sale->payments->pluck('paymentMethod.name')->unique()->join(', ');

            return [
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'total' => (float) $sale->total_amount,
                'status' => $sale->status,
                'payment_methods' => $paymentMethods,
                'is_voided' => false,
                'created_at' => $sale->created_at->toIso8601String(),
                'customer' => $sale->customer ? [
                    'id' => $sale->customer->id,
                    'name' => $sale->customer->name,
                ] : null,
            ];
        });

        // Add voided sales
        $voidedSalesData = $voidedSales->map(function ($sale) {
            $paymentMethods = $sale->payments->pluck('paymentMethod.name')->unique()->join(', ');

            return [
                'id' => $sale->id,
                'sale_number' => $sale->sale_number,
                'total' => (float) $sale->total_amount,
                'status' => 'voided',
                'payment_methods' => $paymentMethods,
                'is_voided' => true,
                'voided_at' => $sale->deleted_at->toIso8601String(),
                'created_at' => $sale->created_at->toIso8601String(),
                'customer' => $sale->customer ? [
                    'id' => $sale->customer->id,
                    'name' => $sale->customer->name,
                ] : null,
            ];
        });

        // Cash/card collected this shift: payment.shift_id (includes deposit top-ups, excludes sale.status filter)
        $fromPayments = $shift->computeMetricsFromShiftPayments();

        $shift->sales_details = [
            'summary' => [
                'total_sold_amount' => (float) $activeSales->sum('total_amount'),
                'sales_count' => $activeSales->count(),
                'voided_sales_count' => $voidedSales->count(),
                'cash_amount' => round($fromPayments['cash_sales'], 2),
                'pos_amount' => round($fromPayments['card_sales'] + $fromPayments['other_sales'], 2),
            ],
            'active_sales' => $salesByPaymentMethod->values(),
            'voided_sales' => $voidedSalesData->values(),
        ];

        return $shift;
    }
}
