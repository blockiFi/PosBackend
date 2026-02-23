<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class ProductCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Creates a hierarchical category structure example
     */
    public function run(): void
    {
        // Get first business or create one for demo
        $business = Business::first();

        if (! $business) {
            $this->command->warn('No business found. Please create a business first.');

            return;
        }

        $this->command->info("Creating categories for business: {$business->name}");

        // Root Categories
        $electronics = ProductCategory::create([
            'business_id' => $business->id,
            'name' => 'Electronics',
            'description' => 'Electronic devices and accessories',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $clothing = ProductCategory::create([
            'business_id' => $business->id,
            'name' => 'Clothing',
            'description' => 'Apparel and fashion items',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $food = ProductCategory::create([
            'business_id' => $business->id,
            'name' => 'Food & Beverages',
            'description' => 'Food items and drinks',
            'is_active' => true,
            'sort_order' => 3,
        ]);

        // Electronics > Subcategories (Level 1)
        $computers = ProductCategory::create([
            'business_id' => $business->id,
            'parent_id' => $electronics->id,
            'name' => 'Computers',
            'description' => 'Desktop and laptop computers',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $phones = ProductCategory::create([
            'business_id' => $business->id,
            'parent_id' => $electronics->id,
            'name' => 'Mobile Phones',
            'description' => 'Smartphones and accessories',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $audio = ProductCategory::create([
            'business_id' => $business->id,
            'parent_id' => $electronics->id,
            'name' => 'Audio Equipment',
            'description' => 'Speakers, headphones, and audio devices',
            'is_active' => true,
            'sort_order' => 3,
        ]);

        // Computers > Subcategories (Level 2)
        ProductCategory::create([
            'business_id' => $business->id,
            'parent_id' => $computers->id,
            'name' => 'Laptops',
            'description' => 'Portable computers',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductCategory::create([
            'business_id' => $business->id,
            'parent_id' => $computers->id,
            'name' => 'Desktops',
            'description' => 'Desktop computers',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $accessories = ProductCategory::create([
            'business_id' => $business->id,
            'parent_id' => $computers->id,
            'name' => 'Computer Accessories',
            'description' => 'Keyboards, mice, and peripherals',
            'is_active' => true,
            'sort_order' => 3,
        ]);

        // Computer Accessories > Subcategories (Level 3)
        ProductCategory::create([
            'business_id' => $business->id,
            'parent_id' => $accessories->id,
            'name' => 'Keyboards',
            'description' => 'Mechanical and membrane keyboards',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductCategory::create([
            'business_id' => $business->id,
            'parent_id' => $accessories->id,
            'name' => 'Mice',
            'description' => 'Computer mice and trackpads',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // Clothing > Subcategories (Level 1)
        $menClothing = ProductCategory::create([
            'business_id' => $business->id,
            'parent_id' => $clothing->id,
            'name' => "Men's Clothing",
            'description' => 'Clothing for men',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $womenClothing = ProductCategory::create([
            'business_id' => $business->id,
            'parent_id' => $clothing->id,
            'name' => "Women's Clothing",
            'description' => 'Clothing for women',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // Men's Clothing > Subcategories (Level 2)
        ProductCategory::create([
            'business_id' => $business->id,
            'parent_id' => $menClothing->id,
            'name' => 'Shirts',
            'description' => "Men's shirts",
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductCategory::create([
            'business_id' => $business->id,
            'parent_id' => $menClothing->id,
            'name' => 'Pants',
            'description' => "Men's pants and trousers",
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // Food > Subcategories (Level 1)
        ProductCategory::create([
            'business_id' => $business->id,
            'parent_id' => $food->id,
            'name' => 'Beverages',
            'description' => 'Drinks and beverages',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductCategory::create([
            'business_id' => $business->id,
            'parent_id' => $food->id,
            'name' => 'Snacks',
            'description' => 'Snack items',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->command->info('Product categories created successfully with hierarchical structure!');
        $this->command->info('Structure created:');
        $this->command->info('- Electronics (3 subcategories)');
        $this->command->info('  - Computers (3 subcategories)');
        $this->command->info('    - Computer Accessories (2 subcategories)');
        $this->command->info('- Clothing (2 subcategories)');
        $this->command->info('  - Men\'s Clothing (2 subcategories)');
        $this->command->info('- Food & Beverages (2 subcategories)');
    }
}
