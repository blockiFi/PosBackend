<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed entities for seeding (V1: single entity per request)
    |--------------------------------------------------------------------------
    |
    | Keys are entity identifiers; values are allowed DB columns (and virtual
    | keys like "category" for products). Mapping values in the request must
    | be in this list for the given entity.
    |
    */

    'allowed_entities' => [
        'products' => [
            'name',
            'sku',
            'barcode',
            'description',
            'image',
            'base_cost_price',
            'base_selling_price',
            'is_taxable',
            'default_tax_rate',
            'unit_of_measure',
            'weight',
            'weight_unit',
            'stock_tracking',
            'low_stock_threshold',
            'is_active',
            'is_available_online',
            'meta_data',
            'sort_order',
            'category', // virtual: resolve by name to category_id
            'retail_value', // virtual: selling_price = retail_value / stock_quantity
            // BranchProduct columns (used when branch_ids provided)
            'cost_price',
            'selling_price',
            'compare_price',
            'stock_quantity',
            'shelf_quantity',
            'store_quantity',
            'is_available',
        ],
        'product_categories' => [
            'name',
            'slug',
            'description',
            'image',
            'sort_order',
            'is_active',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | BranchProduct columns (when branch_ids is provided for products)
    |--------------------------------------------------------------------------
    */

    'branch_product_columns' => [
        'cost_price',
        'selling_price',
        'compare_price',
        'stock_quantity',
        'shelf_quantity',
        'store_quantity',
        'low_stock_threshold',
        'is_available',
    ],

];
