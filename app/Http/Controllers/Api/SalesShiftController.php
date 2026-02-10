<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesShift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesShiftController extends Controller
{
    /**
     * List shifts with filtering and statistics
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        $canViewAll = $user->hasPermissionTo('view all shifts');
        $canViewOwn = $user->hasPermissionTo('view user shift');

        if (!$canViewAll && !$canViewOwn) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = SalesShift::with(['user', 'branch'])
            ->forBusiness($businessId);

        // If user can only view their own shifts, filter by user_id
        if (!$canViewAll && $canViewOwn) {
            $query->where('user_id', $user->id);
        }

        // Filter by branch
        if ($request->filled('branch_id')) {
            $branchId = $request->branch_id;
            if (!$this->userHasBranchAccess($user, $businessId, $branchId)) {
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

        // Filter by discrepancies
        if ($request->boolean('has_discrepancy')) {
            $query->where('status', 'closed')
                  ->where(function ($q) {
                      $q->whereRaw('ABS(variance) >= 0.01');
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

        $shifts = $query->orderBy('start_time', 'desc')->paginate(15);

        // Enhance each shift with statistics
        $shifts->getCollection()->transform(function ($shift) {
            return $this->enrichShiftWithStats($shift);
        });

        return response()->json($shifts);
    }

    /**
     * Open a new shift
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if (!$user->hasPermissionTo('create shift')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'opening_balance' => 'required|numeric|min:0',
            'opening_notes' => 'nullable|string',
        ]);

        // Check branch access
        if (!$this->userHasBranchAccess($user, $businessId, $validated['branch_id'])) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        // Business Rule: Each user can only have ONE active shift at a time across all branches
        // However, a branch CAN have MULTIPLE active shifts (one per user/cashier)
        $openShift = SalesShift::forBusiness($businessId)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->first();

        if ($openShift) {
            return response()->json([
                'message' => 'You already have an open shift. Please close your current shift before opening a new one.',
                'current_shift' => [
                    'id' => $openShift->id,
                    'shift_number' => $openShift->shift_number,
                    'branch_id' => $openShift->branch_id,
                    'branch_name' => $openShift->branch->name ?? null,
                    'opened_at' => $openShift->start_time->toIso8601String(),
                ],
            ], 400);
        }

        DB::beginTransaction();
        try {
            $shiftNumber = $this->generateShiftNumber($businessId);

            $shift = SalesShift::create([
                'shift_number' => $shiftNumber,
                'business_id' => $businessId,
                'branch_id' => $validated['branch_id'],
                'user_id' => $user->id,
                'start_time' => now(),
                'opening_balance' => $validated['opening_balance'],
                'opening_notes' => $validated['opening_notes'] ?? null,
                'status' => 'open',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Shift opened successfully',
                'shift' => $shift->load(['user', 'branch']),
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

        $canViewAll = $user->hasPermissionTo('view all shifts');
        $canViewOwn = $user->hasPermissionTo('view user shift');

        if (!$canViewAll && !$canViewOwn) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $shift = SalesShift::with(['user', 'branch', 'sales' => function ($query) {
                $query->with(['payments.paymentMethod', 'customer', 'items.product'])
                      ->withTrashed(); // Include voided/cancelled sales
            }])
            ->forBusiness($businessId)
            ->findOrFail($id);

        // Check branch access
        if (!$this->userHasBranchAccess($user, $businessId, $shift->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        // If user can only view their own shifts, verify ownership
        if (!$canViewAll && $canViewOwn && $shift->user_id !== $user->id) {
            return response()->json(['message' => 'You can only view your own shifts'], 403);
        }

        // Enrich with statistics and sales details
        $shift = $this->enrichShiftWithStats($shift);
        $shift = $this->enrichShiftWithSalesDetails($shift);

        return response()->json($shift);
    }

    /**
     * Close a shift
     */
    public function close(Request $request, $id)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if (!$user->hasPermissionTo('close shift')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'actual_cash' => 'required|numeric|min:0',
            'closing_notes' => 'nullable|string',
        ]);

        $shift = SalesShift::forBusiness($businessId)->findOrFail($id);

        // Check branch access
        if (!$this->userHasBranchAccess($user, $businessId, $shift->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        if ($shift->status === 'closed') {
            return response()->json(['message' => 'Shift is already closed'], 400);
        }

        // Only the shift owner or someone with manage shifts permission can close
        if ($shift->user_id !== $user->id && !$user->hasPermissionTo('manage shifts')) {
            return response()->json(['message' => 'You can only close your own shifts'], 403);
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
     * Get current open shift for user
     */
    public function current(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        $shift = SalesShift::with(['user', 'branch'])
            ->forBusiness($businessId)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->first();

        if (!$shift) {
            return response()->json(['message' => 'No open shift found'], 404);
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

        if (!$user->hasPermissionTo('manage shifts')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'resolution_notes' => 'required|string|max:1000',
        ]);

        $shift = SalesShift::forBusiness($businessId)->findOrFail($id);

        // Check branch access
        if (!$this->userHasBranchAccess($user, $businessId, $shift->branch_id)) {
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

        $canViewAll = $user->hasPermissionTo('view all shifts');
        $canViewOwn = $user->hasPermissionTo('view user shift');

        if (!$canViewAll && !$canViewOwn) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $shift = SalesShift::forBusiness($businessId)->findOrFail($id);

        // Check branch access
        if (!$this->userHasBranchAccess($user, $businessId, $shift->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        // If user can only view their own shifts, verify ownership
        if (!$canViewAll && $canViewOwn && $shift->user_id !== $user->id) {
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
                $q->where('name', 'like', '%' . $request->payment_method . '%');
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
     * Generate unique shift number
     */
    private function generateShiftNumber($businessId): string
    {
        $prefix = 'SHIFT';
        $date = now()->format('Ymd');
        $lastShift = SalesShift::forBusiness($businessId)
            ->whereDate('created_at', now())
            ->orderBy('id', 'desc')
            ->first();

        $sequence = $lastShift ? (intval(substr($lastShift->shift_number, -4)) + 1) : 1;
        
        return sprintf('%s-%s-%04d', $prefix, $date, $sequence);
    }

    /**
     * Enrich shift with statistics
     */
    private function enrichShiftWithStats($shift)
    {
        $totalSales = $shift->total_sales ?? 0;
        $cashSales = $shift->cash_sales ?? 0;
        $cardSales = $shift->card_sales ?? 0;
        $transactionsCount = $shift->transactions_count ?? 0;
        
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
        $reconciliationStatus = !$hasDiscrepancy ? 'balanced' : ($shift->discrepancy_resolved ? 'resolved' : 'discrepancy');

        // Calculate shift duration
        $startTime = $shift->start_time;
        $endTime = $shift->end_time ?? now();
        $durationInMinutes = $startTime->diffInMinutes($endTime);
        $durationFormatted = sprintf('%dh %dm', floor($durationInMinutes / 60), $durationInMinutes % 60);

        // Add statistics to shift
        $shift->statistics = [
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
        if (!$shift->relationLoaded('sales')) {
            return $shift;
        }

        $sales = $shift->sales;
        $activeSales = $sales->filter(fn($sale) => !$sale->trashed());
        $voidedSales = $sales->filter(fn($sale) => $sale->trashed());

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

        // Calculate sales summary
        $cashAmount = 0;
        $posAmount = 0;

        foreach ($activeSales as $sale) {
            foreach ($sale->payments as $payment) {
                $methodName = strtolower($payment->paymentMethod->name ?? '');
                if (in_array($methodName, ['cash', 'cash payment'])) {
                    $cashAmount += $payment->amount;
                } else {
                    $posAmount += $payment->amount;
                }
            }
        }

        $shift->sales_details = [
            'summary' => [
                'total_sold_amount' => (float) $activeSales->sum('total_amount'),
                'sales_count' => $activeSales->count(),
                'voided_sales_count' => $voidedSales->count(),
                'cash_amount' => round($cashAmount, 2),
                'pos_amount' => round($posAmount, 2),
            ],
            'active_sales' => $salesByPaymentMethod->values(),
            'voided_sales' => $voidedSalesData->values(),
        ];

        return $shift;
    }

    /**
     * Check if user has access to a specific branch
     */
    private function userHasBranchAccess($user, $businessId, $branchId): bool
    {
        $accessibleBranches = $user->getBranchesInBusiness($businessId);
        
        // Empty collection means user has access to all branches
        if ($accessibleBranches->isEmpty()) {
            return true;
        }
        
        return $accessibleBranches->contains('id', $branchId);
    }
}
