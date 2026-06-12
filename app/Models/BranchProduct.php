<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BranchProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'product_id',
        'cost_price',
        'last_received_cost',
        'avg_cost_price',
        'selling_price',
        'compare_price',
        'discount_amount',
        'discount_type',
        'tax_rate',
        'stock_quantity',
        'shelf_quantity',
        'store_quantity',
        'low_stock_threshold',
        'allow_backorder',
        'reorder_point',
        'reorder_quantity',
        'is_available',
        'is_featured',
        'display_order',
        'bin_location',
        'shelf_location',
        'branch_meta_data',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'last_received_cost' => 'decimal:2',
        'avg_cost_price' => 'decimal:4',
        'selling_price' => 'decimal:2',
        'compare_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'stock_quantity' => 'decimal:3',
        'shelf_quantity' => 'decimal:3',
        'store_quantity' => 'decimal:3',
        'low_stock_threshold' => 'integer',
        'reorder_point' => 'integer',
        'reorder_quantity' => 'decimal:3',
        'is_available' => 'boolean',
        'is_featured' => 'boolean',
        'allow_backorder' => 'boolean',
        'display_order' => 'integer',
        'branch_meta_data' => 'array',
    ];

    /**
     * Get the branch that owns this product instance
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Get the product
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get unit-specific prices for this branch product (pack of 6, carton, etc.)
     */
    public function unitPrices()
    {
        return $this->hasMany(BranchProductUnitPrice::class);
    }

    /**
     * Get quantity-based price tiers (e.g. 1-5 = 500, 6-19 = 450)
     */
    public function quantityTiers()
    {
        return $this->hasMany(BranchProductQuantityTier::class)->orderBy('min_quantity');
    }

    /**
     * Get the effective cost price (branch-specific or base)
     */
    public function getEffectiveCostPrice(): float
    {
        return $this->cost_price ?? $this->product->base_cost_price ?? 0;
    }

    /**
     * Get the effective selling price (branch-specific or base)
     */
    public function getEffectiveSellingPrice(): float
    {
        return $this->selling_price ?? $this->product->base_selling_price ?? 0;
    }

    /**
     * Get the effective tax rate (branch-specific or product default)
     */
    public function getEffectiveTaxRate(): float
    {
        return $this->tax_rate ?? $this->product->default_tax_rate ?? 0;
    }

    /**
     * Calculate the final price after discount
     */
    public function getFinalPrice(): float
    {
        $price = $this->getEffectiveSellingPrice();

        if (! $this->discount_amount || ! $this->discount_type) {
            return $price;
        }

        if ($this->discount_type === 'fixed') {
            return max(0, $price - (float) $this->discount_amount);
        }

        if ($this->discount_type === 'percentage') {
            $discount = ($price * (float) $this->discount_amount) / 100;

            return max(0, $price - $discount);
        }

        return $price;
    }

    /**
     * Calculate the tax amount
     */
    public function getTaxAmount(): float
    {
        if (! $this->product->is_taxable) {
            return 0;
        }

        $finalPrice = $this->getFinalPrice();
        $taxRate = $this->getEffectiveTaxRate();

        return ($finalPrice * $taxRate) / 100;
    }

    /**
     * Get the final price including tax
     */
    public function getPriceWithTax(): float
    {
        return $this->getFinalPrice() + $this->getTaxAmount();
    }

    /**
     * Calculate profit margin
     */
    public function getProfitMargin(): float
    {
        $sellingPrice = $this->getEffectiveSellingPrice();
        $costPrice = $this->getEffectiveCostPrice();

        if ($sellingPrice <= 0) {
            return 0;
        }

        $profit = $sellingPrice - $costPrice;

        return ($profit / $sellingPrice) * 100;
    }

    /**
     * Check if product is in stock at this branch
     */
    public function isInStock(): bool
    {
        if ($this->product->stock_tracking === 'none') {
            return true;
        }

        return $this->stock_quantity > 0 || $this->allow_backorder;
    }

    /**
     * Check if product is low on stock at this branch
     */
    public function isLowStock(): bool
    {
        if ($this->product->stock_tracking === 'none') {
            return false;
        }

        $threshold = $this->low_stock_threshold ?? $this->product->low_stock_threshold ?? 0;

        return $this->stock_quantity <= $threshold && $this->stock_quantity > 0;
    }

    /**
     * Check if product is out of stock at this branch
     */
    public function isOutOfStock(): bool
    {
        if ($this->product->stock_tracking === 'none') {
            return false;
        }

        return $this->stock_quantity <= 0 && ! $this->allow_backorder;
    }

    /**
     * Check if reorder is needed
     */
    public function needsReorder(): bool
    {
        if (! $this->reorder_point) {
            return false;
        }

        return $this->stock_quantity <= $this->reorder_point;
    }

    /**
     * Update stock quantity
     */
    public function updateStock(float $quantity, string $operation = 'add'): bool
    {
        if ($this->product->stock_tracking === 'none') {
            return false;
        }

        $quantity = \App\Support\Quantity::normalize($quantity);

        if ($operation === 'add') {
            $this->stock_quantity = \App\Support\Quantity::normalize((float) $this->stock_quantity + $quantity);
        } elseif ($operation === 'subtract') {
            $this->stock_quantity = \App\Support\Quantity::normalize((float) $this->stock_quantity - $quantity);
        } elseif ($operation === 'set') {
            $this->stock_quantity = $quantity;
        }

        return $this->save();
    }

    /**
     * Update shelf quantity
     */
    public function updateShelfQuantity(float $quantity, string $operation = 'add'): bool
    {
        if ($this->product->stock_tracking === 'none') {
            return false;
        }

        $quantity = \App\Support\Quantity::normalize($quantity);

        if ($operation === 'add') {
            $this->shelf_quantity = \App\Support\Quantity::normalize((float) $this->shelf_quantity + $quantity);
        } elseif ($operation === 'subtract') {
            $this->shelf_quantity = max(0, \App\Support\Quantity::normalize((float) $this->shelf_quantity - $quantity));
        } elseif ($operation === 'set') {
            $this->shelf_quantity = max(0, $quantity);
        }

        $this->stock_quantity = \App\Support\Quantity::normalize((float) $this->shelf_quantity + (float) $this->store_quantity);

        return $this->save();
    }

    /**
     * Update store quantity
     */
    public function updateStoreQuantity(float $quantity, string $operation = 'add'): bool
    {
        if ($this->product->stock_tracking === 'none') {
            return false;
        }

        $quantity = \App\Support\Quantity::normalize($quantity);

        if ($operation === 'add') {
            $this->store_quantity = \App\Support\Quantity::normalize((float) $this->store_quantity + $quantity);
        } elseif ($operation === 'subtract') {
            $this->store_quantity = max(0, \App\Support\Quantity::normalize((float) $this->store_quantity - $quantity));
        } elseif ($operation === 'set') {
            $this->store_quantity = max(0, $quantity);
        }

        $this->stock_quantity = \App\Support\Quantity::normalize((float) $this->shelf_quantity + (float) $this->store_quantity);

        return $this->save();
    }

    /**
     * Move stock from store to shelf
     */
    public function moveToShelf(int $quantity): bool
    {
        if ($this->product->stock_tracking === 'none') {
            return false;
        }

        if ($quantity > $this->store_quantity) {
            return false; // Not enough in store
        }

        $this->store_quantity -= $quantity;
        $this->shelf_quantity += $quantity;
        // Total stock quantity remains the same

        return $this->save();
    }

    /**
     * Move stock from shelf to store
     */
    public function moveToStore(int $quantity): bool
    {
        if ($this->product->stock_tracking === 'none') {
            return false;
        }

        if ($quantity > $this->shelf_quantity) {
            return false; // Not enough on shelf
        }

        $this->shelf_quantity -= $quantity;
        $this->store_quantity += $quantity;
        // Total stock quantity remains the same

        return $this->save();
    }

    /**
     * Get total stock quantity (shelf + store)
     */
    public function getTotalStockQuantity(): float
    {
        return (float) $this->shelf_quantity + (float) $this->store_quantity;
    }

    /**
     * Deduct quantity for a sale from physical locations (shelf first, then store).
     * Keeps stock_quantity = shelf_quantity + store_quantity. Handles legacy data
     * where only stock_quantity was set (shelf+store 0).
     *
     * @return array{stock_tracked: bool, from_shelf: float, from_store: float, quantity_before: float, quantity_after: float, shelf_quantity_before: float, store_quantity_before: float, shelf_quantity_after: float, store_quantity_after: float}
     */
    public function deductForSale(float $quantity): array
    {
        $quantity = \App\Support\Quantity::normalize($quantity);

        $zeroResult = [
            'stock_tracked' => false,
            'from_shelf' => 0.0,
            'from_store' => 0.0,
            'quantity_before' => (float) $this->stock_quantity,
            'quantity_after' => (float) $this->stock_quantity,
            'shelf_quantity_before' => (float) $this->shelf_quantity,
            'store_quantity_before' => (float) $this->store_quantity,
            'shelf_quantity_after' => (float) $this->shelf_quantity,
            'store_quantity_after' => (float) $this->store_quantity,
        ];

        // if ($this->product->stock_tracking === 'none') {
        //     return $zeroResult;
        // }

        $shelfBefore = (float) $this->shelf_quantity;
        $storeBefore = (float) $this->store_quantity;
        $totalAvailable = $shelfBefore + $storeBefore;

        if (\App\Support\Quantity::isZero($totalAvailable) && (float) $this->stock_quantity > 0) {
            $this->store_quantity = $this->stock_quantity;
            $storeBefore = (float) $this->stock_quantity;
            $totalAvailable = $storeBefore;
        }

        $toDeduct = min($quantity, $totalAvailable);
        $fromShelf = min($toDeduct, $shelfBefore);
        $fromStore = \App\Support\Quantity::normalize($toDeduct - $fromShelf);

        $this->shelf_quantity = max(0, \App\Support\Quantity::normalize($shelfBefore - $fromShelf));
        $this->store_quantity = max(0, \App\Support\Quantity::normalize($storeBefore - $fromStore));
        $this->stock_quantity = \App\Support\Quantity::normalize((float) $this->shelf_quantity + (float) $this->store_quantity);
        $this->save();

        return [
            'stock_tracked' => true,
            'from_shelf' => $fromShelf,
            'from_store' => $fromStore,
            'quantity_before' => $shelfBefore + $storeBefore,
            'quantity_after' => $this->stock_quantity,
            'shelf_quantity_before' => $shelfBefore,
            'store_quantity_before' => $storeBefore,
            'shelf_quantity_after' => $this->shelf_quantity,
            'store_quantity_after' => $this->store_quantity,
        ];
    }

    /**
     * Check if shelf needs restocking from store
     */
    public function shelfNeedsRestocking(): bool
    {
        $threshold = $this->low_stock_threshold ?? $this->product->low_stock_threshold ?? 0;

        return $this->shelf_quantity <= $threshold && $this->store_quantity > 0;
    }

    /**
     * Scope to get only available products
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    /**
     * Scope to get only featured products
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Scope to get in-stock products
     */
    public function scopeInStock($query)
    {
        return $query->where(function ($q) {
            $q->where('stock_quantity', '>', 0)
                ->orWhere('allow_backorder', true);
        });
    }

    /**
     * Scope to get low stock products
     */
    public function scopeLowStock($query)
    {
        return $query->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->where('stock_quantity', '>', 0);
    }

    /**
     * Scope to filter by branch
     */
    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * Verify if the discount on this branch product is from a valid quick sale
     * If not valid, remove the discount
     */
    public function verifyAndCleanQuickSaleDiscount(): void
    {
        // If no discount is applied, nothing to do
        if (! $this->discount_type || ! $this->discount_amount) {
            return;
        }

        // Check if there's an active quick sale that matches this discount
        $activeQuickSale = QuickSale::where('product_id', $this->product_id)
            ->where('branch_id', $this->branch_id)
            ->where('status', QuickSale::STATUS_ACTIVE)
            ->where('discount_type', $this->discount_type)
            ->where('discount_value', $this->discount_amount)
            ->where('start_time', '<=', now())
            ->where('end_time', '>', now())
            ->first();

        // If no active quick sale matches this discount, remove it
        if (! $activeQuickSale) {
            $this->update([
                'discount_type' => null,
                'discount_amount' => null,
            ]);
        }
    }

    /**
     * Static method to verify and clean discounts for multiple branch products
     */
    public static function verifyAndCleanDiscountsForProducts(array $branchProductIds): void
    {
        $branchProducts = self::whereIn('id', $branchProductIds)
            ->whereNotNull('discount_type')
            ->get();

        foreach ($branchProducts as $branchProduct) {
            $branchProduct->verifyAndCleanQuickSaleDiscount();
        }
    }

    /**
     * Static method to verify and clean all discounts for a branch
     */
    public static function verifyAndCleanDiscountsForBranch(int $branchId): void
    {
        $branchProducts = self::where('branch_id', $branchId)
            ->whereNotNull('discount_type')
            ->get();

        foreach ($branchProducts as $branchProduct) {
            $branchProduct->verifyAndCleanQuickSaleDiscount();
        }
    }
}
