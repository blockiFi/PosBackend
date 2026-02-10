<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Sale;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            'payment_method_id' => PaymentMethod::factory(),
            'amount' => fake()->randomFloat(2, 10, 1000),
            'reference_number' => fake()->optional(0.4)->bothify('REF-########'),
            'payment_date' => fake()->dateTimeBetween('-60 days', 'now'),
            'status' => 'completed',
            'notes' => fake()->optional(0.2)->sentence(),
            'metadata' => [],
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'notes' => 'Payment failed: ' . fake()->sentence(),
        ]);
    }

    public function cash(): static
    {
        return $this->state(fn (array $attributes) => [
            'reference_number' => null,
        ]);
    }

    public function withReference(): static
    {
        return $this->state(fn (array $attributes) => [
            'reference_number' => 'TXN-' . fake()->unique()->numerify('##########'),
        ]);
    }
}
