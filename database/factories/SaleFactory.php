<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\Business;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Models\SalesShift;
use Illuminate\Database\Eloquent\Factories\Factory;

class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 10, 5000);
        $taxRate = fake()->randomElement([0, 5, 7.5, 10, 15]);
        $taxAmount = $subtotal * ($taxRate / 100);
        $discountAmount = fake()->randomFloat(2, 0, $subtotal * 0.1);
        $totalAmount = $subtotal + $taxAmount - $discountAmount;
        
        return [
            'sale_number' => 'SALE-' . now()->format('Ymd') . '-' . fake()->unique()->numberBetween(1, 99999),
            'business_id' => Business::factory(),
            'branch_id' => Branch::factory(),
            'customer_id' => fake()->optional(0.6)->randomElement([null, Customer::factory()]),
            'user_id' => User::factory(),
            'shift_id' => SalesShift::factory(),
            'sale_date' => fake()->dateTimeBetween('-60 days', 'now'),
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
            'status' => 'completed',
            'payment_status' => 'paid',
            'paid_amount' => $totalAmount,
            'is_refunded' => false,
            'refunded_at' => null,
            'sale_type' => fake()->randomElement(['pos', 'online', 'wholesale']),
            'notes' => fake()->optional(0.2)->sentence(),
            'metadata' => [],
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'paid_amount' => 0,
        ]);
    }

    public function partiallyPaid(): static
    {
        return $this->state(function (array $attributes) {
            $paidAmount = $attributes['total_amount'] * fake()->randomFloat(2, 0.3, 0.8);
            return [
                'payment_status' => 'partial',
                'paid_amount' => $paidAmount,
            ];
        });
    }

    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_refunded' => true,
            'refunded_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'status' => 'voided',
        ]);
    }

    public function withCustomer(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_id' => Customer::factory(),
        ]);
    }
}
