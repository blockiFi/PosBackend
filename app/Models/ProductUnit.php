<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductUnit extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'quantity_multiplier',
        'min_quantity',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity_multiplier' => 'integer',
            'min_quantity' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branchProductUnitPrices(): HasMany
    {
        return $this->hasMany(BranchProductUnitPrice::class);
    }
}
