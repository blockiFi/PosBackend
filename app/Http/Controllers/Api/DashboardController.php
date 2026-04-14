<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesShift;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use HasBranchAccess;

    public function summary(Request $request)
    {
        $user = $request->user();
        $businessId = (int) ($request->current_business_id ?? 0);

        if (! $businessId) {
            return response()->json(['message' => 'Business context required'], 400);
        }

        setPermissionsTeamId($businessId);

        $canViewAnalytics = $user->hasPermissionTo('view analytics');
        $canViewSales = $user->hasPermissionTo('view sales');
        $canViewInventory = $user->hasPermissionTo('view inventory');
        $canViewAllShifts = $user->hasPermissionTo('view all shifts');
        $canViewOwnShifts = $user->hasPermissionTo('view user shift');

        // v2: revenue must not be summed across sale_items join (that double-counts total_amount).
        $cacheKey = "dashboard_summary_v2_{$businessId}_{$user->id}";
        $cached = Cache::get($cacheKey);

        $branchesCount = (int) Branch::where('business_id', $businessId)->count();
        $productsCount = (int) Product::where('business_id', $businessId)->count();

        $openShiftsCount = 0;
        if ($canViewAllShifts || $canViewOwnShifts) {
            $shiftQuery = SalesShift::query()->where('business_id', $businessId)->where('status', 'open');
            if (! $canViewAllShifts && $canViewOwnShifts) {
                $shiftQuery->where('user_id', $user->id);
            }
            $accessibleBranches = $user->getBranchesInBusiness($businessId);
            if ($accessibleBranches->isNotEmpty()) {
                $shiftQuery->whereIn('branch_id', $accessibleBranches->pluck('id'));
            }
            $openShiftsCount = (int) $shiftQuery->count();
        }

        $lowStockCount = 0;
        $outOfStockCount = 0;
        if ($canViewInventory) {
            $accessibleBranches = $user->getBranchesInBusiness($businessId);
            $bpQuery = BranchProduct::query();
            if ($accessibleBranches->isNotEmpty()) {
                $bpQuery->whereIn('branch_id', $accessibleBranches->pluck('id'));
            } else {
                $bpQuery->whereHas('branch', function ($q) use ($businessId) {
                    $q->where('business_id', $businessId);
                });
            }

            $lowStockCount = (int) (clone $bpQuery)
                ->where('stock_quantity', '>', 0)
                ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
                ->count();

            $outOfStockCount = (int) (clone $bpQuery)
                ->where('stock_quantity', '<=', 0)
                ->count();
        }

        $analytics = null;
        if ($canViewAnalytics) {
            $startDate = Carbon::now()->startOfMonth()->startOfDay();
            $endDate = Carbon::now()->endOfDay();
            $days = $startDate->diffInDays($endDate) + 1;
            $prevStartDate = $startDate->copy()->subDays($days);
            $prevEndDate = $endDate->copy()->subDays($days);

            $canUseCache =
                is_array($cached) &&
                isset($cached['computed_at']) &&
                is_string($cached['computed_at']) &&
                isset($cached['analytics']) &&
                is_array($cached['analytics']) &&
                isset($cached['analytics']['current']) &&
                is_array($cached['analytics']['current']) &&
                isset($cached['analytics']['previous']) &&
                is_array($cached['analytics']['previous']);

            if ($canUseCache) {
                try {
                    $computedAt = Carbon::parse($cached['computed_at']);
                    // Only apply incremental merge when cached snapshot belongs to the current month window.
                    if ($computedAt->greaterThanOrEqualTo($startDate) && $computedAt->lessThanOrEqualTo($endDate)) {
                        $delta = $this->salesMetricsDelta($businessId, $computedAt, $endDate);
                        $current = $this->mergePeriodMetrics($cached['analytics']['current'], $delta);
                        $previous = $cached['analytics']['previous'];
                        $comparison = $this->comparison($current, $previous);
                    } else {
                        $current = $this->salesMetrics($businessId, $startDate, $endDate);
                        $previous = $this->salesMetrics($businessId, $prevStartDate, $prevEndDate);
                        $comparison = $this->comparison($current, $previous);
                    }
                } catch (\Throwable $e) {
                    $current = $this->salesMetrics($businessId, $startDate, $endDate);
                    $previous = $this->salesMetrics($businessId, $prevStartDate, $prevEndDate);
                    $comparison = $this->comparison($current, $previous);
                }
            } else {
                $current = $this->salesMetrics($businessId, $startDate, $endDate);
                $previous = $this->salesMetrics($businessId, $prevStartDate, $prevEndDate);
                $comparison = $this->comparison($current, $previous);
            }

            $analytics = [
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                    'days' => $days,
                ],
                'current' => $current,
                'previous' => $previous,
                'comparison' => $comparison,
            ];
        }

        $recentSales = [];
        if ($canViewSales) {
            $sales = Sale::query()
                ->where('business_id', $businessId)
                ->orderBy('sale_date', 'desc')
                ->limit(5)
                ->get(['id', 'sale_number', 'total_amount', 'status', 'sale_date', 'created_at']);

            $recentSales = $sales->map(function (Sale $sale) {
                return [
                    'id' => $sale->id,
                    'sale_number' => $sale->sale_number,
                    'total_amount' => $sale->total_amount,
                    'status' => $sale->status,
                    'sale_date' => optional($sale->sale_date)->toIso8601String(),
                    'created_at' => optional($sale->created_at)->toIso8601String(),
                ];
            })->all();
        }

        $payload = [
            'computed_at' => Carbon::now()->toIso8601String(),
            'analytics' => $analytics,
            'counts' => [
                'branches' => $branchesCount,
                'products' => $productsCount,
                'open_shifts' => $openShiftsCount,
                'low_stock' => $lowStockCount,
                'out_of_stock' => $outOfStockCount,
            ],
            'recent_sales' => $recentSales,
        ];

        // 10 minutes TTL safety net (even if no requests)
        Cache::put($cacheKey, $payload, now()->addMinutes(10));

        return response()->json($payload);
    }

    private function salesMetrics(int $businessId, Carbon $startDate, Carbon $endDate): array
    {
        $totals = DB::table('sales as s')
            ->where('s.business_id', $businessId)
            ->where('s.status', 'completed')
            ->whereBetween('s.sale_date', [$startDate, $endDate])
            ->selectRaw('COALESCE(SUM(s.total_amount),0) as revenue')
            ->selectRaw('COALESCE(COUNT(s.id),0) as transaction_count')
            ->first();

        $costRow = DB::table('sales as s')
            ->join('sale_items as si', 'si.sale_id', '=', 's.id')
            ->leftJoin('branch_products as bp', function ($join) {
                $join->on('bp.product_id', '=', 'si.product_id')
                    ->on('bp.branch_id', '=', 's.branch_id');
            })
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->where('s.business_id', $businessId)
            ->where('s.status', 'completed')
            ->whereBetween('s.sale_date', [$startDate, $endDate])
            ->selectRaw('COALESCE(SUM(si.quantity * COALESCE(bp.cost_price, p.base_cost_price, 0)),0) as cost')
            ->first();

        $revenue = (float) ($totals->revenue ?? 0);
        $cost = (float) ($costRow->cost ?? 0);
        $tx = (int) ($totals->transaction_count ?? 0);
        $profit = $revenue - $cost;
        $aov = $tx > 0 ? $revenue / $tx : 0;
        $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

        return [
            'revenue' => number_format($revenue, 2, '.', ''),
            'cost' => number_format($cost, 2, '.', ''),
            'profit' => number_format($profit, 2, '.', ''),
            'margin_percentage' => number_format($margin, 2, '.', ''),
            'transaction_count' => $tx,
            'average_order_value' => number_format($aov, 2, '.', ''),
        ];
    }

    private function salesMetricsDelta(int $businessId, Carbon $since, Carbon $endDate): array
    {
        // Delta scan: only sales strictly after last computed_at.
        $totals = DB::table('sales as s')
            ->where('s.business_id', $businessId)
            ->where('s.status', 'completed')
            ->where('s.sale_date', '>', $since)
            ->where('s.sale_date', '<=', $endDate)
            ->selectRaw('COALESCE(SUM(s.total_amount),0) as revenue')
            ->selectRaw('COALESCE(COUNT(s.id),0) as transaction_count')
            ->first();

        $costRow = DB::table('sales as s')
            ->join('sale_items as si', 'si.sale_id', '=', 's.id')
            ->leftJoin('branch_products as bp', function ($join) {
                $join->on('bp.product_id', '=', 'si.product_id')
                    ->on('bp.branch_id', '=', 's.branch_id');
            })
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->where('s.business_id', $businessId)
            ->where('s.status', 'completed')
            ->where('s.sale_date', '>', $since)
            ->where('s.sale_date', '<=', $endDate)
            ->selectRaw('COALESCE(SUM(si.quantity * COALESCE(bp.cost_price, p.base_cost_price, 0)),0) as cost')
            ->first();

        return [
            'revenue' => (float) ($totals->revenue ?? 0),
            'cost' => (float) ($costRow->cost ?? 0),
            'transaction_count' => (int) ($totals->transaction_count ?? 0),
        ];
    }

    private function mergePeriodMetrics(array $base, array $delta): array
    {
        $baseRevenue = (float) ($base['revenue'] ?? 0);
        $baseCost = (float) ($base['cost'] ?? 0);
        $baseTx = (int) ($base['transaction_count'] ?? 0);

        $deltaRevenue = (float) ($delta['revenue'] ?? 0);
        $deltaCost = (float) ($delta['cost'] ?? 0);
        $deltaTx = (int) ($delta['transaction_count'] ?? 0);

        $revenue = $baseRevenue + $deltaRevenue;
        $cost = $baseCost + $deltaCost;
        $tx = $baseTx + $deltaTx;
        $profit = $revenue - $cost;
        $aov = $tx > 0 ? $revenue / $tx : 0;
        $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

        return [
            'revenue' => number_format($revenue, 2, '.', ''),
            'cost' => number_format($cost, 2, '.', ''),
            'profit' => number_format($profit, 2, '.', ''),
            'margin_percentage' => number_format($margin, 2, '.', ''),
            'transaction_count' => $tx,
            'average_order_value' => number_format($aov, 2, '.', ''),
        ];
    }

    private function comparison(array $current, array $previous): array
    {
        $revenueChange = $this->pct((float) ($previous['revenue'] ?? 0), (float) ($current['revenue'] ?? 0));
        $profitChange = $this->pct((float) ($previous['profit'] ?? 0), (float) ($current['profit'] ?? 0));
        $txChange = $this->pct((float) ($previous['transaction_count'] ?? 0), (float) ($current['transaction_count'] ?? 0));

        return [
            'revenue_change_percentage' => $revenueChange,
            'profit_change_percentage' => $profitChange,
            'transaction_change_percentage' => $txChange,
            'revenue_trend' => $this->trend($revenueChange),
            'profit_trend' => $this->trend($profitChange),
        ];
    }

    private function pct(float $previous, float $current): string
    {
        if ($previous == 0.0) {
            return $current > 0 ? '100.00' : '0.00';
        }
        $change = (($current - $previous) / abs($previous)) * 100;

        return number_format($change, 2, '.', '');
    }

    private function trend(string $changePercentage): string
    {
        $change = (float) $changePercentage;
        if ($change > 0) return 'up';
        if ($change < 0) return 'down';

        return 'stable';
    }
}

