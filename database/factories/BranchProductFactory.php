<?php

namespace Database\Factories;

use App\Models\BranchProduct;
use App\Models\Branch;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BranchProduct>
 */
class BranchProductFactory extends Factory
{
    protected $model = BranchProduct::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $shelfQty = fake()->numberBetween(0, 50);
        $storeQty = fake()->numberBetween(0, 100);
        
        return [
            'branch_id' => Branch::factory(),
            'product_id' => Product::factory(),
            'shelf_quantity' => $shelfQty,
            'store_quantity' => $storeQty,
            'stock_quantity' => $shelfQty + $storeQty,
            'reorder_level' => fake()->numberBetween(5, 20),
            'reorder_quantity' => fake()->numberBetween(20, 50),
            'selling_price' => fake()->randomFloat(2, 10, 200),
            'cost_price' => fake()->randomFloat(2, 5, 150),
            'is_available' => true,
        ];
    }

    /**
     * Indicate low shelf stock with stock in store.
     */
    public function needsRestocking(): static
    {
        return $this->state(fn (array $attributes) => [
            'shelf_quantity' => fake()->numberBetween(0, 3),
            'store_quantity' => fake()->numberBetween(20, 100),
        ]);
    }

    /**
     * Indicate the product is out of stock.
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'shelf_quantity' => 0,
            'store_quantity' => 0,
            'stock_quantity' => 0,
        ]);
    }

    /**
     * Indicate the product is unavailable.
     */
    public function unavailable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_available' => false,
        ]);
    }
}
