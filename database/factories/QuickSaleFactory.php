<?php

namespace Database\Factories;

use App\Models\QuickSale;
use App\Models\Business;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuickSaleFactory extends Factory
{
    protected $model = QuickSale::class;

    public function definition(): array
    {
        $originalPrice = fake()->randomFloat(2, 50, 500);
        $discountPercentage = fake()->randomFloat(2, 20, 50);
        $discountedPrice = $originalPrice * (1 - $discountPercentage / 100);
        $status = fake()->randomElement(['pending', 'approved', 'rejected', 'ended']);
        
        return [
            'business_id' => Business::factory(),
            'branch_id' => Branch::factory(),
            'product_id' => Product::factory(),
            'batch_id' => ProductBatch::factory(),
            'original_price' => $originalPrice,
            'discounted_price' => $discountedPrice,
            'discount_percentage' => $discountPercentage,
            'quantity_available' => fake()->numberBetween(10, 100),
            'quantity_sold' => $status !== 'pending' ? fake()->numberBetween(0, 50) : 0,
            'reason' => fake()->sentence(),
            'status' => $status,
            'requested_by' => User::factory(),
            'approved_by' => in_array($status, ['approved', 'ended']) ? User::factory() : null,
            'approved_at' => in_array($status, ['approved', 'ended']) ? fake()->dateTimeBetween('-30 days', '-1 day') : null,
            'starts_at' => in_array($status, ['approved', 'ended']) ? fake()->dateTimeBetween('-30 days', '-1 day') : null,
            'ends_at' => fake()->dateTimeBetween('now', '+30 days'),
            'notes' => fake()->optional(0.4)->sentence(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
            'starts_at' => null,
            'quantity_sold' => 0,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'approved_by' => User::factory(),
            'approved_at' => fake()->dateTimeBetween('-30 days', '-1 day'),
            'starts_at' => fake()->dateTimeBetween('-30 days', '-1 day'),
            'quantity_sold' => fake()->numberBetween(0, 50),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'approved_by' => null,
            'approved_at' => null,
            'starts_at' => null,
            'quantity_sold' => 0,
        ]);
    }
}
