<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesShift extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'shift_number',
        'business_id',
        'branch_id',
        'user_id',
        'device_id',
        'group_id',
        'start_time',
        'end_time',
        'opening_balance',
        'expected_cash',
        'actual_cash',
        'cash_sales',
        'card_sales',
        'other_sales',
        'total_sales',
        'transactions_count',
        'variance',
        'status',
        'paused_at',
        'opening_notes',
        'closing_notes',
        'metadata',
        'discrepancy_resolved',
        'discrepancy_resolved_at',
        'discrepancy_resolved_by',
        'resolution_notes',
        'opening_balance_discrepancy',
        'previous_shift_id',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'opening_balance' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'actual_cash' => 'decimal:2',
        'cash_sales' => 'decimal:2',
        'card_sales' => 'decimal:2',
        'other_sales' => 'decimal:2',
        'total_sales' => 'decimal:2',
        'transactions_count' => 'integer',
        'variance' => 'decimal:2',
        'opening_balance_discrepancy' => 'decimal:2',
        'metadata' => 'array',
        'discrepancy_resolved' => 'boolean',
        'discrepancy_resolved_at' => 'datetime',
        'paused_at' => 'datetime',
    ];

    // Relationships
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class, 'group_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discrepancy_resolved_by');
    }

    public function previousShift(): BelongsTo
    {
        return $this->belongsTo(SalesShift::class, 'previous_shift_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'shift_id');
    }

    // Scopes
    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForGroup($query, int|string $groupId)
    {
        return $query->where('group_id', $groupId);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    public function scopePaused($query)
    {
        return $query->where('status', 'paused');
    }

    /** Shifts that are open or paused (not closed) */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['open', 'paused']);
    }

    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('start_time', [$startDate, $endDate]);
    }

    // Helper methods
    public function calculateExpectedCash(): void
    {
        $this->expected_cash = $this->opening_balance + $this->cash_sales;
    }

    public function calculateVariance(): void
    {
        if ($this->actual_cash !== null) {
            $this->variance = $this->actual_cash - $this->expected_cash;
        }
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    /** Shift can accept new sales only when open (not paused) */
    public function canAcceptSales(): bool
    {
        return $this->status === 'open';
    }

    public function hasVariance(): bool
    {
        return abs($this->variance) > 0.01;
    }

    public function hasOpeningBalanceDiscrepancy(): bool
    {
        return $this->opening_balance_discrepancy !== null
            && abs((float) $this->opening_balance_discrepancy) >= 0.01;
    }

    /**
     * Live cash-basis metrics for this shift: completed payments with this shift_id.
     * Used when closing a shift, and for read APIs so open shifts match drawer reality
     * (e.g. pending deposit sales still have payments that must count before close).
     *
     * @return array{
     *     cash_sales: float,
     *     card_sales: float,
     *     other_sales: float,
     *     total_sales: float,
     *     transactions_count: int
     * }
     */
    public function computeMetricsFromShiftPayments(): array
    {
        $payments = Payment::query()
            ->where('shift_id', $this->id)
            ->where('status', 'completed')
            // Exclude payments tied to cancelled (or soft-deleted) sales — those amounts are
            // not in the drawer at close time. whereHas('sale', ...) implicitly filters
            // soft-deleted sales out via the relation's default scope.
            ->whereHas('sale', function ($q) {
                $q->where('sales.status', '!=', 'cancelled');
            })
            ->with(['paymentMethod:id,type'])
            ->get(['id', 'sale_id', 'amount', 'payment_method_id']);

        $cash = (float) $payments->filter(fn ($p) => optional($p->paymentMethod)->type === 'cash')->sum('amount');
        $card = (float) $payments->filter(fn ($p) => optional($p->paymentMethod)->type === 'card')->sum('amount');
        $total = (float) $payments->sum('amount');
        $other = max(0, $total - $cash - $card);
        $count = $payments->pluck('sale_id')->unique()->count();

        return [
            'cash_sales' => $cash,
            'card_sales' => $card,
            'other_sales' => $other,
            'total_sales' => $total,
            'transactions_count' => $count,
        ];
    }

    /**
     * Cash-basis metrics: sum completed payments stamped to this shift, regardless of which
     * shift the parent sale was opened in. This makes drawer reconciliation correct when
     * deposit installments (or any addPayment) cross shift boundaries.
     */
    public function updateSalesMetrics(): void
    {
        $m = $this->computeMetricsFromShiftPayments();
        $this->cash_sales = $m['cash_sales'];
        $this->card_sales = $m['card_sales'];
        $this->total_sales = $m['total_sales'];
        $this->other_sales = $m['other_sales'];
        $this->transactions_count = $m['transactions_count'];
    }
}
