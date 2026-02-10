<?php

namespace Database\Factories;

use App\Models\SalesShift;
use App\Models\Business;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SalesShiftFactory extends Factory
{
    protected $model = SalesShift::class;

    public function definition(): array
    {
        $openingBalance = fake()->randomFloat(2, 500, 2000);
        $isOpen = fake()->boolean(30); // 30% chance shift is still open
        
        return [
            'shift_number' => 'SHIFT-' . now()->format('Ymd') . '-' . fake()->unique()->numberBetween(1, 999),
            'business_id' => Business::factory(),
            'branch_id' => Branch::factory(),
            'user_id' => User::factory(),
            'start_time' => fake()->dateTimeBetween('-30 days', 'now'),
            'end_time' => $isOpen ? null : fake()->dateTimeBetween('-30 days', 'now'),
            'opening_balance' => $openingBalance,
            'expected_cash' => $isOpen ? null : fake()->randomFloat(2, $openingBalance, $openingBalance + 10000),
            'actual_cash' => $isOpen ? null : fake()->randomFloat(2, $openingBalance, $openingBalance + 10000),
            'cash_sales' => $isOpen ? 0 : fake()->randomFloat(2, 0, 8000),
            'card_sales' => $isOpen ? 0 : fake()->randomFloat(2, 0, 5000),
            'other_sales' => $isOpen ? 0 : fake()->randomFloat(2, 0, 2000),
            'total_sales' => $isOpen ? 0 : fake()->randomFloat(2, 1000, 15000),
            'transactions_count' => $isOpen ? 0 : fake()->numberBetween(10, 200),
            'variance' => $isOpen ? null : fake()->randomFloat(2, -100, 100),
            'status' => $isOpen ? 'open' : fake()->randomElement(['closed', 'reconciled']),
            'opening_notes' => fake()->optional(0.3)->sentence(),
            'closing_notes' => $isOpen ? null : fake()->optional(0.5)->sentence(),
            'metadata' => [],
            'discrepancy_resolved' => false,
            'discrepancy_resolved_at' => null,
            'discrepancy_resolved_by' => null,
            'resolution_notes' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'end_time' => null,
            'expected_cash' => null,
            'actual_cash' => null,
            'cash_sales' => 0,
            'card_sales' => 0,
            'other_sales' => 0,
            'total_sales' => 0,
            'transactions_count' => 0,
            'variance' => null,
            'status' => 'open',
            'closing_notes' => null,
        ]);
    }

    public function closed(): static
    {
        $openingBalance = fake()->randomFloat(2, 500, 2000);
        $totalSales = fake()->randomFloat(2, 1000, 15000);
        $cashSales = fake()->randomFloat(2, 0, $totalSales * 0.6);
        $cardSales = fake()->randomFloat(2, 0, $totalSales - $cashSales);
        $otherSales = $totalSales - $cashSales - $cardSales;
        $expectedCash = $openingBalance + $cashSales;
        $variance = fake()->randomFloat(2, -50, 50);
        
        return $this->state(fn (array $attributes) => [
            'opening_balance' => $openingBalance,
            'end_time' => fake()->dateTimeBetween('-30 days', 'now'),
            'cash_sales' => $cashSales,
            'card_sales' => $cardSales,
            'other_sales' => $otherSales,
            'total_sales' => $totalSales,
            'transactions_count' => fake()->numberBetween(10, 200),
            'expected_cash' => $expectedCash,
            'actual_cash' => $expectedCash + $variance,
            'variance' => $variance,
            'status' => 'closed',
            'closing_notes' => fake()->optional(0.7)->sentence(),
        ]);
    }

    public function withDiscrepancy(): static
    {
        $openingBalance = fake()->randomFloat(2, 500, 2000);
        $totalSales = fake()->randomFloat(2, 1000, 15000);
        $cashSales = fake()->randomFloat(2, 0, $totalSales * 0.6);
        $expectedCash = $openingBalance + $cashSales;
        $variance = fake()->randomFloat(2, -200, -50); // Significant shortage
        
        return $this->state(fn (array $attributes) => [
            'opening_balance' => $openingBalance,
            'expected_cash' => $expectedCash,
            'actual_cash' => $expectedCash + $variance,
            'variance' => $variance,
            'status' => 'closed',
            'discrepancy_resolved' => false,
        ]);
    }
}
