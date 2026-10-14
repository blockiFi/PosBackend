<?php

namespace App\Services;

use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\QuickSale;

/**
 * Shared branch-product creation and transformation, used by POST /branch-products
 * and by POST /products when the product is created straight into a branch.
 */
class BranchProductService
{
    /**
     * Validation rules for the branch-level attributes of a new branch product
     * (everything except branch_id / product_id).
     *
     * @return array<string, array<int, string>>
     */
    public static function attributeRules(): array
    {
        return [
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'compare_price' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', 'in:fixed,percentage'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stock_quantity' => ['nullable', 'numeric', 'min:0'],
            'shelf_quantity' => ['nullable', 'numeric', 'min:0'],
            'store_quantity' => ['nullable', 'numeric', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'allow_backorder' => ['nullable', 'boolean'],
            'reorder_point' => ['nullable', 'integer', 'min:0'],
            'reorder_quantity' => ['nullable', 'numeric', 'min:0'],
            'is_available' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer'],
            'bin_location' => ['nullable', 'string', 'max:255'],
            'shelf_location' => ['nullable', 'string', 'max:255'],
            'branch_meta_data' => ['nullable', 'array'],
        ];
    }

    /**
     * Create the branch product for a product. Shelf + store make up the stock; a bare
     * stock_quantity (no shelf/store given) goes on the shelf. The selling price starts
     * from the product's base selling price.
     *
     * @param  array<string, mixed>  $data  Validated attributes (see attributeRules()).
     */
    public function createForBranch(Product $product, int $branchId, array $data): BranchProduct
    {
        $shelfQty = $data['shelf_quantity'] ?? 0;
        $storeQty = $data['store_quantity'] ?? 0;
        $totalStock = $shelfQty + $storeQty;

        if (isset($data['stock_quantity']) && ! isset($data['shelf_quantity']) && ! isset($data['store_quantity'])) {
            $shelfQty = $data['stock_quantity'];
            $totalStock = $data['stock_quantity'];
        }

        return BranchProduct::create([
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'cost_price' => $data['cost_price'] ?? null,
            'selling_price' => $product->base_selling_price ?? null,
            'compare_price' => $data['compare_price'] ?? null,
            'discount_amount' => $data['discount_amount'] ?? null,
            'discount_type' => $data['discount_type'] ?? null,
            'tax_rate' => $data['tax_rate'] ?? null,
            'stock_quantity' => $totalStock,
            'shelf_quantity' => $shelfQty,
            'store_quantity' => $storeQty,
            'low_stock_threshold' => $data['low_stock_threshold'] ?? null,
            'allow_backorder' => $data['allow_backorder'] ?? false,
            'reorder_point' => $data['reorder_point'] ?? null,
            'reorder_quantity' => $data['reorder_quantity'] ?? null,
            'is_available' => $data['is_available'] ?? true,
            'is_featured' => $data['is_featured'] ?? false,
            'display_order' => $data['display_order'] ?? 0,
            'bin_location' => $data['bin_location'] ?? null,
            'shelf_location' => $data['shelf_location'] ?? null,
            'branch_meta_data' => $data['branch_meta_data'] ?? null,
        ]);
    }

    /**
     * The branch product shape returned by the /branch-products endpoints.
     *
     * @return array<string, mixed>
     */
    public function transform(BranchProduct $branchProduct): array
    {
        $activeQuickSale = QuickSale::getActiveQuickSale(
            $branchProduct->product_id,
            $branchProduct->branch_id
        );

        $quickSaleData = null;
        if ($activeQuickSale) {
            $quickSaleData = [
                'id' => $activeQuickSale->id,
                'discount_type' => $activeQuickSale->discount_type,
                'discount_value' => $activeQuickSale->discount_value,
                'batch_id' => $activeQuickSale->batch_id,
                'start_time' => $activeQuickSale->start_time,
                'end_time' => $activeQuickSale->end_time,
                'status' => $activeQuickSale->status,
            ];
        }

        return [
            'id' => $branchProduct->id,
            'branch_id' => $branchProduct->branch_id,
            'product_id' => $branchProduct->product_id,
            'product' => [
                'id' => $branchProduct->product->id,
                'name' => $branchProduct->product->name,
                'sku' => $branchProduct->product->sku,
                'barcode' => $branchProduct->product->barcode,
                'image' => $branchProduct->product->image,
                'category' => $branchProduct->product->category ? [
                    'id' => $branchProduct->product->category->id,
                    'name' => $branchProduct->product->category->name,
                ] : null,
            ],
            'pricing' => [
                'cost_price' => $branchProduct->cost_price,
                'selling_price' => $branchProduct->selling_price,
                'compare_price' => $branchProduct->compare_price,
                'discount_amount' => $branchProduct->discount_amount,
                'discount_type' => $branchProduct->discount_type,
                'tax_rate' => $branchProduct->tax_rate,
                'final_price' => $branchProduct->getFinalPrice(),
                'price_with_tax' => $branchProduct->getPriceWithTax(),
                'profit_margin' => $branchProduct->getProfitMargin(),
            ],
            'quick_sale' => $quickSaleData,
            'inventory' => [
                'stock_quantity' => $branchProduct->stock_quantity,
                'shelf_quantity' => $branchProduct->shelf_quantity,
                'store_quantity' => $branchProduct->store_quantity,
                'low_stock_threshold' => $branchProduct->low_stock_threshold,
                'allow_backorder' => $branchProduct->allow_backorder,
                'reorder_point' => $branchProduct->reorder_point,
                'reorder_quantity' => $branchProduct->reorder_quantity,
                'is_in_stock' => $branchProduct->isInStock(),
                'is_low_stock' => $branchProduct->isLowStock(),
                'is_out_of_stock' => $branchProduct->isOutOfStock(),
                'needs_reorder' => $branchProduct->needsReorder(),
                'shelf_needs_restocking' => $branchProduct->shelfNeedsRestocking(),
                'bin_location' => $branchProduct->bin_location,
                'shelf_location' => $branchProduct->shelf_location,
            ],
            'settings' => [
                'is_available' => $branchProduct->is_available,
                'is_featured' => $branchProduct->is_featured,
                'display_order' => $branchProduct->display_order,
            ],
            'branch_meta_data' => $branchProduct->branch_meta_data,
            'created_at' => $branchProduct->created_at,
            'updated_at' => $branchProduct->updated_at,
        ];
    }
}
