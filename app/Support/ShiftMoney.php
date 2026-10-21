<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\Sale;
use App\Models\SalesShift;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a shift took in, sale by sale, so the shift page's cards and history rows add up.
 *
 * - Money received: every payment taken on the shift (deposits and top-ups too), the same rule
 *   as SalesShift::computeMetricsFromShiftPayments(), so it matches the Shifts & Sales list.
 * - Items sold: completed sales rung up on the shift, the same rule as the owner overview and
 *   SalesProfit, so the shifts of a day add up to the overview.
 *
 * The two differ only by deposits: money for deposits not finished yet, balances paid here for
 * deposits from older shifts, and the part of a finished deposit that was paid on another shift.
 */
final class ShiftMoney
{
    /** Sales rung up on the shift, plus older sales that had money paid on it (deposit balances). */
    public static function salesQuery(SalesShift $shift): Builder
    {
        return Sale::withTrashed()
            ->where(function ($query) use ($shift) {
                $query->where('shift_id', $shift->id)
                    ->orWhereHas('payments', function ($payments) use ($shift) {
                        $payments->where('shift_id', $shift->id)->where('status', 'completed');
                    });
            })
            ->with(['payments.paymentMethod']);
    }

    /**
     * @return array{received_on_shift: float, received_methods: array<int, array{method: string, type: string, amount: float}>,
     *               paid_on_other_shifts: float, item_value: float, kind: string, from_other_shift: bool, paid_at: ?string}
     */
    public static function row(Sale $sale, SalesShift $shift): array
    {
        $ownShift = (int) $sale->shift_id === (int) $shift->id;
        $completed = $sale->payments->where('status', 'completed');

        $onShift = $completed->filter(fn (Payment $payment) => self::isOnShift($payment, $sale, $shift));
        $elsewhere = $completed->reject(fn (Payment $payment) => self::isOnShift($payment, $sale, $shift));

        // Voided and cancelled sales are left out of the drawer count (see computeMetricsFromShiftPayments)
        $counted = ! $sale->trashed() && $sale->status !== 'cancelled';
        $received = $counted ? (float) $onShift->sum('amount') : 0.0;

        $isDone = ! $sale->trashed() && $sale->status === 'completed';

        if ($sale->trashed()) {
            $kind = 'voided';
        } elseif ($sale->status === 'cancelled') {
            $kind = 'cancelled';
        } elseif ($sale->status === 'refunded') {
            $kind = 'refunded';
        } elseif (! $ownShift) {
            $kind = 'older_deposit';
        } elseif (! $isDone) {
            $kind = 'deposit_waiting';
        } else {
            $kind = $sale->sale_type === 'deposit' || $elsewhere->isNotEmpty() ? 'deposit_done' : 'sale';
        }

        return [
            'received_on_shift' => round($received, 2),
            'received_methods' => $counted ? $onShift->map(fn (Payment $payment) => [
                'method' => $payment->paymentMethod->name ?? 'Unknown',
                'type' => self::type($payment),
                'amount' => (float) $payment->amount,
            ])->values()->all() : [],
            'paid_on_other_shifts' => round((float) $elsewhere->sum('amount'), 2),
            'item_value' => $ownShift && $isDone ? round((float) $sale->total_amount, 2) : 0.0,
            'kind' => $kind,
            'from_other_shift' => ! $ownShift,
            'paid_at' => optional($onShift->max('payment_date'))->toIso8601String(),
        ];
    }

    /**
     * Totals over every row of the shift. money_received - items_sold
     * = unfinished_deposits + older_deposit_balances - paid_on_other_shifts + other.
     *
     * @return array<string, float|int>
     */
    public static function summary(SalesShift $shift): array
    {
        $totals = [
            'money_received' => 0.0, 'cash' => 0.0, 'card' => 0.0, 'other' => 0.0,
            'items_sold' => 0.0, 'items_sold_count' => 0, 'rows_count' => 0,
            'unfinished_deposits' => 0.0, 'older_deposit_balances' => 0.0, 'paid_on_other_shifts' => 0.0,
        ];

        foreach (self::salesQuery($shift)->get() as $sale) {
            $row = self::row($sale, $shift);
            $totals['rows_count']++;
            $totals['money_received'] += $row['received_on_shift'];
            foreach ($row['received_methods'] as $part) {
                $totals[$part['type']] += $part['amount'];
            }
            if ($row['kind'] === 'sale' || $row['kind'] === 'deposit_done') {
                $totals['items_sold'] += $row['item_value'];
                $totals['items_sold_count']++;
                $totals['paid_on_other_shifts'] += $row['paid_on_other_shifts'];
            }
            if ($row['kind'] === 'deposit_waiting') {
                $totals['unfinished_deposits'] += $row['received_on_shift'];
            }
            if ($row['kind'] === 'older_deposit') {
                $totals['older_deposit_balances'] += $row['received_on_shift'];
            }
        }

        $totals = array_map(fn ($value) => is_float($value) ? round($value, 2) : $value, $totals);
        // Anything left over (e.g. a cancelled deposit with part of the money given back)
        $totals['other_difference'] = round(
            $totals['money_received'] - $totals['items_sold']
            - $totals['unfinished_deposits'] - $totals['older_deposit_balances'] + $totals['paid_on_other_shifts'],
            2
        );

        return $totals;
    }

    private static function isOnShift(Payment $payment, Sale $sale, SalesShift $shift): bool
    {
        if ($payment->shift_id !== null) {
            return (int) $payment->shift_id === (int) $shift->id;
        }

        return (int) $sale->shift_id === (int) $shift->id;
    }

    private static function type(Payment $payment): string
    {
        $type = optional($payment->paymentMethod)->type;

        return $type === 'cash' || $type === 'card' ? $type : 'other';
    }
}
