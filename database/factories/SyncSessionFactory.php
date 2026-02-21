<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\DeviceRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SyncSession>
 */
class SyncSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $started = fake()->dateTimeBetween('-1 week', 'now');
        $completed = fake()->dateTimeBetween($started, 'now');

        return [
            'session_id' => Str::uuid()->toString(),
            'device_id' => DeviceRegistration::factory(),
            'business_id' => Business::factory(),
            'user_id' => User::factory(),
            'direction' => fake()->randomElement(['pull', 'push', 'bidirectional']),
            'status' => 'completed',
            'started_at' => $started,
            'completed_at' => $completed,
            'records_pushed' => fake()->numberBetween(0, 100),
            'records_pulled' => fake()->numberBetween(0, 100),
            'conflicts_detected' => 0,
            'conflicts_resolved' => 0,
            'errors_count' => 0,
            'last_activity_at' => $completed,
            'summary' => [
                'sales' => ['created' => 5, 'updated' => 2],
                'customers' => ['created' => 3],
            ],
            'error_message' => null,
            'metadata' => [],
        ];
    }

    public function initiated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'initiated',
            'completed_at' => null,
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in_progress',
            'completed_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'errors_count' => fake()->numberBetween(1, 10),
            'error_message' => fake()->sentence(),
        ]);
    }

    public function withConflicts(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'partial',
            'conflicts_detected' => fake()->numberBetween(1, 10),
            'conflicts_resolved' => fake()->numberBetween(0, 5),
        ]);
    }
}
