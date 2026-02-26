<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property-read \App\Models\ProductBatch|null $batch
 */
class StockWriteoff extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'branch_id',
        'branch_product_id',
        'product_id',
        'batch_id',
        'sku',
        'quantity',
        'source',
        'reason',
        'written_off_by',
        'written_off_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'written_off_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($writeoff) {
            if (empty($writeoff->written_off_at)) {
                $writeoff->written_off_at = now();
            }
        });
    }

    /**
     * Get the business this write-off belongs to
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Get the branch this write-off belongs to
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Get the branch product this write-off is for
     */
    public function branchProduct(): BelongsTo
    {
        return $this->belongsTo(BranchProduct::class);
    }

    /**
     * Get the product this write-off is for
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the batch this write-off is for (when source is batch)
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    /**
     * Get the user who wrote off the stock
     */
    public function writtenOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'written_off_by');
    }
}
