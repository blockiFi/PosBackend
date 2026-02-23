<?php

namespace Database\Seeders;

use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get existing businesses and branches
        $businesses = Business::all();

        if ($businesses->isEmpty()) {
            $this->command->warn('No businesses found. Please run BusinessSeeder first.');

            return;
        }

        foreach ($businesses as $business) {
            $this->command->info("Seeding products for business: {$business->name}");

            // Create categories
            $categories = $this->createCategories($business);

            // Create products
            $this->createProducts($business, $categories);
        }
    }

    private function createCategories(Business $business): array
    {
        $categories = [];

        // Electronics category with subcategories
        $electronics = ProductCategory::create([
            'business_id' => $business->id,
            'name' => 'Electronics',
            'description' => 'Electronic devices and accessories',
            'parent_id' => null,
        ]);
        $categories['electronics'] = $electronics;

        ProductCategory::create([
            'business_id' => $business->id,
            'name' => 'Mobile Phones',
            'parent_id' => $electronics->id,
        ]);

        ProductCategory::create([
            'business_id' => $business->id,
            'name' => 'Laptops',
            'parent_id' => $electronics->id,
        ]);

        ProductCategory::create([
            'business_id' => $business->id,
            'name' => 'Accessories',
            'parent_id' => $electronics->id,
        ]);

        // Groceries with subcategories
        $groceries = ProductCategory::create([
            'business_id' => $business->id,
            'name' => 'Groceries',
            'description' => 'Food and grocery items',
            'parent_id' => null,
        ]);
        $categories['groceries'] = $groceries;

        ProductCategory::create([
            'business_id' => $business->id,
            'name' => 'Dairy Products',
            'parent_id' => $groceries->id,
        ]);

        ProductCategory::create([
            'business_id' => $business->id,
            'name' => 'Beverages',
            'parent_id' => $groceries->id,
        ]);

        ProductCategory::create([
            'business_id' => $business->id,
            'name' => 'Snacks',
            'parent_id' => $groceries->id,
        ]);

        // Beverages category
        $categories['beverages'] = ProductCategory::create([
            'business_id' => $business->id,
            'name' => 'Beverages',
            'description' => 'Soft drinks, juices, and water',
            'parent_id' => null,
        ]);

        // Household Items
        $household = ProductCategory::create([
            'business_id' => $business->id,
            'name' => 'Household Items',
            'description' => 'Cleaning supplies and home essentials',
            'parent_id' => null,
        ]);
        $categories['household'] = $household;

        ProductCategory::create([
            'business_id' => $business->id,
            'name' => 'Cleaning Supplies',
            'parent_id' => $household->id,
        ]);

        // Personal Care
        $categories['personal_care'] = ProductCategory::create([
            'business_id' => $business->id,
            'name' => 'Personal Care',
            'description' => 'Health and beauty products',
            'parent_id' => null,
        ]);

        // Office Supplies
        $categories['office'] = ProductCategory::create([
            'business_id' => $business->id,
            'name' => 'Office Supplies',
            'description' => 'Stationery and office equipment',
            'parent_id' => null,
        ]);

        return $categories;
    }

    private function createProducts(Business $business, array $categories): void
    {
        $branches = $business->branches;

        // Electronics products
        $electronicsProducts = [
            ['name' => 'iPhone 14 Pro', 'sku' => 'IPH14PRO', 'cost' => 750.00, 'price' => 999.99],
            ['name' => 'Samsung Galaxy S23', 'sku' => 'SGS23', 'cost' => 650.00, 'price' => 899.99],
            ['name' => 'MacBook Pro 14"', 'sku' => 'MBP14', 'cost' => 1500.00, 'price' => 1999.99],
            ['name' => 'Dell XPS 15', 'sku' => 'DXPS15', 'cost' => 1200.00, 'price' => 1599.99],
            ['name' => 'AirPods Pro', 'sku' => 'APPRO', 'cost' => 150.00, 'price' => 249.99],
            ['name' => 'USB-C Cable', 'sku' => 'USBC', 'cost' => 5.00, 'price' => 14.99],
            ['name' => 'Wireless Mouse', 'sku' => 'WMOUSE', 'cost' => 15.00, 'price' => 29.99],
            ['name' => 'Bluetooth Keyboard', 'sku' => 'BTKBD', 'cost' => 35.00, 'price' => 59.99],
        ];

        foreach ($electronicsProducts as $productData) {
            $product = Product::create([
                'business_id' => $business->id,
                'category_id' => $categories['electronics']->id,
                'name' => $productData['name'],
                'sku' => $productData['sku'].'-'.Str::random(4),
                'barcode' => fake()->unique()->ean13(),
                'description' => fake()->sentence(),
                'base_cost_price' => $productData['cost'],
                'base_selling_price' => $productData['price'],
                'is_taxable' => true,
                'default_tax_rate' => 15,
                'unit_of_measure' => 'piece',
                'stock_tracking' => 'simple',
                'low_stock_threshold' => 5,
                'is_active' => true,
            ]);

            $this->addProductToBranches($product, $branches);
        }

        // Groceries products
        $groceryProducts = [
            ['name' => 'Milk 1L', 'sku' => 'MLK1L', 'cost' => 1.50, 'price' => 2.99, 'perishable' => true],
            ['name' => 'Bread Loaf', 'sku' => 'BREAD', 'cost' => 0.80, 'price' => 1.99, 'perishable' => true],
            ['name' => 'Eggs (12 pack)', 'sku' => 'EGG12', 'cost' => 2.50, 'price' => 4.99, 'perishable' => true],
            ['name' => 'Rice 5kg', 'sku' => 'RICE5', 'cost' => 8.00, 'price' => 14.99, 'perishable' => false],
            ['name' => 'Sugar 2kg', 'sku' => 'SUGAR2', 'cost' => 3.00, 'price' => 5.99, 'perishable' => false],
            ['name' => 'Cooking Oil 2L', 'sku' => 'OIL2L', 'cost' => 5.00, 'price' => 8.99, 'perishable' => false],
            ['name' => 'Pasta 500g', 'sku' => 'PASTA', 'cost' => 1.20, 'price' => 2.49, 'perishable' => false],
            ['name' => 'Canned Tomatoes', 'sku' => 'TOMCAN', 'cost' => 0.80, 'price' => 1.79, 'perishable' => false],
        ];

        foreach ($groceryProducts as $productData) {
            $product = Product::create([
                'business_id' => $business->id,
                'category_id' => $categories['groceries']->id,
                'name' => $productData['name'],
                'sku' => $productData['sku'].'-'.Str::random(4),
                'barcode' => fake()->unique()->ean13(),
                'description' => fake()->sentence(),
                'base_cost_price' => $productData['cost'],
                'base_selling_price' => $productData['price'],
                'is_taxable' => false,
                'default_tax_rate' => 0,
                'unit_of_measure' => 'piece',
                'stock_tracking' => 'simple',
                'low_stock_threshold' => 20,
                'is_active' => true,
            ]);

            $this->addProductToBranches($product, $branches, $productData['perishable']);
        }

        // Beverages
        $beverageProducts = [
            ['name' => 'Coca-Cola 330ml', 'sku' => 'COKE330', 'cost' => 0.50, 'price' => 1.29],
            ['name' => 'Pepsi 330ml', 'sku' => 'PEPSI330', 'cost' => 0.50, 'price' => 1.29],
            ['name' => 'Orange Juice 1L', 'sku' => 'OJ1L', 'cost' => 2.00, 'price' => 3.99],
            ['name' => 'Bottled Water 500ml', 'sku' => 'WATER500', 'cost' => 0.20, 'price' => 0.99],
            ['name' => 'Energy Drink', 'sku' => 'ENERGY', 'cost' => 1.50, 'price' => 2.99],
        ];

        foreach ($beverageProducts as $productData) {
            $product = Product::create([
                'business_id' => $business->id,
                'category_id' => $categories['beverages']->id,
                'name' => $productData['name'],
                'sku' => $productData['sku'].'-'.Str::random(4),
                'barcode' => fake()->unique()->ean13(),
                'description' => fake()->sentence(),
                'base_cost_price' => $productData['cost'],
                'base_selling_price' => $productData['price'],
                'is_taxable' => true,
                'default_tax_rate' => 7.5,
                'unit_of_measure' => 'piece',
                'stock_tracking' => 'simple',
                'low_stock_threshold' => 30,
                'is_active' => true,
            ]);

            $this->addProductToBranches($product, $branches, true);
        }

        // Household items
        $householdProducts = [
            ['name' => 'Dish Soap', 'sku' => 'DSOAP', 'cost' => 2.50, 'price' => 4.99],
            ['name' => 'Laundry Detergent 2L', 'sku' => 'LDET2', 'cost' => 8.00, 'price' => 14.99],
            ['name' => 'Paper Towels', 'sku' => 'PTOWEL', 'cost' => 3.00, 'price' => 5.99],
            ['name' => 'Toilet Paper 12-pack', 'sku' => 'TP12', 'cost' => 6.00, 'price' => 10.99],
            ['name' => 'All-Purpose Cleaner', 'sku' => 'APCLN', 'cost' => 3.50, 'price' => 6.99],
        ];

        foreach ($householdProducts as $productData) {
            $product = Product::create([
                'business_id' => $business->id,
                'category_id' => $categories['household']->id,
                'name' => $productData['name'],
                'sku' => $productData['sku'].'-'.Str::random(4),
                'barcode' => fake()->unique()->ean13(),
                'description' => fake()->sentence(),
                'base_cost_price' => $productData['cost'],
                'base_selling_price' => $productData['price'],
                'is_taxable' => true,
                'default_tax_rate' => 10,
                'unit_of_measure' => 'piece',
                'stock_tracking' => 'simple',
                'low_stock_threshold' => 15,
                'is_active' => true,
            ]);

            $this->addProductToBranches($product, $branches);
        }

        // Personal care
        $personalCareProducts = [
            ['name' => 'Shampoo 500ml', 'sku' => 'SHAMP', 'cost' => 4.00, 'price' => 7.99],
            ['name' => 'Body Wash', 'sku' => 'BWASH', 'cost' => 3.50, 'price' => 6.99],
            ['name' => 'Toothpaste', 'sku' => 'TPASTE', 'cost' => 2.00, 'price' => 3.99],
            ['name' => 'Deodorant', 'sku' => 'DEOD', 'cost' => 3.00, 'price' => 5.99],
        ];

        foreach ($personalCareProducts as $productData) {
            $product = Product::create([
                'business_id' => $business->id,
                'category_id' => $categories['personal_care']->id,
                'name' => $productData['name'],
                'sku' => $productData['sku'].'-'.Str::random(4),
                'barcode' => fake()->unique()->ean13(),
                'description' => fake()->sentence(),
                'base_cost_price' => $productData['cost'],
                'base_selling_price' => $productData['price'],
                'is_taxable' => true,
                'default_tax_rate' => 10,
                'unit_of_measure' => 'piece',
                'stock_tracking' => 'simple',
                'low_stock_threshold' => 10,
                'is_active' => true,
            ]);

            $this->addProductToBranches($product, $branches);
        }

        // Office supplies
        $officeProducts = [
            ['name' => 'A4 Paper Ream', 'sku' => 'A4REAM', 'cost' => 4.00, 'price' => 7.99],
            ['name' => 'Ballpoint Pen (pack of 10)', 'sku' => 'PEN10', 'cost' => 2.50, 'price' => 4.99],
            ['name' => 'Notebook A5', 'sku' => 'NB-A5', 'cost' => 1.50, 'price' => 2.99],
            ['name' => 'Stapler', 'sku' => 'STAPLER', 'cost' => 3.00, 'price' => 5.99],
        ];

        foreach ($officeProducts as $productData) {
            $product = Product::create([
                'business_id' => $business->id,
                'category_id' => $categories['office']->id,
                'name' => $productData['name'],
                'sku' => $productData['sku'].'-'.Str::random(4),
                'barcode' => fake()->unique()->ean13(),
                'description' => fake()->sentence(),
                'base_cost_price' => $productData['cost'],
                'base_selling_price' => $productData['price'],
                'is_taxable' => true,
                'default_tax_rate' => 10,
                'unit_of_measure' => 'piece',
                'stock_tracking' => 'simple',
                'low_stock_threshold' => 10,
                'is_active' => true,
            ]);

            $this->addProductToBranches($product, $branches);
        }
    }

    private function addProductToBranches(Product $product, $branches, bool $perishable = false): void
    {
        foreach ($branches as $branch) {
            $shelfQty = fake()->numberBetween(10, 100);
            $storeQty = fake()->numberBetween(50, 300);

            BranchProduct::create([
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'shelf_quantity' => $shelfQty,
                'store_quantity' => $storeQty,
                'stock_quantity' => $shelfQty + $storeQty,
                'reorder_point' => $product->low_stock_threshold,
                'reorder_quantity' => fake()->numberBetween(50, 200),
                'selling_price' => $product->base_selling_price,
                'cost_price' => $product->base_cost_price,
                'is_available' => true,
            ]);
        }
    }
}
