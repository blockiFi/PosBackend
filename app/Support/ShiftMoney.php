<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\Sale;
use App\Models\SalesShift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
 *
 * - Ledger: money received split the way the store's handwritten book is kept. Normal sales go
 *   under their Section as cash / transfer+card; every naira paid on a deposit (first payment,
 *   top-ups and the final balance) goes in a separate Deposits line.
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
            'sales_cash' => 0.0, 'sales_transfer_card' => 0.0, 'sales_total' => 0.0,
            'deposits_cash' => 0.0, 'deposits_transfer_card' => 0.0, 'deposits_total' => 0.0, 'deposits_count' => 0,
        ];

        foreach (self::salesQuery($shift)->get() as $sale) {
            $row = self::row($sale, $shift);
            $totals['rows_count']++;
            $totals['money_received'] += $row['received_on_shift'];
            $line = self::isDeposit($sale) ? 'deposits' : 'sales';
            foreach ($row['received_methods'] as $part) {
                $totals[$part['type']] += $part['amount'];
                $totals[$line.($part['type'] === 'cash' ? '_cash' : '_transfer_card')] += $part['amount'];
                $totals[$line.'_total'] += $part['amount'];
            }
            if ($line === 'deposits' && $row['received_methods'] !== []) {
                $totals['deposits_count']++;
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

    /**
     * The handwritten-ledger view over many shifts (e.g. one day of a branch): per Section, money
     * from normal sales as cash / transfer+card, and all deposit money as its own line with one row
     * per deposit. Same payments as money_received, so sections + deposits = the shifts' total.
     *
     * @param  Builder  $shifts  SalesShift query (filters only; its columns are replaced)
     */
    public static function ledger(Builder $shifts): array
    {
        $shiftIds = (clone $shifts)->reorder()->select('sales_shifts.id');

        $payments = DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->join('sales_shifts', 'sales_shifts.id', '=', DB::raw('COALESCE(payments.shift_id, sales.shift_id)'))
            ->leftJoin('device_groups', 'device_groups.id', '=', 'sales_shifts.group_id')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            // A deposit is listed under the Section of the till where it was started
            ->leftJoin('sales_shifts as sale_shift', 'sale_shift.id', '=', 'sales.shift_id')
            ->leftJoin('device_groups as sale_group', 'sale_group.id', '=', 'sale_shift.group_id')
            ->where('payments.status', 'completed')
            ->whereNull('payments.deleted_at')
            // Voided and cancelled sales are not in the drawer (see computeMetricsFromShiftPayments)
            ->whereNull('sales.deleted_at')
            ->where('sales.status', '!=', 'cancelled')
            ->whereIn('sales_shifts.id', $shiftIds->toBase())
            ->get([
                'payments.amount', 'payment_methods.type as method_type',
                'sales.id as sale_id', 'sales.sale_number', 'sales.sale_type', 'sales.status as sale_status',
                'customers.name as customer_name', 'sales_shifts.group_id', 'device_groups.name as group_name',
                'sale_shift.group_id as sale_group_id', 'sale_group.name as sale_group_name',
            ]);

        $sections = [];
        $deposits = [];
        foreach ($payments as $payment) {
            $amount = (float) $payment->amount;
            $column = $payment->method_type === 'cash' ? 'cash' : 'transfer_card';

            if ($payment->sale_type === 'deposit') {
                $deposits[$payment->sale_id] ??= [
                    'sale_number' => $payment->sale_number,
                    'customer_name' => $payment->customer_name,
                    'section_id' => $payment->sale_group_id !== null ? (int) $payment->sale_group_id : null,
                    'section_name' => $payment->sale_group_name,
                    'status' => $payment->sale_status,
                    'cash' => 0.0, 'transfer_card' => 0.0, 'total' => 0.0,
                ];
                $deposits[$payment->sale_id][$column] += $amount;
                $deposits[$payment->sale_id]['total'] += $amount;

                continue;
            }

            $key = $payment->group_id ?? 'none';
            $sections[$key] ??= [
                'section_id' => $payment->group_id !== null ? (int) $payment->group_id : null,
                'section_name' => $payment->group_name,
                'cash' => 0.0, 'transfer_card' => 0.0, 'total' => 0.0, 'sales_ids' => [],
            ];
            $sections[$key][$column] += $amount;
            $sections[$key]['total'] += $amount;
            $sections[$key]['sales_ids'][$payment->sale_id] = true;
        }

        $sections = collect($sections)->map(fn (array $row) => [
            'section_id' => $row['section_id'],
            'section_name' => $row['section_name'],
            'sales_count' => count($row['sales_ids']),
            'cash' => round($row['cash'], 2),
            'transfer_card' => round($row['transfer_card'], 2),
            'total' => round($row['total'], 2),
        ])->sortBy(fn (array $row) => [$row['section_name'] === null ? 1 : 0, $row['section_name']])->values();

        $deposits = collect($deposits)->map(fn (array $row) => array_merge($row, [
            'cash' => round($row['cash'], 2),
            'transfer_card' => round($row['transfer_card'], 2),
            'total' => round($row['total'], 2),
        ]))->sortByDesc('total')->values();

        $sum = fn ($rows, string $column) => round((float) $rows->sum($column), 2);

        return [
            'sections' => $sections->all(),
            'sales' => ['cash' => $sum($sections, 'cash'), 'transfer_card' => $sum($sections, 'transfer_card'), 'total' => $sum($sections, 'total')],
            'deposits' => [
                'cash' => $sum($deposits, 'cash'),
                'transfer_card' => $sum($deposits, 'transfer_card'),
                'total' => $sum($deposits, 'total'),
                'count' => $deposits->count(),
                'rows' => $deposits->all(),
            ],
            'money_received' => round($sum($sections, 'total') + $sum($deposits, 'total'), 2),
        ];
    }

    private static function isDeposit(Sale $sale): bool
    {
        return $sale->sale_type === 'deposit';
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
