<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DeviceRegistration>
 */
class DeviceRegistrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => 'DEV-' . Str::random(10),
            'business_id' => Business::factory(),
            'branch_id' => Branch::factory(),
            'user_id' => User::factory(),
            'device_name' => fake()->words(3, true) . ' Terminal',
            'device_type' => fake()->randomElement(['web', 'desktop', 'mobile', 'tablet']),
            'os' => fake()->randomElement(['Windows 11', 'macOS', 'iOS', 'Android', 'Linux']),
            'app_version' => fake()->semver(),
            'ip_address' => fake()->ipv4(),
            'status' => 'active',
            'last_seen_at' => now(),
            'last_sync_at' => now()->subHour(),
            'total_syncs' => fake()->numberBetween(0, 100),
            'capabilities' => [
                'offline_mode' => true,
                'auto_sync' => fake()->boolean(),
                'max_offline_days' => fake()->numberBetween(7, 30)
            ],
            'metadata' => [
                'screen_resolution' => fake()->randomElement(['1920x1080', '1366x768', '2560x1440']),
                'device_model' => fake()->word()
            ]
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'blocked',
        ]);
    }
}
