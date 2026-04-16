<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DeviceGroup>
 */
class DeviceGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(asText: true);
        $code = strtoupper(preg_replace('/[^A-Z0-9]+/', '_', $name) ?? '');
        $code = trim($code, '_');
        if ($code === '') {
            $code = strtoupper($this->faker->bothify('GROUP_##??'));
        }

        return [
            'business_id' => \App\Models\Business::factory(),
            'branch_id' => null,
            'name' => $name,
            'code' => substr($code, 0, 50),
            'description' => $this->faker->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
