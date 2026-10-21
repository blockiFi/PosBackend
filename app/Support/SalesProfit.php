<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Profit on completed sales: what the customer paid (sales.total_amount) minus what the items
 * cost (branch cost_price, else the product's base_cost_price), the same rule the owner
 * overview uses. Items with no cost price count as costing nothing, so they are also counted
 * in items_without_cost for screens to warn that profit is shown too high.
 *
 * Both queries join `sales_shifts as shifts` so $scope can filter on the shift (status,
 * group_id, id) as well as on the sale.
 */
final class SalesProfit
{
    private const ITEM_COST = 'COALESCE(branch_products.cost_price, products.base_cost_price, 0)';

    /**
     * @param  string|null  $groupColumn  e.g. 'shifts.group_id' or 'sales.shift_id'; null for one total
     * @param  callable(Builder): void  $scope
     * @return Collection<string, array{sales_count: int, revenue: float, cost: float, profit: float, items_without_cost: int}>
     *                            keyed by the group value ('' for null); with no $groupColumn the only key is 'all'
     */
    public static function grouped(?string $groupColumn, callable $scope): Collection
    {
        $salesQuery = self::baseSales(DB::table('sales'));
        $scope($salesQuery);
        $sales = $salesQuery
            ->selectRaw(($groupColumn ?? "'all'").' as group_key, COUNT(*) as sales_count, COALESCE(SUM(sales.total_amount), 0) as revenue')
            ->when($groupColumn, fn ($q) => $q->groupBy($groupColumn))
            ->get()
            ->keyBy(fn ($row) => (string) $row->group_key);

        $costQuery = self::baseSales(
            DB::table('sale_items')->join('sales', 'sale_items.sale_id', '=', 'sales.id')
        )
            ->leftJoin('branch_products', function ($join) {
                $join->on('branch_products.product_id', '=', 'sale_items.product_id')
                    ->on('branch_products.branch_id', '=', 'sales.branch_id')
                    ->whereNull('branch_products.deleted_at');
            })
            ->leftJoin('products', 'sale_items.product_id', '=', 'products.id');
        $scope($costQuery);
        $costs = $costQuery
            ->selectRaw(
                ($groupColumn ?? "'all'").' as group_key,'.
                ' COALESCE(SUM(sale_items.quantity * '.self::ITEM_COST.'), 0) as cost,'.
                ' COUNT(DISTINCT CASE WHEN '.self::ITEM_COST.' <= 0 THEN sale_items.product_id END) as items_without_cost'
            )
            ->when($groupColumn, fn ($q) => $q->groupBy($groupColumn))
            ->get()
            ->keyBy(fn ($row) => (string) $row->group_key);

        if ($groupColumn === null && $sales->isEmpty()) {
            $sales = collect(['all' => (object) ['sales_count' => 0, 'revenue' => 0]]);
        }

        return $sales->map(function ($row, $groupKey) use ($costs) {
            $revenue = (float) $row->revenue;
            $costRow = $costs->get($groupKey);
            $cost = $costRow ? (float) $costRow->cost : 0.0;

            return [
                'sales_count' => (int) $row->sales_count,
                'revenue' => round($revenue, 2),
                'cost' => round($cost, 2),
                'profit' => round($revenue - $cost, 2),
                'items_without_cost' => $costRow ? (int) $costRow->items_without_cost : 0,
            ];
        });
    }

    /**
     * @param  callable(Builder): void  $scope
     * @return array{sales_count: int, revenue: float, cost: float, profit: float, items_without_cost: int}
     */
    public static function total(callable $scope): array
    {
        return self::grouped(null, $scope)->get('all');
    }

    private static function baseSales(Builder $query): Builder
    {
        return $query
            ->leftJoin('sales_shifts as shifts', 'shifts.id', '=', 'sales.shift_id')
            ->where('sales.status', 'completed')
            ->whereNull('sales.deleted_at');
    }
}
