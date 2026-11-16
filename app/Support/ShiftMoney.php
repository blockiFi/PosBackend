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
 * deposits from older shifts, money taken here for deposits handed over on a later shift, and the
 * part of a finished deposit that was paid on another shift.
 *
 * A finished deposit counts as sold on the shift (and day) that handed the item over: complete-
 * deposit moves the sale there and keeps where it began in metadata.deposit_started_shift_id.
 *
 * - Ledger: money split the way the store's handwritten book is kept. Normal sales go under their
 *   Section as cash / transfer+card. A deposit finished (item handed over) on the shift becomes a
 *   sale there at full price: money paid for it on the shift goes in its cash / transfer+card, and
 *   what was paid on earlier shifts goes in paid_earlier, so cash and transfer+card stay the money
 *   actually taken. Money paid on deposits not finished on the shift goes in a separate Deposits line.
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
        } elseif (! $ownShift && self::startedOn($sale, $shift)) {
            // Deposit taken here, item handed over (and sold) on a later shift
            $kind = 'deposit_finished_later';
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
     * Totals over every row of the shift. money_received - items_sold = unfinished_deposits
     * + older_deposit_balances + deposits_finished_later - paid_on_other_shifts + other.
     *
     * @return array<string, float|int>
     */
    public static function summary(SalesShift $shift): array
    {
        $totals = [
            'money_received' => 0.0, 'cash' => 0.0, 'card' => 0.0, 'other' => 0.0,
            'items_sold' => 0.0, 'items_sold_count' => 0, 'rows_count' => 0,
            'unfinished_deposits' => 0.0, 'older_deposit_balances' => 0.0, 'paid_on_other_shifts' => 0.0,
            'deposits_finished_later' => 0.0,
            'sales_cash' => 0.0, 'sales_transfer_card' => 0.0, 'sales_paid_earlier' => 0.0, 'sales_total' => 0.0,
            'deposits_collected_total' => 0.0, 'deposits_collected_count' => 0,
            'deposits_cash' => 0.0, 'deposits_transfer_card' => 0.0, 'deposits_total' => 0.0, 'deposits_count' => 0,
        ];

        foreach (self::salesQuery($shift)->get() as $sale) {
            $row = self::row($sale, $shift);
            $totals['rows_count']++;
            $totals['money_received'] += $row['received_on_shift'];
            $finishedHere = self::isDeposit($sale) && $row['kind'] === 'deposit_done';
            $line = self::isDeposit($sale) && ! $finishedHere ? 'deposits' : 'sales';
            foreach ($row['received_methods'] as $part) {
                $totals[$part['type']] += $part['amount'];
                $totals[$line.($part['type'] === 'cash' ? '_cash' : '_transfer_card')] += $part['amount'];
                $totals[$line.'_total'] += $part['amount'];
            }
            if ($line === 'deposits' && $row['received_methods'] !== []) {
                $totals['deposits_count']++;
            }
            if ($finishedHere) {
                // The rest of the item's price was paid on earlier shifts
                $earlier = max(0.0, $row['item_value'] - $row['received_on_shift']);
                $totals['sales_paid_earlier'] += $earlier;
                $totals['sales_total'] += $earlier;
                $totals['deposits_collected_total'] += $row['item_value'];
                $totals['deposits_collected_count']++;
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
            if ($row['kind'] === 'deposit_finished_later') {
                $totals['deposits_finished_later'] += $row['received_on_shift'];
            }
        }

        $totals = array_map(fn ($value) => is_float($value) ? round($value, 2) : $value, $totals);
        // Anything left over (e.g. a cancelled deposit with part of the money given back)
        $totals['other_difference'] = round(
            $totals['money_received'] - $totals['items_sold']
            - $totals['unfinished_deposits'] - $totals['older_deposit_balances'] - $totals['deposits_finished_later']
            + $totals['paid_on_other_shifts'],
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
        $inPeriod = array_flip(array_map('intval', (clone $shiftIds)->toBase()->pluck('id')->all()));

        $payments = DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->join('sales_shifts', 'sales_shifts.id', '=', DB::raw('COALESCE(payments.shift_id, sales.shift_id)'))
            ->leftJoin('device_groups', 'device_groups.id', '=', 'sales_shifts.group_id')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            // A deposit is listed under the Section of its sale's till (where it was finished, once it is)
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
                'sales_shifts.id as paid_on_shift_id', 'sales.shift_id as sale_shift_id',
            ]);

        // Deposits finished (item handed over) on these shifts are sales here, at full price
        $finished = DB::table('sales')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('sales_shifts as sale_shift', 'sale_shift.id', '=', 'sales.shift_id')
            ->leftJoin('device_groups as sale_group', 'sale_group.id', '=', 'sale_shift.group_id')
            ->where('sales.sale_type', 'deposit')
            ->where('sales.status', 'completed')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.shift_id', $shiftIds->toBase())
            ->get([
                'sales.id', 'sales.sale_number', 'sales.total_amount', 'sales.shift_id', 'sales.created_at',
                'customers.name as customer_name', 'sale_shift.group_id', 'sale_group.name as group_name',
            ])
            ->keyBy('id');
        $items = DB::table('sale_items')
            ->whereIn('sale_id', $finished->keys()->all())
            ->orderBy('id')
            ->get(['sale_id', 'product_name', 'quantity'])
            ->groupBy('sale_id');
        $paidHere = [];

        $sections = [];
        $deposits = [];
        $byShift = [];
        foreach ($payments as $payment) {
            $amount = (float) $payment->amount;
            $column = $payment->method_type === 'cash' ? 'cash' : 'transfer_card';
            $finishedHere = $finished->has($payment->sale_id);
            $line = $payment->sale_type === 'deposit' && ! $finishedHere ? 'deposits' : 'sales';
            $byShift[$payment->paid_on_shift_id] ??= ['sales' => 0.0, 'deposits' => 0.0];
            $byShift[$payment->paid_on_shift_id][$line] += $amount;

            if ($finishedHere) {
                $paidHere[$payment->sale_id] = ($paidHere[$payment->sale_id] ?? 0.0) + $amount;
                // Under the Section of the till that handed the item over
                $payment->group_id = $payment->sale_group_id;
                $payment->group_name = $payment->sale_group_name;
            }

            if ($line === 'deposits') {
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
            $sections[$key] ??= self::emptySection($payment->group_id, $payment->group_name);
            $sections[$key][$column] += $amount;
            $sections[$key]['total'] += $amount;
            $sections[$key]['sales_ids'][$payment->sale_id] = true;
        }

        // The part of each finished deposit paid on earlier shifts (all of it if nothing was paid here)
        $collected = [];
        foreach ($finished as $sale) {
            $earlier = max(0.0, (float) $sale->total_amount - ($paidHere[$sale->id] ?? 0.0));
            $collected[] = [
                'sale_number' => $sale->sale_number,
                'customer_name' => $sale->customer_name,
                'section_id' => $sale->group_id !== null ? (int) $sale->group_id : null,
                'section_name' => $sale->group_name,
                'items' => self::itemsLabel($items->get($sale->id, collect())),
                'started_at' => $sale->created_at !== null ? \Illuminate\Support\Carbon::parse($sale->created_at)->toIso8601String() : null,
                'total' => round((float) $sale->total_amount, 2),
                'paid_today' => round($paidHere[$sale->id] ?? 0.0, 2),
                'paid_earlier' => round($earlier, 2),
            ];
            $key = $sale->group_id ?? 'none';
            $sections[$key] ??= self::emptySection($sale->group_id, $sale->group_name);
            $sections[$key]['paid_earlier'] += $earlier;
            $sections[$key]['total'] += $earlier;
            $sections[$key]['sales_ids'][$sale->id] = true;
            $byShift[$sale->shift_id] ??= ['sales' => 0.0, 'deposits' => 0.0];
            $byShift[$sale->shift_id]['sales'] += $earlier;
        }

        $sections = collect($sections)->map(fn (array $row) => [
            'section_id' => $row['section_id'],
            'section_name' => $row['section_name'],
            'sales_count' => count($row['sales_ids']),
            'cash' => round($row['cash'], 2),
            'transfer_card' => round($row['transfer_card'], 2),
            'paid_earlier' => round($row['paid_earlier'], 2),
            'total' => round($row['total'], 2),
        ])->sortBy(fn (array $row) => [$row['section_name'] === null ? 1 : 0, $row['section_name']])->values();

        $deposits = collect($deposits)->map(fn (array $row) => array_merge($row, [
            'cash' => round($row['cash'], 2),
            'transfer_card' => round($row['transfer_card'], 2),
            'total' => round($row['total'], 2),
        ]))->sortByDesc('total')->values();

        $collected = collect($collected)->sortByDesc('total')->values();
        $sum = fn ($rows, string $column) => round((float) $rows->sum($column), 2);

        return [
            'sections' => $sections->all(),
            'sales' => [
                'cash' => $sum($sections, 'cash'),
                'transfer_card' => $sum($sections, 'transfer_card'),
                'paid_earlier' => $sum($sections, 'paid_earlier'),
                'total' => $sum($sections, 'total'),
                // Sales = normal sales + deposits collected (item handed over) on these shifts
                'normal' => round($sum($sections, 'total') - $sum($collected, 'total'), 2),
                'from_deposits' => $sum($collected, 'total'),
            ],
            // Deposits whose item was handed over on these shifts, one row each
            'deposits_collected' => [
                'count' => $collected->count(),
                'total' => $sum($collected, 'total'),
                'paid_today' => $sum($collected, 'paid_today'),
                'paid_earlier' => $sum($collected, 'paid_earlier'),
                'rows' => $collected->all(),
            ],
            'deposits' => [
                'cash' => $sum($deposits, 'cash'),
                'transfer_card' => $sum($deposits, 'transfer_card'),
                'total' => $sum($deposits, 'total'),
                'count' => $deposits->count(),
                'rows' => $deposits->all(),
            ],
            // Money actually taken on these shifts: not what was paid earlier for finished deposits
            'money_received' => round($sum($sections, 'cash') + $sum($sections, 'transfer_card') + $sum($deposits, 'total'), 2),
            // Sales vs deposits for each shift, keyed by shift id, for the Shifts & Sales rows
            'shifts' => collect($byShift)->map(fn (array $row) => [
                'sales' => round($row['sales'], 2),
                'deposits' => round($row['deposits'], 2),
            ])->all(),
        ];
    }

    /** "3 × KENTE GOLD, 1 × GYALE and 4 more" */
    public static function itemsLabel(\Illuminate\Support\Collection $items): string
    {
        $names = $items->take(3)->map(function ($item) {
            $quantity = (float) $item->quantity;
            $quantity = floor($quantity) === $quantity ? (int) $quantity : $quantity;

            return $quantity.' × '.$item->product_name;
        })->implode(', ');

        return $items->count() > 3 ? $names.' and '.($items->count() - 3).' more' : $names;
    }

    /** @return array<string, mixed> */
    private static function emptySection(mixed $groupId, ?string $groupName): array
    {
        return [
            'section_id' => $groupId !== null ? (int) $groupId : null,
            'section_name' => $groupName,
            'cash' => 0.0, 'transfer_card' => 0.0, 'paid_earlier' => 0.0, 'total' => 0.0, 'sales_ids' => [],
        ];
    }

    /** A finished deposit moves to the shift that handed the item over; this is where it began. */
    private static function startedOn(Sale $sale, SalesShift $shift): bool
    {
        $started = ($sale->metadata ?? [])['deposit_started_shift_id'] ?? null;

        return $sale->sale_type === 'deposit' && $started !== null && (int) $started === (int) $shift->id;
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
