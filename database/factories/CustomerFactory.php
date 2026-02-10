<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        $hasEmail = fake()->boolean(70); // 70% chance of having email
        $hasCreditLimit = fake()->boolean(30); // 30% chance of credit limit
        
        return [
            'business_id' => Business::factory(),
            'customer_code' => 'CUST-' . str_pad(fake()->unique()->numberBetween(1, 99999), 6, '0', STR_PAD_LEFT),
            'name' => fake()->name(),
            'email' => $hasEmail ? fake()->unique()->safeEmail() : null,
            'phone' => fake()->optional(0.8)->phoneNumber(),
            'address' => fake()->optional(0.6)->address(),
            'type' => fake()->randomElement(['walk-in', 'regular', 'vip']),
            'credit_limit' => $hasCreditLimit ? fake()->randomFloat(2, 1000, 50000) : 0,
            'outstanding_balance' => 0.00,
            'loyalty_points' => fake()->numberBetween(0, 500),
            'metadata' => [],
            'is_active' => true,
        ];
    }

    public function vip(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'vip',
            'credit_limit' => fake()->randomFloat(2, 10000, 100000),
            'loyalty_points' => fake()->numberBetween(500, 5000),
        ]);
    }

    public function wholesale(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'regular',
            'credit_limit' => fake()->randomFloat(2, 5000, 50000),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
