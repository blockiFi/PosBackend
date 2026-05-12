<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AnalyticsController extends Controller
{
    use HasBranchAccess;

    protected function analyticsCacheTtl(): int
    {
        return config('analytics.use_rollups')
            ? (int) config('analytics.rollup_cache_ttl_seconds', 120)
            : (int) config('analytics.cache_ttl_seconds', 900);
    }

    /**
     * File-backed cache + mutex so expensive analytics don't stampede the DB.
     */
    protected function analyticsRemember(string $cacheKey, ?int $ttlSeconds, \Closure $callback): mixed
    {
        $store = Cache::store('file');
        $ttl = $ttlSeconds ?? $this->analyticsCacheTtl();

        $hit = $store->get($cacheKey);
        if ($hit !== null) {
            return $hit;
        }

        return Cache::store('file')->lock('analytics_lock_'.$cacheKey, 120)->block(90, function () use ($store, $cacheKey, $ttl, $callback) {
            $hit = $store->get($cacheKey);
            if ($hit !== null) {
                return $hit;
            }
            $value = $callback();
            $store->put($cacheKey, $value, $ttl);

            return $value;
        });
    }

    protected function resolveTrendGranularity(?string $requested, Carbon $startDate, Carbon $endDate): string
    {
        if (in_array($requested, ['daily', 'weekly', 'monthly'], true)) {
            return $requested;
        }

        $days = $startDate->copy()->startOfDay()->diffInDays($endDate->copy()->startOfDay()) + 1;
        if ($days <= 31) {
            return 'daily';
        }
        if ($days <= 180) {
            return 'weekly';
        }

        return 'monthly';
    }

    /**
     * Get organization-wide analytics
     */
    public function organizationAnalytics(Request $request)
    {
        $agentRunId = bin2hex(random_bytes(4));
        $agentStart = microtime(true);
        // Agent debug: capture slow queries during this request only.
        $slowQueries = [];
        DB::listen(function ($query) use (&$slowQueries, $agentRunId) {
            if (($query->time ?? 0) < 200) {
                return;
            }
            $slowQueries[] = [
                'time_ms' => $query->time,
                'sql' => (string) $query->sql,
            ];
            // Keep memory bounded.
            if (count($slowQueries) > 10) {
                array_shift($slowQueries);
            }
            Log::info('AGENT_DEBUG bea792 analytics.slow_query', [
                'run' => $agentRunId,
                'time_ms' => $query->time,
                'sql' => (string) $query->sql,
            ]);
        });

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
            'start_date' => 'required_if:period,custom|required_with:end_date|date',
            'end_date' => 'required_if:period,custom|required_with:start_date|date|after_or_equal:start_date',
            'compare_previous' => 'sometimes|boolean',
            'granularity' => 'sometimes|in:auto,daily,weekly,monthly',
        ]);

        $period = $request->input('period', 'month');
        $comparePrevious = $request->input('compare_previous', true);
        $startDateInput = $request->input('start_date');
        $endDateInput = $request->input('end_date');

        if ($startDateInput && $endDateInput) {
            $period = 'custom';
        }

        [$startDate, $endDate] = $this->getDateRange($period, $startDateInput, $endDateInput);

        $granularityReq = $request->input('granularity', 'auto');
        $granularity = $this->resolveTrendGranularity(
            $granularityReq === 'auto' ? null : $granularityReq,
            $startDate,
            $endDate
        );

        // NOTE: cache key includes user id because branch_contributions are scoped by user's permitted branches.
        $cacheKey = "org_analytics_{$businessId}_user_{$user->id}_{$period}_{$startDate}_{$endDate}_{$comparePrevious}_{$granularity}";

        Log::info('AGENT_DEBUG bea792 org_analytics:start', [
            'run' => $agentRunId,
            'business_id' => $businessId,
            'period' => $period,
            'start' => $startDate->format('Y-m-d'),
            'end' => $endDate->format('Y-m-d'),
            'compare_previous' => (bool) $comparePrevious,
            'granularity' => $granularity,
            'cache_key' => $cacheKey,
            'cache_hit' => Cache::store('file')->has($cacheKey),
        ]);

        $response = $this->analyticsRemember($cacheKey, null, function () use ($user, $businessId, $startDate, $endDate, $comparePrevious, $granularity) {
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

            // Revenue trend (bucketed by granularity / range)
            $result['revenue_trend'] = $this->getRevenueTrend($businessId, $startDate, $endDate, null, $granularity);

            return response()->json($result);
        });

        Log::info('AGENT_DEBUG bea792 org_analytics:end', [
            'run' => $agentRunId,
            'duration_ms' => (int) round((microtime(true) - $agentStart) * 1000),
            'slow_query_count' => count($slowQueries),
        ]);

        return $response;
    }

    /**
     * Get branch-level analytics
     */
    public function branchAnalytics(Request $request)
    {
        $agentRunId = bin2hex(random_bytes(4));
        $agentStart = microtime(true);
        $slowQueries = [];
        DB::listen(function ($query) use (&$slowQueries, $agentRunId) {
            if (($query->time ?? 0) < 200) {
                return;
            }
            $slowQueries[] = [
                'time_ms' => $query->time,
                'sql' => (string) $query->sql,
            ];
            if (count($slowQueries) > 10) {
                array_shift($slowQueries);
            }
            Log::info('AGENT_DEBUG bea792 analytics.slow_query', [
                'run' => $agentRunId,
                'time_ms' => $query->time,
                'sql' => (string) $query->sql,
            ]);
        });

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
            'granularity' => 'sometimes|in:auto,daily,weekly,monthly',
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

        $granularityReq = $request->input('granularity', 'auto');
        $granularity = $this->resolveTrendGranularity(
            $granularityReq === 'auto' ? null : $granularityReq,
            $startDate,
            $endDate
        );

        Log::info('AGENT_DEBUG bea792 branch_analytics:start', [
            'run' => $agentRunId,
            'business_id' => $businessId,
            'period' => $period,
            'start' => $startDate->format('Y-m-d'),
            'end' => $endDate->format('Y-m-d'),
            'compare_previous' => (bool) $comparePrevious,
            'branch_id' => $request->input('branch_id') ? (string) $request->input('branch_id') : null,
            'branches_count' => count($branches),
            'granularity' => $granularity,
        ]);

        $results = [];
        foreach ($branches as $branchId) {
            $cacheKey = "branch_analytics_{$branchId}_{$period}_{$startDate}_{$endDate}_{$comparePrevious}_{$granularity}";

            $branchData = $this->analyticsRemember($cacheKey, null, function () use ($businessId, $branchId, $startDate, $endDate, $comparePrevious, $granularity) {
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
                $data['revenue_trend'] = $this->getRevenueTrend($businessId, $startDate, $endDate, $branchId, $granularity);

                return $data;
            });

            $results[] = $branchData;
        }

        Log::info('AGENT_DEBUG bea792 branch_analytics:end', [
            'run' => $agentRunId,
            'duration_ms' => (int) round((microtime(true) - $agentStart) * 1000),
            'slow_query_count' => count($slowQueries),
        ]);

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

        return $this->analyticsRemember($cacheKey, null, function () use ($businessId, $branchId, $startDate, $endDate, $limit, $sortBy, $direction) {
            $query = SaleItem::query()
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->join('products', 'sale_items.product_id', '=', 'products.id')
                ->leftJoin('branch_products', function ($join) {
                    $join->on('branch_products.product_id', '=', 'sale_items.product_id')
                        ->on('branch_products.branch_id', '=', 'sales.branch_id');
                })
                ->where('sales.business_id', $businessId)
                ->where('sales.status', 'completed')
                ->whereBetween('sales.created_at', [$startDate, $endDate]);

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

            $stockQuery = BranchProduct::query()
                ->join('products', 'branch_products.product_id', '=', 'products.id')
                ->where('products.business_id', $businessId)
                ->whereNull('branch_products.deleted_at')
                ->whereNull('products.deleted_at')
                ->where('branch_products.stock_quantity', '>', 0);

            if ($branchId) {
                $stockQuery->where('branch_products.branch_id', $branchId);
            }

            $stockAggregates = $stockQuery->selectRaw(
                'SUM(branch_products.stock_quantity) as total_units,
                 SUM(branch_products.stock_quantity * branch_products.selling_price) as total_revenue,
                 SUM(branch_products.stock_quantity * branch_products.cost_price) as total_cost'
            )->first();

            $stockUnits = (int) ($stockAggregates->total_units ?? 0);
            $stockRevenue = (float) ($stockAggregates->total_revenue ?? 0);
            $stockCost = (float) ($stockAggregates->total_cost ?? 0);
            $stockProfit = $stockRevenue - $stockCost;

            $stockByBranchQuery = BranchProduct::query()
                ->join('products', 'branch_products.product_id', '=', 'products.id')
                ->join('branches', 'branch_products.branch_id', '=', 'branches.id')
                ->where('products.business_id', $businessId)
                ->where('branches.business_id', $businessId)
                ->whereNull('branch_products.deleted_at')
                ->whereNull('products.deleted_at')
                ->where('branch_products.stock_quantity', '>', 0);

            if ($branchId) {
                $stockByBranchQuery->where('branch_products.branch_id', $branchId);
            }

            $stockByBranchRows = $stockByBranchQuery->selectRaw(
                'branch_products.branch_id,
                 branches.name as branch_name,
                 SUM(branch_products.stock_quantity) as total_units,
                 SUM(branch_products.stock_quantity * branch_products.selling_price) as total_revenue,
                 SUM(branch_products.stock_quantity * branch_products.cost_price) as total_cost'
            )->groupBy('branch_products.branch_id', 'branches.name')->get();

            $byBranch = $stockByBranchRows->map(function ($row) {
                $revenue = (float) $row->total_revenue;
                $cost = (float) $row->total_cost;
                $profit = $revenue - $cost;

                return [
                    'branch_id' => (int) $row->branch_id,
                    'branch_name' => $row->branch_name,
                    'total_stock_units' => (int) $row->total_units,
                    'total_stock_revenue' => number_format($revenue, 2, '.', ''),
                    'total_stock_cost' => number_format($cost, 2, '.', ''),
                    'total_stock_profit' => number_format($profit, 2, '.', ''),
                ];
            })->values()->all();

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
                'stock_valuation' => [
                    'total_stock_units' => $stockUnits,
                    'total_stock_revenue' => number_format($stockRevenue, 2, '.', ''),
                    'total_stock_cost' => number_format($stockCost, 2, '.', ''),
                    'total_stock_profit' => number_format($stockProfit, 2, '.', ''),
                    'by_branch' => $byBranch,
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

        return $this->analyticsRemember($cacheKey, null, function () use ($businessId, $branchId, $startDate, $endDate) {
            if (config('analytics.use_rollups')) {
                $rollupQuery = DB::table('analytics_daily_summaries')
                    ->where('business_id', $businessId)
                    ->whereBetween('sale_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);

                if ($branchId) {
                    $rollupQuery->where('branch_id', $branchId);
                }

                $agg = $rollupQuery->selectRaw(
                    'SUM(txn_count) as txn_count, SUM(revenue) as revenue, SUM(discount) as discount, SUM(cost) as cost'
                )->first();

                $totalRevenue = (float) ($agg->revenue ?? 0);
                $totalDiscount = (float) ($agg->discount ?? 0);
                $transactionCount = (int) ($agg->txn_count ?? 0);
                $totalCost = (float) ($agg->cost ?? 0);
            } else {
                $salesAgg = Sale::query()
                    ->where('business_id', $businessId)
                    ->where('status', 'completed')
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                    ->selectRaw('COUNT(*) as txn_count, COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(discount_amount), 0) as discount')
                    ->first();

                $transactionCount = (int) ($salesAgg->txn_count ?? 0);
                $totalRevenue = (float) ($salesAgg->revenue ?? 0);
                $totalDiscount = (float) ($salesAgg->discount ?? 0);

                $costRow = DB::table('sale_items')
                    ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                    ->leftJoin('branch_products', function ($join) {
                        $join->on('branch_products.product_id', '=', 'sale_items.product_id')
                            ->on('branch_products.branch_id', '=', 'sales.branch_id')
                            ->whereNull('branch_products.deleted_at');
                    })
                    ->join('products', 'sale_items.product_id', '=', 'products.id')
                    ->whereNull('sales.deleted_at')
                    ->whereNull('products.deleted_at')
                    ->where('sales.business_id', $businessId)
                    ->where('sales.status', 'completed')
                    ->whereBetween('sales.created_at', [$startDate, $endDate])
                    ->when($branchId, fn ($q) => $q->where('sales.branch_id', $branchId))
                    ->selectRaw(
                        'COALESCE(SUM(sale_items.quantity * COALESCE(branch_products.cost_price, products.base_cost_price, 0)), 0) as total_cost'
                    )
                    ->first();

                $totalCost = (float) ($costRow->total_cost ?? 0);
            }

            $grossRevenue = $totalRevenue + $totalDiscount;

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
                    'total_transactions' => $transactionCount,
                    'average_transaction_value' => $transactionCount > 0
                        ? number_format($totalRevenue / $transactionCount, 2, '.', '')
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

        return $this->analyticsRemember($cacheKey, null, function () use ($businessId, $branchId, $interval, $periods) {
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

    private function calculatePeriodMetrics($businessId, $startDate, $endDate, $branchId = null): array
    {
        return config('analytics.use_rollups')
            ? $this->calculatePeriodMetricsFromRollup($businessId, $startDate, $endDate, $branchId)
            : $this->calculatePeriodMetricsLive($businessId, $startDate, $endDate, $branchId);
    }

    private function calculatePeriodMetricsFromRollup($businessId, $startDate, $endDate, $branchId = null): array
    {
        $query = DB::table('analytics_daily_summaries')
            ->where('business_id', $businessId)
            ->whereBetween('sale_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $row = $query->selectRaw(
            'SUM(txn_count) as txn_count, SUM(revenue) as revenue, SUM(cost) as cost'
        )->first();

        $transactionCount = (int) ($row->txn_count ?? 0);
        $revenue = (float) ($row->revenue ?? 0);
        $cost = (float) ($row->cost ?? 0);
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

    private function calculatePeriodMetricsLive($businessId, $startDate, $endDate, $branchId = null): array
    {
        $salesAgg = Sale::query()
            ->where('business_id', $businessId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('COUNT(*) as txn_count, COALESCE(SUM(total_amount), 0) as revenue')
            ->first();

        $transactionCount = (int) ($salesAgg->txn_count ?? 0);
        $revenue = (float) ($salesAgg->revenue ?? 0);

        $costRow = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->leftJoin('branch_products', function ($join) {
                $join->on('branch_products.product_id', '=', 'sale_items.product_id')
                    ->on('branch_products.branch_id', '=', 'sales.branch_id')
                    ->whereNull('branch_products.deleted_at');
            })
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->whereNull('sales.deleted_at')
            ->whereNull('products.deleted_at')
            ->where('sales.business_id', $businessId)
            ->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [$startDate, $endDate])
            ->when($branchId, fn ($q) => $q->where('sales.branch_id', $branchId))
            ->selectRaw(
                'COALESCE(SUM(sale_items.quantity * COALESCE(branch_products.cost_price, products.base_cost_price, 0)), 0) as total_cost'
            )
            ->first();

        $cost = (float) ($costRow->total_cost ?? 0);
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
        return config('analytics.use_rollups')
            ? $this->getBranchContributionsFromRollup($businessId, $startDate, $endDate, $permittedBranches)
            : $this->getBranchContributionsLive($businessId, $startDate, $endDate, $permittedBranches);
    }

    private function getBranchContributionsLive($businessId, $startDate, $endDate, $permittedBranches = null): array
    {
        $branchQuery = Branch::where('business_id', $businessId);
        if ($permittedBranches !== null && $permittedBranches->isNotEmpty()) {
            $branchQuery->whereIn('id', $permittedBranches);
        }
        $branches = $branchQuery->get();

        $revRows = Sale::query()
            ->where('business_id', $businessId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->when($permittedBranches !== null && $permittedBranches->isNotEmpty(), fn ($q) => $q->whereIn('branch_id', $permittedBranches))
            ->selectRaw('branch_id, COUNT(*) as txn_count, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        $costRows = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->leftJoin('branch_products', function ($join) {
                $join->on('branch_products.product_id', '=', 'sale_items.product_id')
                    ->on('branch_products.branch_id', '=', 'sales.branch_id')
                    ->whereNull('branch_products.deleted_at');
            })
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->whereNull('sales.deleted_at')
            ->whereNull('products.deleted_at')
            ->where('sales.business_id', $businessId)
            ->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [$startDate, $endDate])
            ->when($permittedBranches !== null && $permittedBranches->isNotEmpty(), fn ($q) => $q->whereIn('sales.branch_id', $permittedBranches))
            ->groupBy('sales.branch_id')
            ->selectRaw(
                'sales.branch_id as branch_id,'.
                ' COALESCE(SUM(sale_items.quantity * COALESCE(branch_products.cost_price, products.base_cost_price, 0)), 0) as total_cost'
            )
            ->get()
            ->keyBy('branch_id');

        $contributions = [];
        $totalRevenue = 0;

        foreach ($branches as $branch) {
            $rev = $revRows->get($branch->id);
            $costRow = $costRows->get($branch->id);
            $revenue = $rev ? (float) $rev->revenue : 0.0;
            $cost = $costRow ? (float) $costRow->total_cost : 0.0;
            $profit = $revenue - $cost;
            $txnCount = $rev ? (int) $rev->txn_count : 0;

            $totalRevenue += $revenue;

            $contributions[] = [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                '_revenue_float' => $revenue,
                'revenue' => number_format($revenue, 2, '.', ''),
                'profit' => number_format($profit, 2, '.', ''),
                'transaction_count' => $txnCount,
                'contribution_percentage' => '0.00',
            ];
        }

        foreach ($contributions as &$row) {
            $rev = $row['_revenue_float'];
            $row['contribution_percentage'] = number_format(
                $totalRevenue > 0 ? ($rev / $totalRevenue) * 100 : 0,
                2,
                '.',
                ''
            );
            unset($row['_revenue_float']);
        }
        unset($row);

        return collect($contributions)->sortByDesc(fn ($r) => (float) $r['revenue'])->values()->all();
    }

    private function getBranchContributionsFromRollup($businessId, $startDate, $endDate, $permittedBranches = null): array
    {
        $branchQuery = Branch::where('business_id', $businessId);
        if ($permittedBranches !== null && $permittedBranches->isNotEmpty()) {
            $branchQuery->whereIn('id', $permittedBranches);
        }
        $branches = $branchQuery->get();

        $aggRows = DB::table('analytics_daily_summaries')
            ->where('business_id', $businessId)
            ->whereBetween('sale_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->when($permittedBranches !== null && $permittedBranches->isNotEmpty(), fn ($q) => $q->whereIn('branch_id', $permittedBranches))
            ->groupBy('branch_id')
            ->selectRaw('branch_id, SUM(txn_count) as txn_count, SUM(revenue) as revenue, SUM(cost) as cost')
            ->get()
            ->keyBy('branch_id');

        $contributions = [];
        $totalRevenue = 0;

        foreach ($branches as $branch) {
            $agg = $aggRows->get($branch->id);
            $revenue = $agg ? (float) $agg->revenue : 0.0;
            $cost = $agg ? (float) $agg->cost : 0.0;
            $profit = $revenue - $cost;
            $txnCount = $agg ? (int) $agg->txn_count : 0;
            $totalRevenue += $revenue;

            $contributions[] = [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                '_revenue_float' => $revenue,
                'revenue' => number_format($revenue, 2, '.', ''),
                'profit' => number_format($profit, 2, '.', ''),
                'transaction_count' => $txnCount,
                'contribution_percentage' => '0.00',
            ];
        }

        foreach ($contributions as &$row) {
            $rev = $row['_revenue_float'];
            $row['contribution_percentage'] = number_format(
                $totalRevenue > 0 ? ($rev / $totalRevenue) * 100 : 0,
                2,
                '.',
                ''
            );
            unset($row['_revenue_float']);
        }
        unset($row);

        return collect($contributions)->sortByDesc(fn ($r) => (float) $r['revenue'])->values()->all();
    }

    private function getRevenueTrend($businessId, $startDate, $endDate, $branchId = null, ?string $granularity = null)
    {
        $granularity ??= $this->resolveTrendGranularity(null, $startDate, $endDate);

        return config('analytics.use_rollups')
            ? $this->getRevenueTrendFromRollup($businessId, $startDate, $endDate, $branchId, $granularity)
            : $this->getRevenueTrendLive($businessId, $startDate, $endDate, $branchId, $granularity);
    }

    /**
     * @return array{0:\Illuminate\Database\Query\Expression|string, 1:string}
     */
    private function trendBucketSelectExpr(string $granularity, string $dateColumn = 'created_at'): array
    {
        if (! preg_match('/^[a-z_]+$/', $dateColumn)) {
            $dateColumn = 'created_at';
        }

        return match ($granularity) {
            'weekly' => [
                DB::raw("DATE_FORMAT(`{$dateColumn}`, '%x-W%v') as date"),
                "DATE_FORMAT(`{$dateColumn}`, '%x-W%v')",
            ],
            'monthly' => [
                DB::raw("DATE_FORMAT(`{$dateColumn}`, '%Y-%m') as date"),
                "DATE_FORMAT(`{$dateColumn}`, '%Y-%m')",
            ],
            default => [
                DB::raw("DATE(`{$dateColumn}`) as date"),
                "DATE(`{$dateColumn}`)",
            ],
        };
    }

    private function getRevenueTrendLive($businessId, $startDate, $endDate, $branchId, string $granularity)
    {
        [$bucketSelect, $groupSql] = $this->trendBucketSelectExpr($granularity, 'created_at');

        $query = Sale::query()
            ->where('business_id', $businessId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$startDate, $endDate]);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query
            ->select(
                $bucketSelect,
                DB::raw('SUM(total_amount) as revenue'),
                DB::raw('COUNT(*) as transactions')
            )
            ->groupBy(DB::raw($groupSql))
            ->orderBy('date')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'revenue' => number_format($item->revenue, 2, '.', ''),
                    'transactions' => $item->transactions,
                ];
            });
    }

    private function getRevenueTrendFromRollup($businessId, $startDate, $endDate, $branchId, string $granularity)
    {
        [$bucketSelect, $groupSql] = $this->trendBucketSelectExpr($granularity, 'sale_date');

        $query = DB::table('analytics_daily_summaries')
            ->where('business_id', $businessId)
            ->whereBetween('sale_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query
            ->select(
                $bucketSelect,
                DB::raw('SUM(revenue) as revenue'),
                DB::raw('SUM(txn_count) as transactions')
            )
            ->groupBy(DB::raw($groupSql))
            ->orderBy('date')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'revenue' => number_format($item->revenue, 2, '.', ''),
                    'transactions' => $item->transactions,
                ];
            });
    }

    private function getDateRange($period, $customStart = null, $customEnd = null)
    {
        if ($customStart !== null && $customStart !== '' && $customEnd !== null && $customEnd !== '') {
            return [
                Carbon::parse($customStart)->startOfDay(),
                Carbon::parse($customEnd)->endOfDay(),
            ];
        }

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
