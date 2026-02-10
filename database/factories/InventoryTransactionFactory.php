<?php

namespace Database\Factories;

use App\Models\InventoryTransaction;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryTransactionFactory extends Factory
{
    protected $model = InventoryTransaction::class;

    public function definition(): array
    {
        $type = fake()->randomElement(['purchase', 'sale', 'adjustment', 'transfer_in', 'transfer_out', 'writeoff']);
        $quantity = fake()->numberBetween(1, 100);
        
        return [
            'branch_id' => Branch::factory(),
            'product_id' => Product::factory(),
            'batch_id' => ProductBatch::factory(),
            'type' => $type,
            'quantity' => $quantity,
            'unit_cost' => fake()->randomFloat(2, 5, 100),
            'reference_id' => null,
            'reference_type' => null,
            'notes' => fake()->optional(0.4)->sentence(),
            'created_by' => User::factory(),
        ];
    }

    public function purchase(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'purchase',
            'notes' => 'Stock purchase from supplier',
        ]);
    }

    public function sale(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'sale',
            'notes' => 'Stock sold to customer',
        ]);
    }

    public function adjustment(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'adjustment',
            'quantity' => fake()->numberBetween(-50, 50),
            'notes' => 'Stock count adjustment',
        ]);
    }

    public function transfer(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => fake()->randomElement(['transfer_in', 'transfer_out']),
            'notes' => 'Inter-branch stock transfer',
        ]);
    }

    public function writeoff(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'writeoff',
            'notes' => 'Stock write-off: ' . fake()->randomElement(['expired', 'damaged', 'lost']),
        ]);
    }
}
