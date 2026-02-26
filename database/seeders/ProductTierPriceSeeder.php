<?php

namespace Database\Seeders;

use App\Models\BranchProduct;
use App\Models\BranchProductQuantityTier;
use App\Models\BranchProductUnitPrice;
use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Seeds product tier pricing for already existing products in the database.
 *
 * Does not create products; only adds:
 * - Product units (e.g. Piece, Pack of 6, Dozen, Case)
 * - Branch product unit prices (per unit, with pack discounts)
 * - Branch product quantity tiers (volume discounts)
 *
 * Unit and tier definitions are chosen by product category (Electronics, Groceries,
 * Beverages, Household, etc.) for realistic demo data.
 *
 * Idempotent: skips products/rows that already have tier data.
 *
 * Usage:
 *   php artisan db:seed --class=ProductTierPriceSeeder
 */
class ProductTierPriceSeeder extends Seeder
{
    /**
     * Pack discount: price per base unit in a pack = base_price * pack_discount.
     * E.g. 0.92 for pack of 6 => 6 units for ~5.52x single price (~8% off).
     */
    private const PACK_DISCOUNT_MULTIPLIER = 0.92;

    /**
     * Unit definitions by root category name.
     * Each entry: name => [quantity_multiplier, display_order].
     *
     * @var array<string, array<string, array{0: int, 1: int}>>
     */
    private const UNITS_BY_CATEGORY = [
        'Electronics' => [
            'Piece' => [1, 0],
        ],
        'Groceries' => [
            'Piece' => [1, 0],
            'Pack of 6' => [6, 1],
            'Dozen' => [12, 2],
            'Carton (24)' => [24, 3],
        ],
        'Beverages' => [
            'Single' => [1, 0],
            '6-Pack' => [6, 1],
            'Case (24)' => [24, 2],
        ],
        'Household Items' => [
            'Piece' => [1, 0],
            'Pack of 6' => [6, 1],
            'Pack of 12' => [12, 2],
        ],
        'Personal Care' => [
            'Piece' => [1, 0],
            'Twin Pack' => [2, 1],
            'Pack of 6' => [6, 2],
        ],
        'Office Supplies' => [
            'Piece' => [1, 0],
            'Pack of 5' => [5, 1],
            'Pack of 10' => [10, 2],
        ],
        'default' => [
            'Piece' => [1, 0],
            'Pack of 6' => [6, 1],
            'Pack of 12' => [12, 2],
        ],
    ];

    /**
     * Quantity tiers by root category: [min_quantity, max_quantity (null = no cap), discount (e.g. 0.95 = 5% off)].
     *
     * @var array<string, list<array{0: int, 1: int|null, 2: float}>>
     */
    private const TIERS_BY_CATEGORY = [
        'Electronics' => [
            [1, 2, 1.00],
            [3, 9, 0.97],
            [10, null, 0.93],
        ],
        'Groceries' => [
            [1, 5, 1.00],
            [6, 19, 0.95],
            [20, 49, 0.90],
            [50, null, 0.85],
        ],
        'Beverages' => [
            [1, 5, 1.00],
            [6, 23, 0.95],
            [24, 47, 0.90],
            [48, null, 0.85],
        ],
        'Household Items' => [
            [1, 5, 1.00],
            [6, 19, 0.95],
            [20, null, 0.90],
        ],
        'Personal Care' => [
            [1, 5, 1.00],
            [6, 11, 0.95],
            [12, null, 0.90],
        ],
        'Office Supplies' => [
            [1, 4, 1.00],
            [5, 19, 0.95],
            [20, null, 0.88],
        ],
        'default' => [
            [1, 5, 1.00],
            [6, 19, 0.95],
            [20, null, 0.90],
        ],
    ];

    public function run(): void
    {
        $products = Product::with(['branchProducts', 'category.parent'])
            ->whereHas('branchProducts')
            ->get();

        if ($products->isEmpty()) {
            $this->command->warn('No products with branch assignments found. Ensure products and branch_products exist first.');

            return;
        }

        $this->command->info('Seeding tier prices for '.$products->count().' existing products...');

        $unitsCreated = 0;
        $unitPricesCreated = 0;
        $tiersCreated = 0;
        $productsProcessed = 0;
        $productsSkipped = 0;

        foreach ($products as $product) {
            $rootCategoryName = $this->rootCategoryName($product);
            $unitDefs = self::UNITS_BY_CATEGORY[$rootCategoryName] ?? self::UNITS_BY_CATEGORY['default'];
            $tierDefs = self::TIERS_BY_CATEGORY[$rootCategoryName] ?? self::TIERS_BY_CATEGORY['default'];

            $productUnits = $this->ensureProductUnits($product, $unitDefs);
            $newUnits = $productUnits->count();
            $unitsCreated += $newUnits;

            $anyBranchWork = false;
            foreach ($product->branchProducts as $branchProduct) {
                $up = $this->ensureBranchProductUnitPrices($branchProduct, $productUnits);
                $tp = $this->ensureBranchProductQuantityTiers($branchProduct, $tierDefs);
                $unitPricesCreated += $up;
                $tiersCreated += $tp;
                if ($up > 0 || $tp > 0) {
                    $anyBranchWork = true;
                }
            }

            if ($newUnits > 0 || $anyBranchWork) {
                $productsProcessed++;
            } else {
                $productsSkipped++;
            }
        }

        $this->command->info('Product tier prices seeded for existing products.');
        $this->command->table(
            ['Metric', 'Count'],
            [
                ['Products processed (new tier data)', $productsProcessed],
                ['Products skipped (already had tier data)', $productsSkipped],
                ['Product unit definitions created', $unitsCreated],
                ['Branch product unit prices created', $unitPricesCreated],
                ['Branch product quantity tiers created', $tiersCreated],
            ]
        );
    }

    private function rootCategoryName(Product $product): string
    {
        $category = $product->category;

        if (! $category) {
            return 'default';
        }

        $root = $category->parent_id && $category->parent
            ? $category->parent
            : $category;

        return $root->name ?? 'default';
    }

    /**
     * Create or get unit definitions for this product.
     *
     * @param  array<string, array{0: int, 1: int}>  $unitDefs  name => [multiplier, display_order]
     * @return Collection<int, ProductUnit>
     */
    private function ensureProductUnits(Product $product, array $unitDefs): Collection
    {
        $existing = $product->units()->get()->keyBy('name');
        $created = collect();

        foreach ($unitDefs as $name => [$multiplier, $displayOrder]) {
            if ($existing->has($name)) {
                $created->push($existing->get($name));

                continue;
            }

            $unit = ProductUnit::create([
                'product_id' => $product->id,
                'name' => $name,
                'quantity_multiplier' => $multiplier,
                'min_quantity' => null,
                'display_order' => $displayOrder,
            ]);
            $created->push($unit);
        }

        return $created;
    }

    private function ensureBranchProductUnitPrices(BranchProduct $branchProduct, Collection $productUnits): int
    {
        $basePrice = (float) ($branchProduct->selling_price ?? $branchProduct->product->base_selling_price ?? 0);

        if ($basePrice <= 0) {
            return 0;
        }

        $created = 0;

        foreach ($productUnits as $unit) {
            $exists = BranchProductUnitPrice::where('branch_product_id', $branchProduct->id)
                ->where('product_unit_id', $unit->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $multiplier = $unit->quantity_multiplier;
            if ($multiplier === 1) {
                $sellingPrice = $basePrice;
            } else {
                $sellingPrice = round($basePrice * $multiplier * self::PACK_DISCOUNT_MULTIPLIER, 2);
            }

            BranchProductUnitPrice::create([
                'branch_product_id' => $branchProduct->id,
                'product_unit_id' => $unit->id,
                'selling_price' => $sellingPrice,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * @param  list<array{0: int, 1: int|null, 2: float}>  $tierDefs  [min, max, discount_multiplier]
     */
    private function ensureBranchProductQuantityTiers(BranchProduct $branchProduct, array $tierDefs): int
    {
        $basePrice = (float) ($branchProduct->selling_price ?? $branchProduct->product->base_selling_price ?? 0);

        if ($basePrice <= 0) {
            return 0;
        }

        if ($branchProduct->quantityTiers()->count() > 0) {
            return 0;
        }

        foreach ($tierDefs as [$min, $max, $multiplier]) {
            BranchProductQuantityTier::create([
                'branch_product_id' => $branchProduct->id,
                'min_quantity' => $min,
                'max_quantity' => $max,
                'price_per_unit' => round($basePrice * $multiplier, 2),
            ]);
        }

        return count($tierDefs);
    }
}
