<?php

namespace App\Services;

use App\Models\BranchProduct;

/**
 * Resolves unit price for a branch product and quantity using tiered pricing:
 * 1) Exact pack match (e.g. qty 6 → "pack of 6" price)
 * 2) Quantity range tier (e.g. 6–19 units = 450/unit)
 * 3) Default single-unit price (branch_product.selling_price)
 */
class TieredPricingService
{
    /**
     * Get the effective unit price and tier info for a given quantity.
     *
     * @return array{unit_price: float, total: float, tier_type: string, product_unit_id: int|null, quantity_tier_id: int|null, cost_per_unit: float|null}
     */
    public function getUnitPrice(BranchProduct $branchProduct, float $quantity): array
    {
        $branchProduct->loadMissing(['unitPrices.productUnit', 'quantityTiers']);

        $costPerUnit = (float) $branchProduct->getEffectiveCostPrice();

        // 1) Exact pack match: quantity equals a unit's multiplier (integer qty only)
        if (fmod($quantity, 1.0) === 0.0) {
            $qtyInt = (int) $quantity;
            $unitPriceRow = $branchProduct->unitPrices
                ->first(fn ($up) => (int) $up->productUnit->quantity_multiplier === $qtyInt
                    && ($up->productUnit->min_quantity === null || $qtyInt >= $up->productUnit->min_quantity));

            if ($unitPriceRow) {
            $multiplier = (int) $unitPriceRow->productUnit->quantity_multiplier;
            $unitPrice = (float) $unitPriceRow->selling_price / $multiplier;

                return [
                    'unit_price' => round($unitPrice, 2),
                    'total' => round($unitPrice * $quantity, 2),
                    'tier_type' => 'pack',
                    'product_unit_id' => $unitPriceRow->product_unit_id,
                    'quantity_tier_id' => null,
                    'cost_per_unit' => $costPerUnit,
                ];
            }
        }

        // 2) Quantity range tier
        $tier = $branchProduct->quantityTiers
            ->first(fn ($t) => $quantity >= (int) $t->min_quantity
                && ($t->max_quantity === null || $quantity <= (int) $t->max_quantity));

        if ($tier) {
            $unitPrice = (float) $tier->price_per_unit;

            return [
                'unit_price' => round($unitPrice, 2),
                'total' => round($unitPrice * $quantity, 2),
                'tier_type' => 'quantity_range',
                'product_unit_id' => null,
                'quantity_tier_id' => $tier->id,
                'cost_per_unit' => $costPerUnit,
            ];
        }

        // 3) Default single-unit price
        $unitPrice = (float) $branchProduct->getEffectiveSellingPrice();

        return [
            'unit_price' => round($unitPrice, 2),
            'total' => round($unitPrice * $quantity, 2),
            'tier_type' => 'single',
            'product_unit_id' => null,
            'quantity_tier_id' => null,
            'cost_per_unit' => $costPerUnit,
        ];
    }
}
