<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'business_id',
        'category_id',
        'name',
        'sku',
        'barcode',
        'description',
        'image',
        'base_cost_price',
        'base_selling_price',
        'is_taxable',
        'default_tax_rate',
        'unit_of_measure',
        'weight',
        'weight_unit',
        'stock_tracking',
        'low_stock_threshold',
        'is_active',
        'is_available_online',
        'meta_data',
        'sort_order',
    ];

    protected $casts = [
        'base_cost_price' => 'decimal:2',
        'base_selling_price' => 'decimal:2',
        'default_tax_rate' => 'decimal:2',
        'low_stock_threshold' => 'integer',
        'weight' => 'decimal:3',
        'is_taxable' => 'boolean',
        'is_active' => 'boolean',
        'is_available_online' => 'boolean',
        'meta_data' => 'array',
        'sort_order' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            if (empty($product->uuid)) {
                $product->uuid = Str::uuid();
            }
            if (empty($product->sku)) {
                $product->sku = static::generateSKU();
            }
        });
    }

    /**
     * Get the business that owns the product
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Get all branches where this product is available (through branch_products)
     */
    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'branch_products')
            ->withPivot([
                'cost_price', 'selling_price', 'compare_price',
                'discount_amount', 'discount_type', 'tax_rate',
                'stock_quantity', 'low_stock_threshold', 'allow_backorder',
                'reorder_point', 'reorder_quantity',
                'is_available', 'is_featured', 'display_order',
                'bin_location', 'shelf_location', 'branch_meta_data',
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
     * Get unit definitions for tiered pricing (piece, pack of 6, etc.)
     */
    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderBy('display_order')->orderBy('quantity_multiplier');
    }

    /**
     * Get all batches for this product
     */
    public function batches()
    {
        return $this->hasMany(ProductBatch::class);
    }

    /**
     * Get branch product for a specific branch
     */
    public function getBranchProduct(int $branchId): ?BranchProduct
    {
        return $this->branchProducts()->where('branch_id', $branchId)->first();
    }

    /**
     * Get the category that the product belongs to
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * Check if product is available in a specific branch
     */
    public function isAvailableInBranch(int $branchId): bool
    {
        $branchProduct = $this->getBranchProduct($branchId);

        return $branchProduct && $branchProduct->is_available;
    }

    /**
     * Get stock quantity in a specific branch
     */
    public function getStockInBranch(int $branchId): int
    {
        $branchProduct = $this->getBranchProduct($branchId);

        return $branchProduct ? $branchProduct->stock_quantity : 0;
    }

    /**
     * Get total stock across all branches
     */
    public function getTotalStock(): int
    {
        if ($this->stock_tracking === 'none') {
            return 0;
        }

        return $this->branchProducts()->sum('stock_quantity');
    }

    /**
     * Generate a unique SKU
     */
    protected static function generateSKU(): string
    {
        do {
            $sku = 'PRD-'.strtoupper(Str::random(8));
        } while (static::where('sku', $sku)->exists());

        return $sku;
    }

    /**
     * Scope to get only active products
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get products available in a specific branch
     */
    public function scopeAvailableInBranch($query, int $branchId)
    {
        return $query->whereHas('branchProducts', function ($q) use ($branchId) {
            $q->where('branch_id', $branchId)
                ->where('is_available', true);
        });
    }

    /**
     * Scope to filter by business
     */
    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    /**
     * Scope to filter by category (including subcategories)
     */
    public function scopeInCategory($query, int $categoryId, bool $includeSubcategories = true)
    {
        if (! $includeSubcategories) {
            return $query->where('category_id', $categoryId);
        }

        $category = ProductCategory::find($categoryId);
        if (! $category) {
            return $query->where('category_id', $categoryId);
        }

        $categoryIds = [$categoryId];
        $descendants = $category->descendants()->get();
        $categoryIds = array_merge($categoryIds, static::flattenDescendants($descendants));

        return $query->whereIn('category_id', $categoryIds);
    }

    /**
     * Helper to flatten descendant categories
     */
    protected static function flattenDescendants($categories): array
    {
        $ids = [];
        foreach ($categories as $category) {
            $ids[] = $category->id;
            if ($category->descendants && $category->descendants->count() > 0) {
                $ids = array_merge($ids, static::flattenDescendants($category->descendants));
            }
        }

        return $ids;
    }
}
