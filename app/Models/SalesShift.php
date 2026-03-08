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

    public function updateSalesMetrics(): void
    {
        // This should be called when closing the shift
        $sales = $this->sales()->where('status', 'completed')->get();

        $this->total_sales = $sales->sum('total_amount');
        $this->transactions_count = $sales->count();

        // Calculate by payment method
        $this->cash_sales = $sales->sum(function ($sale) {
            return $sale->payments()->whereHas('paymentMethod', function ($q) {
                $q->where('type', 'cash');
            })->sum('amount');
        });

        $this->card_sales = $sales->sum(function ($sale) {
            return $sale->payments()->whereHas('paymentMethod', function ($q) {
                $q->where('type', 'card');
            })->sum('amount');
        });

        $this->other_sales = $this->total_sales - $this->cash_sales - $this->card_sales;
    }
}
