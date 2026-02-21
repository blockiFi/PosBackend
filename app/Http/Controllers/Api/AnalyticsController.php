<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    use HasBranchAccess;

    /**
     * Get organization-wide analytics
     */
    public function organizationAnalytics(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json(['message' => 'Business context required'], 400);
        }

        // Check permission
        setPermissionsTeamId($businessId);
        if (! $user->hasPermissionTo('view analytics')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'period' => 'sometimes|in:today,week,month,year,custom',
            'start_date' => 'required_if:period,custom|date',
            'end_date' => 'required_if:period,custom|date|after_or_equal:start_date',
            'compare_previous' => 'sometimes|boolean',
        ]);

        $period = $request->input('period', 'month');
        $comparePrevious = $request->input('compare_previous', true);

        [$startDate, $endDate] = $this->getDateRange($period, $request->input('start_date'), $request->input('end_date'));

        $cacheKey = "org_analytics_{$businessId}_{$period}_{$startDate}_{$endDate}_{$comparePrevious}";

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($user, $businessId, $startDate, $endDate, $comparePrevious) {
            // Current period metrics
            $currentMetrics = $this->calculatePeriodMetrics($businessId, $startDate, $endDate);

            $result = [
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                    'days' => $startDate->diffInDays($endDate) + 1,
                ],
                'current' => $currentMetrics,
            ];

            // Compare with previous period
            if ($comparePrevious) {
                $days = $startDate->diffInDays($endDate) + 1;
                $prevStartDate = $startDate->copy()->subDays($days);
                $prevEndDate = $endDate->copy()->subDays($days);

                $previousMetrics = $this->calculatePeriodMetrics($businessId, $prevStartDate, $prevEndDate);

                $result['previous'] = $previousMetrics;
                $result['comparison'] = $this->calculateComparison($currentMetrics, $previousMetrics);
            }

            // Branch contributions
            // Scope branch contributions to permitted branches
            $permittedBranches = $this->getPermittedBranches($user, $businessId);
            $result['branch_contributions'] = $this->getBranchContributions($businessId, $startDate, $endDate, $permittedBranches);

            // Revenue trend (daily breakdown)
            $result['revenue_trend'] = $this->getRevenueTrend($businessId, $startDate, $endDate);

            return response()->json($result);
        });
    }

    /**
     * Get branch-level analytics
     */
    public function branchAnalytics(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json(['message' => 'Business context required'], 400);
        }

        setPermissionsTeamId($businessId);

        $request->validate([
            'branch_id' => 'sometimes|exists:branches,id',
            'period' => 'sometimes|in:today,week,month,year,custom',
            'start_date' => 'required_if:period,custom|date',
            'end_date' => 'required_if:period,custom|date|after_or_equal:start_date',
            'compare_previous' => 'sometimes|boolean',
        ]);

        // Determine branch access
        $branchId = $request->input('branch_id');

        if ($branchId) {
            // Check if user has access to specific branch
            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            $branches = [$branchId];
        } else {
            // Get all permitted branches
            if (! $user->hasPermissionTo('view analytics')) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            $permittedBranches = $this->getPermittedBranches($user, $businessId);
            // If empty collection, user has business-wide access - get all branches
            if ($permittedBranches->isEmpty()) {
                $branches = Branch::where('business_id', $businessId)->pluck('id')->toArray();
            } else {
                $branches = $permittedBranches->toArray();
            }
        }

        $period = $request->input('period', 'month');
        $comparePrevious = $request->input('compare_previous', true);

        [$startDate, $endDate] = $this->getDateRange($period, $request->input('start_date'), $request->input('end_date'));

        $results = [];
        foreach ($branches as $branchId) {
            $cacheKey = "branch_analytics_{$branchId}_{$period}_{$startDate}_{$endDate}_{$comparePrevious}";

            $branchData = Cache::remember($cacheKey, now()->addMinutes(15), function () use ($businessId, $branchId, $startDate, $endDate, $comparePrevious) {
                $branch = Branch::find($branchId);

                $currentMetrics = $this->calculatePeriodMetrics($businessId, $startDate, $endDate, $branchId);

                $data = [
                    'branch_id' => $branchId,
                    'branch_name' => $branch->name,
                    'period' => [
                        'start_date' => $startDate->format('Y-m-d'),
                        'end_date' => $endDate->format('Y-m-d'),
                        'days' => $startDate->diffInDays($endDate) + 1,
                    ],
                    'current' => $currentMetrics,
                ];

                if ($comparePrevious) {
                    $days = $startDate->diffInDays($endDate) + 1;
                    $prevStartDate = $startDate->copy()->subDays($days);
                    $prevEndDate = $endDate->copy()->subDays($days);

                    $previousMetrics = $this->calculatePeriodMetrics($businessId, $prevStartDate, $prevEndDate, $branchId);

                    $data['previous'] = $previousMetrics;
                    $data['comparison'] = $this->calculateComparison($currentMetrics, $previousMetrics);
                }

                // Revenue trend for this branch
                $data['revenue_trend'] = $this->getRevenueTrend($businessId, $startDate, $endDate, $branchId);

                return $data;
            });

            $results[] = $branchData;
        }

        return response()->json([
            'branches' => $results,
        ]);
    }

    /**
     * Get product performance analytics
     */
    public function productAnalytics(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json(['message' => 'Business context required'], 400);
        }

        setPermissionsTeamId($businessId);
        if (! $user->hasPermissionTo('view analytics')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'branch_id' => 'sometimes|exists:branches,id',
            'period' => 'sometimes|in:today,week,month,year,custom',
            'start_date' => 'required_if:period,custom|date',
            'end_date' => 'required_if:period,custom|date|after_or_equal:start_date',
            'limit' => 'sometimes|integer|min:1|max:100',
            'sort_by' => 'sometimes|in:revenue,quantity,profit,margin',
            'direction' => 'sometimes|in:asc,desc',
        ]);

        $period = $request->input('period', 'month');
        $branchId = $request->input('branch_id');

        // Verify branch access if branch_id provided
        if ($branchId && ! $this->userHasBranchAccess($user, $businessId, $branchId)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $limit = $request->input('limit', 20);
        $sortBy = $request->input('sort_by', 'revenue');
        $direction = $request->input('direction', 'desc');

        [$startDate, $endDate] = $this->getDateRange($period, $request->input('start_date'), $request->input('end_date'));

        $cacheKey = "product_analytics_{$businessId}_{$branchId}_{$period}_{$startDate}_{$endDate}_{$limit}_{$sortBy}_{$direction}";

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($businessId, $branchId, $startDate, $endDate, $limit, $sortBy, $direction) {
            $query = SaleItem::query()
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->join('products', 'sale_items.product_id', '=', 'products.id')
                ->leftJoin('branch_products', function ($join) {
                    $join->on('branch_products.product_id', '=', 'sale_items.product_id')
                        ->on('branch_products.branch_id', '=', 'sales.branch_id');
                })
                ->where('sales.business_id', $businessId)
                ->where('sales.status', 'completed')
                ->whereBetween('sales.sale_date', [$startDate, $endDate]);

            if ($branchId) {
                $query->where('sales.branch_id', $branchId);
            }

            $products = $query->select(
                'sale_items.product_id',
                'products.name as product_name',
                'products.sku as product_sku',
                DB::raw('SUM(sale_items.quantity) as total_quantity'),
                DB::raw('SUM(sale_items.subtotal) as total_revenue'),
                DB::raw('SUM(sale_items.quantity * COALESCE(branch_products.cost_price, products.base_cost_price, 0)) as total_cost'),
                DB::raw('SUM(sale_items.subtotal - (sale_items.quantity * COALESCE(branch_products.cost_price, products.base_cost_price, 0))) as total_profit'),
                DB::raw('COUNT(DISTINCT sales.id) as transaction_count')
            )
                ->groupBy('sale_items.product_id', 'products.name', 'products.sku')
                ->get()
                ->map(function ($item) {
                    $revenue = (float) $item->total_revenue;
                    $cost = (float) $item->total_cost;
                    $profit = (float) $item->total_profit;
                    $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

                    return [
                        'product_id' => $item->product_id,
                        'product_name' => $item->product_name,
                        'product_sku' => $item->product_sku,
                        'quantity_sold' => (int) $item->total_quantity,
                        'revenue' => number_format($revenue, 2, '.', ''),
                        'cost' => number_format($cost, 2, '.', ''),
                        'profit' => number_format($profit, 2, '.', ''),
                        'margin_percentage' => number_format($margin, 2, '.', ''),
                        'transaction_count' => (int) $item->transaction_count,
                    ];
                });

            // Sort
            $sortKey = match ($sortBy) {
                'quantity' => 'quantity_sold',
                'margin' => 'margin_percentage',
                default => $sortBy,
            };

            $products = $products->sortBy([
                [$sortKey, $direction === 'desc' ? 'desc' : 'asc'],
            ])->values();

            $totalRevenue = $products->sum(fn ($p) => (float) $p['revenue']);
            $totalCost = $products->sum(fn ($p) => (float) $p['cost']);
            $totalProfit = $products->sum(fn ($p) => (float) $p['profit']);

            // Add contribution percentage
            $products = $products->map(function ($item) use ($totalRevenue) {
                $item['contribution_percentage'] = $totalRevenue > 0
                    ? number_format(((float) $item['revenue'] / $totalRevenue) * 100, 2, '.', '')
                    : '0.00';

                return $item;
            });

            return response()->json([
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                ],
                'summary' => [
                    'total_products' => $products->count(),
                    'total_revenue' => number_format($totalRevenue, 2, '.', ''),
                    'total_cost' => number_format($totalCost, 2, '.', ''),
                    'total_profit' => number_format($totalProfit, 2, '.', ''),
                    'average_margin' => $totalRevenue > 0
                        ? number_format(($totalProfit / $totalRevenue) * 100, 2, '.', '')
                        : '0.00',
                ],
                'top_products' => $products->take($limit)->values(),
                'bottom_products' => $products->reverse()->take(10)->values(),
            ]);
        });
    }

    /**
     * Get profit and loss statement
     */
    public function profitLoss(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json(['message' => 'Business context required'], 400);
        }

        setPermissionsTeamId($businessId);
        if (! $user->hasPermissionTo('view financial reports')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'branch_id' => 'sometimes|exists:branches,id',
            'period' => 'sometimes|in:today,week,month,quarter,year,custom',
            'start_date' => 'required_if:period,custom|date',
            'end_date' => 'required_if:period,custom|date|after_or_equal:start_date',
        ]);

        $period = $request->input('period', 'month');
        $branchId = $request->input('branch_id');

        // Verify branch access if branch_id provided
        if ($branchId && ! $this->userHasBranchAccess($user, $businessId, $branchId)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        [$startDate, $endDate] = $this->getDateRange($period, $request->input('start_date'), $request->input('end_date'));

        $cacheKey = "pl_statement_{$businessId}_{$branchId}_{$period}_{$startDate}_{$endDate}";

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($businessId, $branchId, $startDate, $endDate) {
            $salesQuery = Sale::where('business_id', $businessId)
                ->where('status', 'completed')
                ->whereBetween('sale_date', [$startDate, $endDate]);

            if ($branchId) {
                $salesQuery->where('branch_id', $branchId);
            }

            // Revenue calculations
            $totalRevenue = (float) $salesQuery->sum('total_amount');
            $totalDiscount = (float) $salesQuery->sum('discount_amount');
            $grossRevenue = $totalRevenue + $totalDiscount;

            // Cost calculations
            $salesWithItems = $salesQuery->with('items')->get();
            $totalCost = 0;
            $totalCost = $this->calculateSalesCost($salesWithItems);

            // Profit calculations
            $grossProfit = $totalRevenue - $totalCost;
            $netProfit = $grossProfit; // Can be extended with operating expenses

            // Margins
            $grossMargin = $totalRevenue > 0 ? ($grossProfit / $totalRevenue) * 100 : 0;
            $netMargin = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0;

            return response()->json([
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                ],
                'revenue' => [
                    'gross_revenue' => number_format($grossRevenue, 2, '.', ''),
                    'discounts' => number_format($totalDiscount, 2, '.', ''),
                    'net_revenue' => number_format($totalRevenue, 2, '.', ''),
                ],
                'costs' => [
                    'cost_of_goods_sold' => number_format($totalCost, 2, '.', ''),
                ],
                'profit' => [
                    'gross_profit' => number_format($grossProfit, 2, '.', ''),
                    'net_profit' => number_format($netProfit, 2, '.', ''),
                ],
                'margins' => [
                    'gross_margin_percentage' => number_format($grossMargin, 2, '.', ''),
                    'net_margin_percentage' => number_format($netMargin, 2, '.', ''),
                ],
                'metrics' => [
                    'total_transactions' => $salesQuery->count(),
                    'average_transaction_value' => $salesQuery->count() > 0
                        ? number_format($totalRevenue / $salesQuery->count(), 2, '.', '')
                        : '0.00',
                ],
            ]);
        });
    }

    /**
     * Get revenue growth trends
     */
    public function growthTrends(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json(['message' => 'Business context required'], 400);
        }

        setPermissionsTeamId($businessId);
        if (! $user->hasPermissionTo('view analytics')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'branch_id' => 'sometimes|exists:branches,id',
            'interval' => 'sometimes|in:daily,weekly,monthly',
            'periods' => 'sometimes|integer|min:1|max:24',
        ]);

        $branchId = $request->input('branch_id');

        // Verify branch access if branch_id provided
        if ($branchId && ! $this->userHasBranchAccess($user, $businessId, $branchId)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $interval = $request->input('interval', 'monthly');
        $periods = $request->input('periods', 12);

        $cacheKey = "growth_trends_{$businessId}_{$branchId}_{$interval}_{$periods}";

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($businessId, $branchId, $interval, $periods) {
            $trends = [];

            for ($i = $periods - 1; $i >= 0; $i--) {
                [$startDate, $endDate] = $this->getIntervalDates($interval, $i);

                $metrics = $this->calculatePeriodMetrics($businessId, $startDate, $endDate, $branchId);

                $trends[] = [
                    'period' => $startDate->format($interval === 'daily' ? 'Y-m-d' : ($interval === 'weekly' ? 'Y-W' : 'Y-m')),
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                    'revenue' => $metrics['revenue'],
                    'profit' => $metrics['profit'],
                    'transactions' => $metrics['transaction_count'],
                    'average_order_value' => $metrics['average_order_value'],
                ];
            }

            // Calculate growth rates
            $trendsWithGrowth = [];
            foreach ($trends as $index => $trend) {
                $growth = null;
                if ($index > 0) {
                    $prevRevenue = (float) $trends[$index - 1]['revenue'];
                    $currentRevenue = (float) $trend['revenue'];
                    $growth = $prevRevenue > 0
                        ? number_format((($currentRevenue - $prevRevenue) / $prevRevenue) * 100, 2, '.', '')
                        : null;
                }
                $trend['revenue_growth_percentage'] = $growth;
                $trendsWithGrowth[] = $trend;
            }

            return response()->json([
                'interval' => $interval,
                'periods' => $periods,
                'trends' => $trendsWithGrowth,
            ]);
        });
    }

    // Helper Methods

    private function calculateSalesCost($sales): float
    {
        $totalCost = 0;

        foreach ($sales as $sale) {
            $productIds = $sale->items->pluck('product_id')->unique();

            if ($productIds->isEmpty()) {
                continue;
            }

            $branchCosts = BranchProduct::query()
                ->where('branch_id', $sale->branch_id)
                ->whereIn('product_id', $productIds)
                ->pluck('cost_price', 'product_id');

            $productCosts = Product::query()
                ->whereIn('id', $productIds)
                ->pluck('base_cost_price', 'id');

            foreach ($sale->items as $item) {
                $unitCost = $branchCosts->get($item->product_id);
                if ($unitCost === null) {
                    $unitCost = $productCosts->get($item->product_id, 0);
                }

                $totalCost += $item->quantity * $unitCost;
            }
        }

        return $totalCost;
    }

    private function calculatePeriodMetrics($businessId, $startDate, $endDate, $branchId = null)
    {
        $query = Sale::where('business_id', $businessId)
            ->where('status', 'completed')
            ->whereBetween('sale_date', [$startDate, $endDate]);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $sales = $query->with('items')->get();

        $revenue = $sales->sum('total_amount');
        $transactionCount = $sales->count();

        $cost = $this->calculateSalesCost($sales);

        $profit = $revenue - $cost;
        $averageOrderValue = $transactionCount > 0 ? $revenue / $transactionCount : 0;
        $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

        return [
            'revenue' => number_format($revenue, 2, '.', ''),
            'cost' => number_format($cost, 2, '.', ''),
            'profit' => number_format($profit, 2, '.', ''),
            'margin_percentage' => number_format($margin, 2, '.', ''),
            'transaction_count' => $transactionCount,
            'average_order_value' => number_format($averageOrderValue, 2, '.', ''),
        ];
    }

    private function calculateComparison($current, $previous)
    {
        $revenueChange = $this->calculatePercentageChange(
            (float) $previous['revenue'],
            (float) $current['revenue']
        );

        $profitChange = $this->calculatePercentageChange(
            (float) $previous['profit'],
            (float) $current['profit']
        );

        $transactionChange = $this->calculatePercentageChange(
            $previous['transaction_count'],
            $current['transaction_count']
        );

        return [
            'revenue_change_percentage' => $revenueChange,
            'profit_change_percentage' => $profitChange,
            'transaction_change_percentage' => $transactionChange,
            'revenue_trend' => $this->getTrend($revenueChange),
            'profit_trend' => $this->getTrend($profitChange),
        ];
    }

    private function calculatePercentageChange($previous, $current)
    {
        if ($previous == 0) {
            return $current > 0 ? '100.00' : '0.00';
        }

        $change = (($current - $previous) / abs($previous)) * 100;

        return number_format($change, 2, '.', '');
    }

    private function getTrend($changePercentage)
    {
        $change = (float) $changePercentage;
        if ($change > 0) {
            return 'up';
        }
        if ($change < 0) {
            return 'down';
        }

        return 'stable';
    }

    private function getBranchContributions($businessId, $startDate, $endDate, $permittedBranches = null)
    {
        $query = Branch::where('business_id', $businessId);
        if ($permittedBranches !== null && $permittedBranches->isNotEmpty()) {
            $query->whereIn('id', $permittedBranches);
        }
        $branches = $query->get();
        $contributions = [];

        $totalRevenue = 0;
        $branchMetrics = [];

        foreach ($branches as $branch) {
            $metrics = $this->calculatePeriodMetrics($businessId, $startDate, $endDate, $branch->id);
            $branchMetrics[$branch->id] = $metrics;
            $totalRevenue += (float) $metrics['revenue'];
        }

        foreach ($branches as $branch) {
            $metrics = $branchMetrics[$branch->id];
            $revenue = (float) $metrics['revenue'];
            $contribution = $totalRevenue > 0 ? ($revenue / $totalRevenue) * 100 : 0;

            $contributions[] = [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'revenue' => $metrics['revenue'],
                'profit' => $metrics['profit'],
                'transaction_count' => $metrics['transaction_count'],
                'contribution_percentage' => number_format($contribution, 2, '.', ''),
            ];
        }

        return collect($contributions)->sortByDesc('revenue')->values()->all();
    }

    private function getRevenueTrend($businessId, $startDate, $endDate, $branchId = null)
    {
        $query = Sale::where('business_id', $businessId)
            ->where('status', 'completed')
            ->whereBetween('sale_date', [$startDate, $endDate]);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $dailyRevenue = $query->select(
            DB::raw('DATE(sale_date) as date'),
            DB::raw('SUM(total_amount) as revenue'),
            DB::raw('COUNT(*) as transactions')
        )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'revenue' => number_format($item->revenue, 2, '.', ''),
                    'transactions' => $item->transactions,
                ];
            });

        return $dailyRevenue;
    }

    private function getDateRange($period, $customStart = null, $customEnd = null)
    {
        $endDate = Carbon::now();

        switch ($period) {
            case 'today':
                $startDate = Carbon::today();
                break;
            case 'week':
                $startDate = Carbon::now()->startOfWeek();
                break;
            case 'month':
                $startDate = Carbon::now()->startOfMonth();
                break;
            case 'quarter':
                $startDate = Carbon::now()->startOfQuarter();
                break;
            case 'year':
                $startDate = Carbon::now()->startOfYear();
                break;
            case 'custom':
                $startDate = Carbon::parse($customStart);
                $endDate = Carbon::parse($customEnd);
                break;
            default:
                $startDate = Carbon::now()->startOfMonth();
        }

        return [$startDate, $endDate];
    }

    private function getIntervalDates($interval, $periodsAgo)
    {
        switch ($interval) {
            case 'daily':
                $start = Carbon::now()->subDays($periodsAgo)->startOfDay();
                $end = $start->copy()->endOfDay();
                break;
            case 'weekly':
                $start = Carbon::now()->subWeeks($periodsAgo)->startOfWeek();
                $end = $start->copy()->endOfWeek();
                break;
            case 'monthly':
                $start = Carbon::now()->subMonths($periodsAgo)->startOfMonth();
                $end = $start->copy()->endOfMonth();
                break;
            default:
                $start = Carbon::now()->subMonths($periodsAgo)->startOfMonth();
                $end = $start->copy()->endOfMonth();
        }

        return [$start, $end];
    }
}
