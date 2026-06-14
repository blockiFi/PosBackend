<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsRollupService
{
    /**
     * Recompute rollup for one branch-day from raw sales (no rollup reads).
     *
     * @param  string  $saleDateYmd  Calendar day (Y-m-d) used as the rollup bucket key. This is stored in
     *                               `analytics_daily_summaries.sale_date` and is derived from each sale's
     *                               `created_at` date (not `sales.sale_date`).
     */
    public function rebuildDay(int $businessId, int $branchId, string $saleDateYmd): void
    {
        DB::transaction(function () use ($businessId, $branchId, $saleDateYmd) {
            DB::table('analytics_daily_summaries')
                ->where('business_id', $businessId)
                ->where('branch_id', $branchId)
                ->where('sale_date', $saleDateYmd)
                ->delete();

            $rev = DB::table('sales')
                ->whereNull('sales.deleted_at')
                ->where('sales.business_id', $businessId)
                ->where('sales.branch_id', $branchId)
                ->where('sales.status', 'completed')
                ->whereDate('sales.created_at', $saleDateYmd)
                ->selectRaw('COUNT(*) as txn_count, COALESCE(SUM(sales.total_amount), 0) as revenue, COALESCE(SUM(sales.discount_amount), 0) as discount')
                ->first();

            if (! $rev || (int) $rev->txn_count === 0) {
                return;
            }

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
                ->where('sales.branch_id', $branchId)
                ->where('sales.status', 'completed')
                ->whereDate('sales.created_at', $saleDateYmd)
                ->selectRaw(
                    'COALESCE(SUM(sale_items.quantity), 0) as items_sold,'.
                    ' COALESCE(SUM(sale_items.quantity * COALESCE(branch_products.cost_price, products.base_cost_price, 0)), 0) as cost'
                )
                ->first();

            $cost = (float) ($costRow->cost ?? 0);
            $revenue = (float) $rev->revenue;
            $profit = $revenue - $cost;

            DB::table('analytics_daily_summaries')->insert([
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'sale_date' => $saleDateYmd,
                'txn_count' => (int) $rev->txn_count,
                'items_sold' => (int) ($costRow->items_sold ?? 0),
                'revenue' => $revenue,
                'discount' => (float) $rev->discount,
                'cost' => $cost,
                'profit' => $profit,
                'computed_at' => now(),
            ]);
        });
    }

    /**
     * Upsert rollup rows for every (business, branch, day) in range using aggregated SQL.
     */
    public function rollupDateRange(Carbon $from, Carbon $to, ?int $businessId = null): int
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return $this->rollupDateRangeChunkedPhp($from, $to, $businessId);
        }

        $fromStr = $from->format('Y-m-d');
        $toStr = $to->format('Y-m-d');

        $bizFilter = $businessId ? 'AND s.business_id = ?' : '';
        $bindings = [$fromStr, $toStr];
        if ($businessId) {
            $bindings[] = $businessId;
        }
        $bindings[] = $fromStr;
        $bindings[] = $toStr;
        if ($businessId) {
            $bindings[] = $businessId;
        }

        $sql = "
INSERT INTO analytics_daily_summaries (business_id, branch_id, sale_date, txn_count, items_sold, revenue, discount, cost, profit, computed_at)
SELECT
    r.business_id,
    r.branch_id,
    r.sale_date,
    r.txn_count,
    COALESCE(c.items_sold, 0),
    r.revenue,
    r.discount,
    COALESCE(c.cost, 0),
    r.revenue - COALESCE(c.cost, 0),
    NOW()
FROM (
    SELECT
        s.business_id,
        s.branch_id,
        DATE(s.created_at) AS sale_date,
        COUNT(*) AS txn_count,
        COALESCE(SUM(s.total_amount), 0) AS revenue,
        COALESCE(SUM(s.discount_amount), 0) AS discount
    FROM sales s
    WHERE s.deleted_at IS NULL
      AND s.status = 'completed'
      AND DATE(s.created_at) BETWEEN ? AND ?
      {$bizFilter}
    GROUP BY s.business_id, s.branch_id, DATE(s.created_at)
) r
LEFT JOIN (
    SELECT
        s.business_id,
        s.branch_id,
        DATE(s.created_at) AS sale_date,
        COALESCE(SUM(si.quantity), 0) AS items_sold,
        COALESCE(SUM(si.quantity * COALESCE(bp.cost_price, p.base_cost_price, 0)), 0) AS cost
    FROM sale_items si
    INNER JOIN sales s ON s.id = si.sale_id AND s.deleted_at IS NULL AND s.status = 'completed'
    LEFT JOIN branch_products bp ON bp.product_id = si.product_id AND bp.branch_id = s.branch_id AND bp.deleted_at IS NULL
    INNER JOIN products p ON p.id = si.product_id AND p.deleted_at IS NULL
    WHERE DATE(s.created_at) BETWEEN ? AND ?
      {$bizFilter}
    GROUP BY s.business_id, s.branch_id, DATE(s.created_at)
) c ON c.business_id = r.business_id AND c.branch_id = r.branch_id AND c.sale_date = r.sale_date
ON DUPLICATE KEY UPDATE
    txn_count = VALUES(txn_count),
    items_sold = VALUES(items_sold),
    revenue = VALUES(revenue),
    discount = VALUES(discount),
    cost = VALUES(cost),
    profit = VALUES(profit),
    computed_at = VALUES(computed_at)
";

        return DB::affectingStatement($sql, $bindings);
    }

    /**
     * Fallback for sqlite / tests: iterate days (slower but portable).
     */
    protected function rollupDateRangeChunkedPhp(Carbon $from, Carbon $to, ?int $businessId = null): int
    {
        $updated = 0;
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        while ($cursor->lte($end)) {
            $ymd = $cursor->format('Y-m-d');

            $pairs = DB::table('sales')
                ->whereNull('deleted_at')
                ->where('status', 'completed')
                ->whereDate('created_at', $ymd)
                ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
                ->select('business_id', 'branch_id')
                ->distinct()
                ->get();

            foreach ($pairs as $row) {
                $this->rebuildDay((int) $row->business_id, (int) $row->branch_id, $ymd);
                $updated++;
            }

            $cursor->addDay();
        }

        return $updated;
    }
}
