<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ProductBatch extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'business_id',
        'branch_id',
        'product_id',
        'batch_number',
        'lot_number',
        'manufacturing_date',
        'expiry_date',
        'received_quantity',
        'current_quantity',
        'unit_cost',
        'supplier_name',
        'supplier_reference',
        'supplier_id',
        'inventory_transaction_id',
        'goods_received_note_line_id',
        'status',
        'meta_data',
    ];

    protected $casts = [
        'manufacturing_date' => 'date',
        'expiry_date' => 'date',
        'received_quantity' => 'decimal:3',
        'current_quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'meta_data' => 'array',
    ];

    protected $appends = ['quick_sale_requested'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($batch) {
            if (empty($batch->uuid)) {
                $batch->uuid = Str::uuid();
            }
            if (empty($batch->batch_number)) {
                $batch->batch_number = static::generateBatchNumber();
            }
            // Auto-set status based on expiry
            if ($batch->expiry_date && Carbon::parse($batch->expiry_date)->isPast()) {
                $batch->status = 'expired';
            }
        });

        static::updating(function ($batch) {
            // Auto-update status
            if ($batch->current_quantity <= 0 && $batch->status === 'active') {
                $batch->status = 'depleted';
            }
            if ($batch->expiry_date && Carbon::parse($batch->expiry_date)->isPast() && $batch->status === 'active') {
                $batch->status = 'expired';
            }
        });
    }

    // Relationships
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function goodsReceivedNoteLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNoteLine::class, 'goods_received_note_line_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'batch_id');
    }

    public function quickSales(): HasMany
    {
        return $this->hasMany(QuickSale::class, 'batch_id');
    }

    /**
     * Quick sales for this product+branch with no specific batch (apply to any batch of this product at this branch).
     */
    public function productLevelQuickSales(): HasMany
    {
        return $this->hasMany(QuickSale::class, 'product_id', 'product_id')
            ->where('quick_sales.branch_id', $this->branch_id)
            ->whereNull('quick_sales.batch_id');
    }

    public function getQuickSaleRequestedAttribute(): bool
    {
        $statuses = [QuickSale::STATUS_PENDING, QuickSale::STATUS_APPROVED, QuickSale::STATUS_ACTIVE];
        $direct = (int) ($this->attributes['quick_sale_requested_count'] ?? 0);
        $productLevel = (int) ($this->attributes['product_level_quick_sale_count'] ?? 0);
        if (array_key_exists('quick_sale_requested_count', $this->attributes)
            || array_key_exists('product_level_quick_sale_count', $this->attributes)) {
            return (bool) ($direct + $productLevel);
        }

        return $this->quickSales()->whereIn('status', $statuses)->exists()
            || $this->productLevelQuickSales()->whereIn('status', $statuses)->exists();
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

    public function scopeActive($query)
    {
        return $query->where('status', 'active')->where('current_quantity', '>', 0);
    }

    public function scopeExpired($query)
    {
        return $query->where(function ($q) {
            $q->where('status', 'expired')
                ->orWhere(function ($q2) {
                    $q2->where('expiry_date', '<', now())
                        ->where('status', 'active');
                });
        });
    }

    public function scopeNearExpiry($query, int $days = 30)
    {
        return $query->where('status', 'active')
            ->where('current_quantity', '>', 0)
            ->where('expiry_date', '<=', now()->addDays($days))
            ->where('expiry_date', '>=', now());
    }

    public function scopeOrderByFEFO($query)
    {
        return $query->where('status', 'active')
            ->where('current_quantity', '>', 0)
            ->orderBy('expiry_date', 'asc')
            ->orderBy('manufacturing_date', 'asc')
            ->orderBy('created_at', 'asc');
    }

    // Helper Methods
    public function isExpired(): bool
    {
        if (! $this->expiry_date) {
            return false;
        }

        return Carbon::parse($this->expiry_date)->isPast();
    }

    public function isNearExpiry(int $days = 30): bool
    {
        if (! $this->expiry_date) {
            return false;
        }
        $expiryDate = Carbon::parse($this->expiry_date);
        $referenceDate = now()->startOfDay();

        return $expiryDate->greaterThan($referenceDate)
            && $expiryDate->diffInDays($referenceDate, true) <= $days;
    }

    public function daysUntilExpiry(): ?int
    {
        if (! $this->expiry_date) {
            return null;
        }
        $expiryDate = Carbon::parse($this->expiry_date);
        if ($expiryDate->isPast()) {
            return 0;
        }

        return $expiryDate->diffInDays(now()->startOfDay(), true);
    }

    public function canAllocate(float $quantity): bool
    {
        $quantity = \App\Support\Quantity::normalize($quantity);

        return $this->status === 'active'
            && (float) $this->current_quantity >= $quantity - \App\Support\Quantity::EPSILON
            && ! $this->isExpired();
    }

    public function allocate(float $quantity): bool
    {
        $quantity = \App\Support\Quantity::normalize($quantity);

        if (! $this->canAllocate($quantity)) {
            return false;
        }

        $this->current_quantity = \App\Support\Quantity::normalize((float) $this->current_quantity - $quantity);
        if (\App\Support\Quantity::remainingIsZero((float) $this->current_quantity)) {
            $this->current_quantity = 0;
            $this->status = 'depleted';
        }

        return $this->save();
    }

    public function increaseQuantity(float $quantity): bool
    {
        $quantity = \App\Support\Quantity::normalize($quantity);
        $this->current_quantity = \App\Support\Quantity::normalize((float) $this->current_quantity + $quantity);
        if ($this->status === 'depleted' && \App\Support\Quantity::isPositive((float) $this->current_quantity) && ! $this->isExpired()) {
            $this->status = 'active';
        }

        return $this->save();
    }

    public static function generateBatchNumber(): string
    {
        do {
            $batchNumber = 'BATCH-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
        } while (static::where('batch_number', $batchNumber)->exists());

        return $batchNumber;
    }

    /**
     * Find batches to allocate using FEFO (First Expired First Out)
     */
    public static function findBatchesToAllocate(int $productId, int $branchId, float $quantity): array
    {
        $allocations = [];
        $remaining = \App\Support\Quantity::normalize($quantity);

        $batches = static::where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->orderByFEFO()
            ->get();

        foreach ($batches as $batch) {
            if (\App\Support\Quantity::remainingIsZero($remaining)) {
                break;
            }

            $allocateQty = min($remaining, (float) $batch->current_quantity);
            if (\App\Support\Quantity::isPositive($allocateQty)) {
                $allocateQty = \App\Support\Quantity::normalize($allocateQty);
                $allocations[] = [
                    'batch' => $batch,
                    'quantity' => $allocateQty,
                ];
                $remaining = \App\Support\Quantity::normalize($remaining - $allocateQty);
            }
        }

        return [
            'allocations' => $allocations,
            'fully_allocated' => \App\Support\Quantity::remainingIsZero($remaining),
            'remaining' => $remaining,
        ];
    }
}
