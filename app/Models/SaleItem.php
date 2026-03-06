<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'product_id',
        'batch_id',
        'product_name',
        'product_sku',
        'description',
        'quantity',
        'unit_price',
        'discount_amount',
        'discount_percentage',
        'tax_rate',
        'tax_amount',
        'subtotal',
        'total',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
        'metadata' => 'array',
    ];

    // Relationships
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    // Helper methods
    public function calculateTotals(): void
    {
        // Calculate subtotal
        $this->subtotal = $this->quantity * $this->unit_price;

        // Apply discount
        $discountAmount = $this->discount_amount ?? 0;
        if ($this->discount_percentage > 0) {
            $discountAmount = $this->subtotal * ($this->discount_percentage / 100);
        }
        $this->discount_amount = $discountAmount;

        // Calculate after discount
        $afterDiscount = $this->subtotal - $discountAmount;

        // Calculate tax
        $this->tax_amount = $afterDiscount * (($this->tax_rate ?? 0) / 100);

        // Calculate total
        $this->total = $afterDiscount + $this->tax_amount;
    }
}
