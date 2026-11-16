<?php

namespace App\Support;

use App\Models\RefundRequest;
use App\Models\RefundRequestItem;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Collection;

/**
 * Items a customer brings back, done by the cashier with a manager's PIN.
 *
 * A return (or swap) is its own sale, sale_type "return", rung up on the cashier's current shift
 * and dated now. Items coming back are lines with a minus quantity at the price the customer
 * paid; items going out on a swap are normal lines at today's price. Its one payment is the
 * difference: minus when money is given back, plus when the customer pays more. The old sale
 * is never changed except for refunded_amount, so its day and shift stay as they were.
 *
 * Because of that, the shift's drawer, Items sold, profit and the overview all count the return
 * on the day and shift it happened without any special case: the minus lines take the money
 * and the cost of the returned items off, the new lines add the swapped item.
 *
 * reference_id holds the old sale's number, so a sale's returns are easy to find.
 */
final class SaleReturns
{
    public const TYPE = 'return';

    public const PREFIX = 'RET';

    /** Returns of one sale, oldest first. */
    public static function forSale(Sale $sale): Collection
    {
        return Sale::query()
            ->where('business_id', $sale->business_id)
            ->where('sale_type', self::TYPE)
            ->where('reference_id', $sale->sale_number)
            ->with('items')
            ->orderBy('id')
            ->get();
    }

    /**
     * How many of each item of the sale already came back, keyed by sale_item_id. Counts
     * returns done here and older refund requests approved on the supervisor screens.
     *
     * @return array<int, float>
     */
    public static function returnedQuantities(Sale $sale): array
    {
        $returned = [];
        $add = function (int $saleItemId, float $quantity) use (&$returned): void {
            $returned[$saleItemId] = Quantity::normalize(($returned[$saleItemId] ?? 0.0) + $quantity);
        };

        foreach (self::forSale($sale) as $return) {
            foreach ($return->items as $item) {
                $saleItemId = ($item->metadata ?? [])['returned_sale_item_id'] ?? null;
                if ($saleItemId !== null && (float) $item->quantity < 0) {
                    $add((int) $saleItemId, abs((float) $item->quantity));
                }
            }
        }

        $done = [RefundRequest::STATUS_APPROVED, RefundRequest::STATUS_PROCESSED];
        $itemIds = $sale->items->pluck('id')->all();
        if ($itemIds !== []) {
            RefundRequestItem::query()
                ->whereIn('sale_item_id', $itemIds)
                ->whereHas('refundRequest', fn ($q) => $q->whereIn('status', $done))
                ->get(['sale_item_id', 'quantity'])
                ->each(fn (RefundRequestItem $row) => $add((int) $row->sale_item_id, (float) $row->quantity));
        }

        $wholeSale = RefundRequest::query()
            ->where('sale_id', $sale->id)
            ->where('refund_scope', RefundRequest::SCOPE_WHOLE_SALE)
            ->whereIn('status', $done)
            ->exists();
        if ($wholeSale) {
            foreach ($sale->items as $item) {
                $returned[$item->id] = (float) $item->quantity;
            }
        }

        return $returned;
    }

    /** The price of what came back on a return (a plus number). */
    public static function returnedValue(Sale $return): float
    {
        $stored = (($return->metadata ?? [])['return'] ?? [])['returned_value'] ?? null;
        if ($stored !== null) {
            return round((float) $stored, 2);
        }

        $items = $return->relationLoaded('items') ? $return->items : $return->items()->get();

        return round(abs((float) $items->filter(fn (SaleItem $item) => (float) $item->quantity < 0)->sum('total')), 2);
    }

    /**
     * What the customer paid for $quantity of the item: its line total (promotion included),
     * less its share of any discount on the whole sale.
     */
    public static function paidFor(Sale $sale, SaleItem $item, float $quantity): float
    {
        $itemQuantity = (float) $item->quantity;
        if ($itemQuantity <= 0) {
            return 0.0;
        }

        $itemsTotal = (float) $sale->items->sum('total');
        $share = $itemsTotal > 0 && (float) $sale->discount_amount > 0
            ? (float) $sale->total_amount / $itemsTotal
            : 1.0;

        return round((float) $item->total * $share * $quantity / $itemQuantity, 2);
    }

    /**
     * One return in plain words for screens and the owner's list.
     *
     * @return array<string, mixed>
     */
    public static function present(Sale $return): array
    {
        $meta = ($return->metadata ?? [])['return'] ?? [];
        $back = [];
        $out = [];
        foreach ($return->items as $item) {
            $quantity = (float) $item->quantity;
            $line = [
                'product_id' => (int) $item->product_id,
                'name' => $item->product_name,
                'quantity' => abs($quantity),
                'value' => round(abs((float) $item->total), 2),
            ];
            if ($quantity < 0) {
                $line['condition'] = ($item->metadata ?? [])['condition'] ?? 'good';
                $back[] = $line;
            } else {
                $out[] = $line;
            }
        }

        $payment = $return->relationLoaded('payments') ? $return->payments->first() : null;
        $shift = $return->relationLoaded('shift') ? $return->shift : null;
        $paid = (float) $return->paid_amount;

        return [
            'id' => $return->id,
            'return_number' => $return->sale_number,
            'created_at' => optional($return->sale_date ?? $return->created_at)->toIso8601String(),
            'kind' => $meta['kind'] ?? ($out === [] ? 'refund' : 'swap'),
            'of_sale' => [
                'id' => isset($meta['of_sale_id']) ? (int) $meta['of_sale_id'] : null,
                'sale_number' => $return->reference_id,
                'sale_date' => $meta['of_sale_date'] ?? null,
            ],
            'shift' => $shift ? [
                'id' => $shift->id,
                'shift_number' => $shift->shift_number,
                'device_id' => $shift->device_id,
                'section' => $shift->relationLoaded('group') && $shift->group ? $shift->group->name : null,
            ] : null,
            'cashier' => $return->relationLoaded('user') && $return->user
                ? ['id' => $return->user->id, 'name' => $return->user->name]
                : null,
            'manager' => isset($meta['manager_id']) ? [
                'id' => (int) $meta['manager_id'],
                'name' => $meta['manager_name'] ?? null,
                'role' => $meta['manager_role'] ?? null,
            ] : null,
            'customer' => $return->relationLoaded('customer') && $return->customer
                ? ['id' => $return->customer->id, 'name' => $return->customer->name, 'phone' => $return->customer->phone]
                : null,
            'items_back' => $back,
            'items_out' => $out,
            'returned_value' => round(collect($back)->sum('value'), 2),
            'new_value' => round(collect($out)->sum('value'), 2),
            'money_back' => $paid < 0 ? round(abs($paid), 2) : 0.0,
            'money_in' => $paid > 0 ? round($paid, 2) : 0.0,
            'payment_method' => $payment && $payment->relationLoaded('paymentMethod') && $payment->paymentMethod ? [
                'id' => $payment->paymentMethod->id,
                'name' => $payment->paymentMethod->name,
                'type' => $payment->paymentMethod->type,
            ] : null,
            'reason' => $meta['reason'] ?? null,
        ];
    }
}
