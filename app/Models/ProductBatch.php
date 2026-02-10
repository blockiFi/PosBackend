<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Carbon\Carbon;

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
        'inventory_transaction_id',
        'status',
        'meta_data',
    ];

    protected $casts = [
        'manufacturing_date' => 'date',
        'expiry_date' => 'date',
        'received_quantity' => 'integer',
        'current_quantity' => 'integer',
        'unit_cost' => 'decimal:2',
        'meta_data' => 'array',
    ];

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

    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'batch_id');
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
        if (!$this->expiry_date) {
            return false;
        }
        return Carbon::parse($this->expiry_date)->isPast();
    }

    public function isNearExpiry(int $days = 30): bool
    {
        if (!$this->expiry_date) {
            return false;
        }
        $expiryDate = Carbon::parse($this->expiry_date);
        return $expiryDate->isFuture() && $expiryDate->diffInDays(now()) <= $days;
    }

    public function daysUntilExpiry(): ?int
    {
        if (!$this->expiry_date) {
            return null;
        }
        $expiryDate = Carbon::parse($this->expiry_date);
        if ($expiryDate->isPast()) {
            return 0;
        }
        return $expiryDate->diffInDays(now());
    }

    public function canAllocate(int $quantity): bool
    {
        return $this->status === 'active' 
            && $this->current_quantity >= $quantity 
            && !$this->isExpired();
    }

    public function allocate(int $quantity): bool
    {
        if (!$this->canAllocate($quantity)) {
            return false;
        }

        $this->current_quantity -= $quantity;
        if ($this->current_quantity <= 0) {
            $this->status = 'depleted';
        }
        return $this->save();
    }

    public function increaseQuantity(int $quantity): bool
    {
        $this->current_quantity += $quantity;
        if ($this->status === 'depleted' && $this->current_quantity > 0 && !$this->isExpired()) {
            $this->status = 'active';
        }
        return $this->save();
    }

    public static function generateBatchNumber(): string
    {
        do {
            $batchNumber = 'BATCH-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (static::where('batch_number', $batchNumber)->exists());

        return $batchNumber;
    }

    /**
     * Find batches to allocate using FEFO (First Expired First Out)
     */
    public static function findBatchesToAllocate(int $productId, int $branchId, int $quantity): array
    {
        $allocations = [];
        $remaining = $quantity;

        $batches = static::where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->orderByFEFO()
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $allocateQty = min($remaining, $batch->current_quantity);
            if ($allocateQty > 0) {
                $allocations[] = [
                    'batch' => $batch,
                    'quantity' => $allocateQty,
                ];
                $remaining -= $allocateQty;
            }
        }

        return [
            'allocations' => $allocations,
            'fully_allocated' => $remaining <= 0,
            'remaining' => $remaining,
        ];
    }
}
