<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'sale_number',
        'reference_id',
        'business_id',
        'branch_id',
        'customer_id',
        'user_id',
        'shift_id',
        'sale_date',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'status',
        'payment_status',
        'paid_amount',
        'is_refunded',
        'refunded_at',
        'refunded_amount',
        'sale_type',
        'notes',
        'metadata',
        'client_uuid',
        'version',
        'device_id',
        'synced_at',
        'sync_status',
        'origin',
    ];

    protected $casts = [
        'sale_date' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'is_refunded' => 'boolean',
        'refunded_at' => 'datetime',
        'metadata' => 'array',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(SalesShift::class, 'shift_id');
    }


    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
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


    public function scopeForCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeOfStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeOfPaymentStatus($query, $paymentStatus)
    {
        return $query->where('payment_status', $paymentStatus);
    }

    public function scopeOfType($query, $type)
    {
        return $query->where('sale_type', $type);
    }

    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    // Helper methods
    public function calculateTotals(): void
    {
        $this->subtotal = $this->items->sum('subtotal');
        $this->tax_amount = $this->items->sum('tax_amount');

        // Calculate total: sum of item totals minus sale-level discount
        $itemsTotal = $this->items->sum('total');
        $this->total_amount = $itemsTotal - ($this->discount_amount ?? 0);
    }

    public function updatePaymentStatus(): void
    {
        $totalPaid = $this->payments()->where('status', 'completed')->sum('amount');
        $this->paid_amount = $totalPaid;

        if ($totalPaid == 0) {
            $this->payment_status = 'unpaid';
        } elseif ($totalPaid < $this->total_amount) {
            $this->payment_status = 'partial';
        } elseif ($totalPaid == $this->total_amount) {
            $this->payment_status = 'paid';
        } else {
            $this->payment_status = 'overpaid';
        }

        $this->save();
    }

    public function getBalanceAttribute(): float
    {
        return $this->total_amount - $this->paid_amount;
    }

    public function isFullyPaid(): bool
    {
        return $this->payment_status === 'paid' || $this->payment_status === 'overpaid';
    }

    public function canBeModified(): bool
    {
        return in_array($this->status, ['pending']);
    }

    public function isRefundable(): bool
    {
        return $this->refunded_amount < $this->total_amount
            && in_array($this->status, ['completed'])
            && ! $this->trashed();
    }

    public function hasPendingRefundRequest(): bool
    {
        return $this->refundRequests()
            ->where('status', RefundRequest::STATUS_PENDING)
            ->exists();
    }

    public function markAsRefunded(): void
    {
        $this->update([
            'is_refunded' => true,
            'refunded_at' => now(),
            'refunded_amount' => $this->total_amount,
        ]);
    }

    /**
     * Add refunded amount (for partial refunds). Sets is_refunded when total is reached.
     */
    public function addRefundedAmount(float $amount): void
    {
        $newRefunded = $this->refunded_amount + $amount;
        $this->update([
            'refunded_amount' => $newRefunded,
            'is_refunded' => $newRefunded >= (float) $this->total_amount,
            'refunded_at' => $newRefunded >= (float) $this->total_amount ? now() : $this->refunded_at,
        ]);
    }
}
