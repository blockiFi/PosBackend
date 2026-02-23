<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchProductQuantityTier extends Model
{
    protected $fillable = [
        'branch_product_id',
        'min_quantity',
        'max_quantity',
        'price_per_unit',
    ];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
            'price_per_unit' => 'decimal:2',
        ];
    }

    public function branchProduct(): BelongsTo
    {
        return $this->belongsTo(BranchProduct::class);
    }
}
