<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProductPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'supplier_id',
        'product_id',
        'currency',
        'last_unit_cost',
        'last_received_at',
        'avg_unit_cost',
        'receipt_count',
    ];

    protected $casts = [
        'last_unit_cost' => 'decimal:2',
        'avg_unit_cost' => 'decimal:4',
        'receipt_count' => 'integer',
        'last_received_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
