<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Business;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $costPrice = fake()->randomFloat(2, 5, 100);
        $sellingPrice = $costPrice * fake()->randomFloat(2, 1.2, 2.5);
        
        return [
            'business_id' => Business::factory(),
            'category_id' => ProductCategory::factory(),
            'name' => fake()->words(3, true),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####??')),
            'barcode' => fake()->unique()->ean13(),
            'description' => fake()->sentence(),
            'image' => null,
            'base_cost_price' => $costPrice,
            'base_selling_price' => $sellingPrice,
            'is_taxable' => fake()->boolean(80),
            'default_tax_rate' => fake()->randomElement([0, 5, 7.5, 10]),
            'unit_of_measure' => fake()->randomElement(['piece', 'kg', 'liter', 'box']),
            'weight' => fake()->randomFloat(3, 0.1, 10),
            'weight_unit' => 'kg',
            'stock_tracking' => 'simple',
            'low_stock_threshold' => fake()->numberBetween(5, 20),
            'is_active' => true,
            'is_available_online' => fake()->boolean(70),
            'meta_data' => [],
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    /**
     * Indicate that the product is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the product is not taxable.
     */
    public function nonTaxable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_taxable' => false,
            'default_tax_rate' => 0,
        ]);
    }

    /**
     * Indicate that stock tracking is disabled.
     */
    public function noStockTracking(): static
    {
        return $this->state(fn (array $attributes) => [
            'stock_tracking' => 'none',
        ]);
    }
}
