<?php

namespace Database\Factories;

use App\Models\StockTransferRequest;
use App\Models\Business;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockTransferRequestFactory extends Factory
{
    protected $model = StockTransferRequest::class;

    public function definition(): array
    {
        $status = fake()->randomElement(['pending', 'approved', 'rejected', 'in_transit', 'completed']);
        
        return [
            'business_id' => Business::factory(),
            'from_branch_id' => Branch::factory(),
            'to_branch_id' => Branch::factory(),
            'product_id' => Product::factory(),
            'batch_id' => ProductBatch::factory(),
            'quantity' => fake()->numberBetween(10, 200),
            'reason' => fake()->sentence(),
            'status' => $status,
            'requested_by' => User::factory(),
            'approved_by' => in_array($status, ['approved', 'in_transit', 'completed']) ? User::factory() : null,
            'confirmed_by' => $status === 'completed' ? User::factory() : null,
            'requested_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'approved_at' => in_array($status, ['approved', 'in_transit', 'completed']) ? fake()->dateTimeBetween('-20 days', 'now') : null,
            'confirmed_at' => $status === 'completed' ? fake()->dateTimeBetween('-10 days', 'now') : null,
            'notes' => fake()->optional(0.4)->sentence(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'approved_by' => null,
            'confirmed_by' => null,
            'approved_at' => null,
            'confirmed_at' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'approved_by' => User::factory(),
            'approved_at' => fake()->dateTimeBetween('-20 days', 'now'),
            'confirmed_by' => null,
            'confirmed_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'approved_by' => User::factory(),
            'confirmed_by' => User::factory(),
            'approved_at' => fake()->dateTimeBetween('-20 days', '-10 days'),
            'confirmed_at' => fake()->dateTimeBetween('-10 days', 'now'),
        ]);
    }
}
