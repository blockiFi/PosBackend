<?php

namespace App\Services;

use App\Support\ReportingTimezone;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AnalyticsRollupService
{
    /**
     * Recompute rollup for one branch-day from raw sales (no rollup reads).
     *
     * @param  string  $saleDateYmd  Local calendar day (Y-m-d) in the branch's reporting timezone
     *                               (branch, else business time_zone, else Africa/Lagos). Stored in
     *                               `analytics_daily_summaries.sale_date`; covers the sales whose
     *                               `sales.sale_date` (UTC) falls inside that local day.
     */
    public function rebuildDay(int $businessId, int $branchId, string $saleDateYmd, ?string $timezone = null): void
    {
        $timezone ??= ReportingTimezone::forBranch($branchId);
        [$fromUtc, $toUtc] = ReportingTimezone::utcBoundsForLocalDay($saleDateYmd, $timezone);

        DB::transaction(function () use ($businessId, $branchId, $saleDateYmd, $fromUtc, $toUtc) {
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
                ->whereBetween('sales.sale_date', [$fromUtc, $toUtc])
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
                ->whereBetween('sales.sale_date', [$fromUtc, $toUtc])
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
     * The rollup day (branch-local Y-m-d) a sale with this UTC sale_date belongs to.
     */
    public function rollupDayFor(int $branchId, CarbonInterface|string $saleDate): string
    {
        return ReportingTimezone::localDay($saleDate, ReportingTimezone::forBranch($branchId));
    }

    /**
     * Rebuild rollup rows for every branch-day in the local calendar range [from, to].
     *
     * Days are branch-local, so this walks the range day by day in PHP and filters each
     * day by its UTC bounds (portable across mysql, pgsql and sqlite). A branch-day is
     * rebuilt when it has completed sales or an existing rollup row, so rows left by an
     * earlier bucketing are removed.
     *
     * @return int Number of branch-days rebuilt.
     */
    public function rollupDateRange(Carbon $from, Carbon $to, ?int $businessId = null): int
    {
        $branchesByTimezone = DB::table('branches')
            ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
            ->get(['id', 'business_id'])
            ->groupBy(fn (object $branch): string => ReportingTimezone::forBranch($branch->id));

        $firstDay = $from->format('Y-m-d');
        $lastDay = $to->format('Y-m-d');
        $updated = 0;

        foreach ($branchesByTimezone as $timezone => $branches) {
            $branchIds = $branches->pluck('id')->all();
            $businessByBranch = $branches->pluck('business_id', 'id');

            $cursor = Carbon::createFromFormat('Y-m-d', $firstDay, $timezone)->startOfDay();
            while ($cursor->format('Y-m-d') <= $lastDay) {
                $ymd = $cursor->format('Y-m-d');
                [$fromUtc, $toUtc] = ReportingTimezone::utcBoundsForLocalDay($ymd, $timezone);

                $withSales = DB::table('sales')
                    ->whereNull('deleted_at')
                    ->where('status', 'completed')
                    ->whereIn('branch_id', $branchIds)
                    ->whereBetween('sale_date', [$fromUtc, $toUtc])
                    ->distinct()
                    ->pluck('branch_id');

                $withRollups = DB::table('analytics_daily_summaries')
                    ->whereIn('branch_id', $branchIds)
                    ->where('sale_date', $ymd)
                    ->pluck('branch_id');

                foreach ($withSales->merge($withRollups)->unique() as $branchId) {
                    $this->rebuildDay((int) $businessByBranch[$branchId], (int) $branchId, $ymd, $timezone);
                    $updated++;
                }

                $cursor->addDay();
            }
        }

        return $updated;
    }
}
