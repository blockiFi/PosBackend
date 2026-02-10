<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Branch extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'business_id', 'name', 'code', 'is_main',
        'email', 'phone', 'address', 'city', 'state', 'postal_code', 'country',
        'time_zone', 'tax_rate', 'settings', 'is_active'
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'is_main' => 'boolean',
        'tax_rate' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($branch) {
            if (empty($branch->uuid)) {
                $branch->uuid = (string) Str::uuid();
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Get all products in this branch (through branch_products pivot)
     */
    public function products()
    {
        return $this->belongsToMany(Product::class, 'branch_products')
            ->withPivot([
                'cost_price', 'selling_price', 'compare_price',
                'discount_amount', 'discount_type', 'tax_rate',
                'stock_quantity', 'low_stock_threshold', 'allow_backorder',
                'reorder_point', 'reorder_quantity',
                'is_available', 'is_featured', 'display_order',
                'bin_location', 'shelf_location', 'branch_meta_data'
            ])
            ->withTimestamps()
            ->using(BranchProduct::class);
    }

    /**
     * Get branch-specific product data
     */
    public function branchProducts()
    {
        return $this->hasMany(BranchProduct::class);
    }

    /**
     * Get all batches for this branch
     */
    public function batches()
    {
        return $this->hasMany(ProductBatch::class);
    }

    /**
     * Get all active products in this branch
     */
    public function activeProducts()
    {
        return $this->branchProducts()
            ->where('is_available', true)
            ->whereHas('product', function ($q) {
                $q->where('is_active', true);
            });
    }

    /**
     * Get all featured products in this branch
     */
    public function featuredProducts()
    {
        return $this->branchProducts()
            ->where('is_featured', true)
            ->where('is_available', true);
    }
}
