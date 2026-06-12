<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class InventoryTransaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'business_id',
        'branch_id',
        'product_id',
        'user_id',
        'batch_id',
        'goods_received_note_line_id',
        'type',
        'quantity',
        'shelf_quantity',
        'store_quantity',
        'quantity_before',
        'shelf_quantity_before',
        'store_quantity_before',
        'quantity_after',
        'shelf_quantity_after',
        'store_quantity_after',
        'unit_cost',
        'total_cost',
        'related_branch_id',
        'related_transaction_id',
        'supplier_id',
        'stock_transfer_request_id',
        'reference_number',
        'notes',
        'meta_data',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'shelf_quantity' => 'decimal:3',
        'store_quantity' => 'decimal:3',
        'quantity_before' => 'decimal:3',
        'shelf_quantity_before' => 'decimal:3',
        'store_quantity_before' => 'decimal:3',
        'quantity_after' => 'decimal:3',
        'shelf_quantity_after' => 'decimal:3',
        'store_quantity_after' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'meta_data' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            if (empty($transaction->uuid)) {
                $transaction->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Transaction types
     */
    const TYPE_PURCHASE = 'purchase';

    const TYPE_SALE = 'sale';

    const TYPE_ADJUSTMENT = 'adjustment';

    const TYPE_TRANSFER_OUT = 'transfer_out';

    const TYPE_TRANSFER_IN = 'transfer_in';

    const TYPE_RETURN = 'return';

    const TYPE_DAMAGE = 'damage';

    const TYPE_INITIAL = 'initial';

    /**
     * Relationships
     */
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function relatedBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'related_branch_id');
    }

    public function relatedTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'related_transaction_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    public function goodsReceivedNoteLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNoteLine::class, 'goods_received_note_line_id');
    }

    public function stockTransferRequest(): BelongsTo
    {
        return $this->belongsTo(StockTransferRequest::class, 'stock_transfer_request_id');
    }

    /**
     * Scopes
     */
    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeStockIn($query)
    {
        return $query->whereIn('type', [
            self::TYPE_PURCHASE,
            self::TYPE_TRANSFER_IN,
            self::TYPE_RETURN,
            self::TYPE_INITIAL,
        ]);
    }

    public function scopeStockOut($query)
    {
        return $query->whereIn('type', [
            self::TYPE_SALE,
            self::TYPE_TRANSFER_OUT,
            self::TYPE_DAMAGE,
        ]);
    }

    /**
     * Helper methods
     */
    public function isStockIn(): bool
    {
        return in_array($this->type, [
            self::TYPE_PURCHASE,
            self::TYPE_TRANSFER_IN,
            self::TYPE_RETURN,
            self::TYPE_INITIAL,
        ]);
    }

    public function isStockOut(): bool
    {
        return in_array($this->type, [
            self::TYPE_SALE,
            self::TYPE_TRANSFER_OUT,
            self::TYPE_DAMAGE,
        ]);
    }

    public function isTransfer(): bool
    {
        return in_array($this->type, [
            self::TYPE_TRANSFER_OUT,
            self::TYPE_TRANSFER_IN,
        ]);
    }
}
