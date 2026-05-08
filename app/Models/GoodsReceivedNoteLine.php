<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class GoodsReceivedNoteLine extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'goods_received_note_id',
        'purchase_order_line_id',
        'product_id',
        'branch_product_id',
        'quantity_ordered',
        'quantity_received',
        'quantity_accepted',
        'quantity_rejected',
        'rejection_reason',
        'unit_cost',
        'tax_rate',
        'line_total',
        'batch_number',
        'lot_number',
        'manufacturing_date',
        'expiry_date',
        'storage_location',
        'inventory_transaction_id',
        'batch_id',
        'notes',
        'meta_data',
    ];

    protected $casts = [
        'quantity_ordered' => 'decimal:3',
        'quantity_received' => 'decimal:3',
        'quantity_accepted' => 'decimal:3',
        'quantity_rejected' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'line_total' => 'decimal:2',
        'manufacturing_date' => 'date',
        'expiry_date' => 'date',
        'meta_data' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($line) {
            if (empty($line->uuid)) {
                $line->uuid = (string) Str::uuid();
            }
        });
    }

    public function goodsReceivedNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branchProduct(): BelongsTo
    {
        return $this->belongsTo(BranchProduct::class);
    }

    public function inventoryTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }
}
