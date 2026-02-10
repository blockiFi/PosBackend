<?php

namespace Database\Factories;

use App\Models\ProductBatch;
use App\Models\Branch;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductBatchFactory extends Factory
{
    protected $model = ProductBatch::class;

    public function definition(): array
    {
        $initialQty = fake()->numberBetween(50, 500);
        $soldQty = fake()->numberBetween(0, $initialQty * 0.7);
        $currentQty = $initialQty - $soldQty;
        $manufacturingDate = fake()->dateTimeBetween('-6 months', '-1 month');
        $expiryDate = fake()->dateTimeBetween('+1 month', '+2 years');

        return [
            'branch_id' => Branch::factory(),
            'product_id' => Product::factory(),
            'batch_number' => 'BATCH-' . now()->format('Ymd') . '-' . fake()->unique()->numberBetween(1, 9999),
            'quantity' => $currentQty,
            'initial_quantity' => $initialQty,
            'unit_cost' => fake()->randomFloat(2, 5, 100),
            'manufacturing_date' => $manufacturingDate,
            'expiry_date' => $expiryDate,
            'supplier_name' => fake()->optional(0.7)->company(),
            'purchase_order_number' => fake()->optional(0.5)->bothify('PO-####??'),
            'notes' => fake()->optional(0.3)->sentence(),
            'status' => 'active',
        ];
    }

    public function nearExpiry(): static
    {
        return $this->state(fn (array $attributes) => [
            'expiry_date' => fake()->dateTimeBetween('now', '+30 days'),
            'status' => 'near_expiry',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expiry_date' => fake()->dateTimeBetween('-30 days', '-1 day'),
            'status' => 'expired',
            'quantity' => fake()->numberBetween(1, 20), // Small remaining quantity
        ]);
    }

    public function soldOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => 0,
            'status' => 'sold_out',
        ]);
    }
}
