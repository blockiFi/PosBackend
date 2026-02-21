<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->randomElement(['Cash', 'Credit Card', 'Debit Card', 'Mobile Money', 'Bank Transfer']),
            'type' => fake()->randomElement(['cash', 'card', 'mobile_money', 'bank_transfer', 'cheque', 'other']),
            'description' => fake()->optional(0.5)->sentence(),
            'account_details' => [],
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 10),
        ];
    }

    public function cash(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Cash',
            'type' => 'cash',
            'sort_order' => 1,
        ]);
    }

    public function card(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Credit/Debit Card',
            'type' => 'card',
            'sort_order' => 2,
        ]);
    }

    public function mobileMoney(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Mobile Money',
            'type' => 'mobile_money',
            'sort_order' => 3,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
