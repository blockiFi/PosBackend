<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuickSale extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'business_id',
        'branch_id',
        'batch_id',
        'requested_by',
        'approved_by',
        'ended_by',
        'reason',
        'expiry_date',
        'discount_type',
        'discount_value',
        'start_time',
        'end_time',
        'status',
        'rejection_reason',
        'approved_at',
        'ended_at',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'discount_value' => 'decimal:2',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'approved_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    // Status constants
    const STATUS_PENDING = 'pending';

    const STATUS_APPROVED = 'approved';

    const STATUS_ACTIVE = 'active';

    const STATUS_EXPIRED = 'expired';

    const STATUS_ENDED = 'ended';

    const STATUS_REJECTED = 'rejected';

    // Discount type constants
    const DISCOUNT_PERCENTAGE = 'percentage';

    const DISCOUNT_FIXED = 'fixed';

    /**
     * Relationships
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function endedBy()
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    /**
     * Scopes
     */
    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('start_time', '<=', now())
            ->where('end_time', '>', now());
    }

    public function scopeForProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Status check methods
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->start_time <= now()
            && $this->end_time > now();
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            || ($this->end_time && $this->end_time < now());
    }

    public function isEnded(): bool
    {
        return $this->status === self::STATUS_ENDED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isBatchScoped(): bool
    {
        return $this->batch_id !== null;
    }

    /**
     * State management methods
     */
    public function markAsApproved($approverId, $discountType, $discountValue, $startTime, $endTime): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approverId,
            'approved_at' => now(),
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
    }

    public function markAsRejected($reviewerId, $reason): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'approved_by' => $reviewerId,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $this->removeDiscountFromBranchProduct();
    }

    public function markAsActive(): void
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
        ]);

        $this->applyDiscountToBranchProduct();
    }

    public function markAsExpired(): void
    {
        $this->update([
            'status' => self::STATUS_EXPIRED,
        ]);

        $this->removeDiscountFromBranchProduct();
    }

    public function markAsEnded(?int $userId): void
    {
        $this->update([
            'status' => self::STATUS_ENDED,
            'ended_by' => $userId,
            'ended_at' => now(),
        ]);

        $this->removeDiscountFromBranchProduct();
    }

    /**
     * Business logic methods
     */
    public function shouldBeActive(): bool
    {
        return $this->isApproved()
            && $this->start_time
            && $this->start_time <= now()
            && $this->end_time > now();
    }

    public function shouldExpire(): bool
    {
        return $this->isActive()
            && $this->end_time
            && $this->end_time < now();
    }

    /**
     * Calculate discount for a given price
     */
    public function calculateDiscount($originalPrice): float
    {
        if (! $this->isActive()) {
            return 0;
        }

        if ($this->discount_type === self::DISCOUNT_PERCENTAGE) {
            return ($originalPrice * $this->discount_value) / 100;
        }

        return min($this->discount_value, $originalPrice);
    }

    /**
     * Calculate final price after discount
     */
    public function calculateFinalPrice($originalPrice): float
    {
        return max(0, $originalPrice - $this->calculateDiscount($originalPrice));
    }

    /**
     * Check if there's an overlapping active/approved quick sale for the same product (and batch when batch-scoped)
     */
    public static function hasOverlappingQuickSale($productId, $branchId, $startTime, $endTime, $excludeId = null, $batchId = null): bool
    {
        $query = self::where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->whereIn('status', [self::STATUS_APPROVED, self::STATUS_ACTIVE])
            ->where(function ($q) use ($startTime, $endTime) {
                $q->whereBetween('start_time', [$startTime, $endTime])
                    ->orWhereBetween('end_time', [$startTime, $endTime])
                    ->orWhere(function ($q2) use ($startTime, $endTime) {
                        $q2->where('start_time', '<=', $startTime)
                            ->where('end_time', '>=', $endTime);
                    });
            });

        if ($batchId !== null) {
            $query->where('batch_id', $batchId);
        }

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * Get active quick sale for a product at a specific time, optionally for a specific batch
     */
    public static function getActiveQuickSale($productId, $branchId, $time = null, $batchId = null): ?self
    {
        $time = $time ?? now();

        $query = self::where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->where('status', self::STATUS_ACTIVE)
            ->where('start_time', '<=', $time)
            ->where('end_time', '>', $time);

        if ($batchId !== null) {
            $query->where('batch_id', $batchId);
        }

        return $query->first();
    }

    /**
     * Get active batch-scoped quick sale for a product at branch whose batch has remaining quantity (for auto-allocating sale items)
     */
    public static function getActiveQuickSaleForProduct(int $productId, int $branchId): ?self
    {
        return self::where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->whereNotNull('batch_id')
            ->where('status', self::STATUS_ACTIVE)
            ->where('start_time', '<=', now())
            ->where('end_time', '>', now())
            ->whereHas('batch', function ($q) {
                $q->where('current_quantity', '>', 0)->where('status', 'active');
            })
            ->first();
    }

    /**
     * End all active quick sales for a batch (e.g. when batch is depleted)
     */
    public static function endActiveForBatch(int $batchId, ?int $userId = null): void
    {
        $quickSales = self::where('batch_id', $batchId)
            ->where('status', self::STATUS_ACTIVE)
            ->get();

        foreach ($quickSales as $qs) {
            $qs->markAsEnded($userId); // null for system-ended when batch depleted
        }
    }

    /**
     * Apply discount to branch product
     */
    public function applyDiscountToBranchProduct(): void
    {
        $branchProduct = BranchProduct::where('product_id', $this->product_id)
            ->where('branch_id', $this->branch_id)
            ->first();

        if ($branchProduct) {
            $branchProduct->update([
                'discount_type' => $this->discount_type,
                'discount_amount' => $this->discount_value,
            ]);
        }
    }

    /**
     * Remove discount from branch product
     */
    public function removeDiscountFromBranchProduct(): void
    {
        $branchProduct = BranchProduct::where('product_id', $this->product_id)
            ->where('branch_id', $this->branch_id)
            ->first();

        if ($branchProduct) {
            // Only remove if this quick sale is the one that applied it
            // Check if discount matches this quick sale's discount
            if ($branchProduct->discount_type === $this->discount_type
                && $branchProduct->discount_amount == $this->discount_value) {
                $branchProduct->update([
                    'discount_type' => null,
                    'discount_amount' => null,
                ]);
            }
        }
    }
}
