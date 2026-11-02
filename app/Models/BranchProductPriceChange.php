<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One price a branch item had, from effective_from until the next row.
 * Written automatically whenever the selling price or promotion discount
 * changes (see BranchProduct and Product boot).
 */
class BranchProductPriceChange extends Model
{
    /** How far before an uploaded sale a till's price may still be matched. */
    public const SALE_LOOKBACK_DAYS = 3;

    protected $fillable = [
        'branch_product_id',
        'price',
        'full_price',
        'effective_from',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'full_price' => 'decimal:2',
        'effective_from' => 'datetime',
    ];

    public function branchProduct(): BelongsTo
    {
        return $this->belongsTo(BranchProduct::class);
    }

    /**
     * Save the item's current price, unless it is already the latest one.
     */
    public static function record(BranchProduct $branchProduct, ?CarbonInterface $at = null): void
    {
        $price = round((float) $branchProduct->getFinalPrice(), 2);
        $fullPrice = round((float) $branchProduct->getEffectiveSellingPrice(), 2);

        $latest = static::query()
            ->where('branch_product_id', $branchProduct->id)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
        if ($latest && self::samePrice((float) $latest->price, $price) && self::samePrice((float) $latest->full_price, $fullPrice)) {
            return;
        }

        static::create([
            'branch_product_id' => $branchProduct->id,
            'price' => $price,
            'full_price' => $fullPrice,
            'effective_from' => $at ?? now(),
        ]);
    }

    /**
     * Save a price change. The first time an item changes, its old price is
     * saved too (from when the item was added), so sales made before the
     * change can still be matched to it.
     */
    public static function recordChange(BranchProduct $before, BranchProduct $after): void
    {
        if (! static::where('branch_product_id', $after->id)->exists()) {
            static::record($before, $after->created_at ?? now());
        }
        static::record($after);
    }

    /**
     * Whether the item had this price (with or without its discount) at some
     * point in the days before the sale. A till can only charge a price the
     * store set, so a match means the till charged what was right when it
     * last heard from the server.
     */
    public static function wasPriceBefore(BranchProduct $branchProduct, float $unitPrice, CarbonInterface $soldAt): bool
    {
        $from = $soldAt->copy()->subDays(self::SALE_LOOKBACK_DAYS);
        // Allow for a till clock a little behind the server's
        $to = $soldAt->copy()->addMinutes(5);

        $rows = static::query()
            ->where('branch_product_id', $branchProduct->id)
            ->where('effective_from', '>', $from)
            ->where('effective_from', '<=', $to)
            ->get(['price', 'full_price']);
        $inEffectAtStart = static::query()
            ->where('branch_product_id', $branchProduct->id)
            ->where('effective_from', '<=', $from)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first(['price', 'full_price']);
        if ($inEffectAtStart) {
            $rows->push($inEffectAtStart);
        }

        return $rows->contains(fn (self $row) => self::samePrice((float) $row->price, $unitPrice)
            || self::samePrice((float) $row->full_price, $unitPrice));
    }

    private static function samePrice(float $a, float $b): bool
    {
        return abs($a - $b) < 0.005;
    }
}
