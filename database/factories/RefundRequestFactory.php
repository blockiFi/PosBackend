<?php

namespace Database\Factories;

use App\Models\RefundRequest;
use App\Models\Sale;
use App\Models\Business;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RefundRequestFactory extends Factory
{
    protected $model = RefundRequest::class;

    public function definition(): array
    {
        $status = fake()->randomElement(['pending', 'approved', 'rejected']);
        
        return [
            'sale_id' => Sale::factory(),
            'business_id' => Business::factory(),
            'branch_id' => Branch::factory(),
            'requested_by' => User::factory(),
            'reviewed_by' => $status !== 'pending' ? User::factory() : null,
            'amount' => fake()->randomFloat(2, 10, 1000),
            'reason' => fake()->sentence(),
            'rejection_reason' => $status === 'rejected' ? fake()->sentence() : null,
            'status' => $status,
            'reviewed_at' => $status !== 'pending' ? fake()->dateTimeBetween('-7 days', 'now') : null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'reviewed_by' => User::factory(),
            'reviewed_at' => fake()->dateTimeBetween('-7 days', 'now'),
            'rejection_reason' => null,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'reviewed_by' => User::factory(),
            'reviewed_at' => fake()->dateTimeBetween('-7 days', 'now'),
            'rejection_reason' => fake()->sentence(),
        ]);
    }
}
