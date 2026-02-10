<?php

namespace Database\Factories;

use App\Models\SaleItem;
use App\Models\Sale;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class SaleItemFactory extends Factory
{
    protected $model = SaleItem::class;

    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 10);
        $unitPrice = fake()->randomFloat(2, 5, 500);
        $subtotal = $quantity * $unitPrice;
        $discountPercentage = fake()->optional(0.3)->randomFloat(2, 0, 20);
        $discountAmount = $discountPercentage ? ($subtotal * ($discountPercentage / 100)) : 0;
        $afterDiscount = $subtotal - $discountAmount;
        $taxRate = fake()->randomElement([0, 5, 7.5, 10, 15]);
        $taxAmount = $afterDiscount * ($taxRate / 100);
        $total = $afterDiscount + $taxAmount;

        return [
            'sale_id' => Sale::factory(),
            'product_id' => Product::factory(),
            'product_name' => fake()->words(3, true),
            'product_sku' => strtoupper(fake()->bothify('SKU-####??')),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_amount' => $discountAmount,
            'discount_percentage' => $discountPercentage,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'subtotal' => $subtotal,
            'total' => $total,
            'metadata' => [],
        ];
    }

    public function noDiscount(): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_amount' => 0,
            'discount_percentage' => null,
        ]);
    }

    public function withDiscount(): static
    {
        return $this->state(function (array $attributes) {
            $discountPercentage = fake()->randomFloat(2, 10, 30);
            $discountAmount = $attributes['subtotal'] * ($discountPercentage / 100);
            $afterDiscount = $attributes['subtotal'] - $discountAmount;
            $taxAmount = $afterDiscount * (($attributes['tax_rate'] ?? 0) / 100);
            
            return [
                'discount_percentage' => $discountPercentage,
                'discount_amount' => $discountAmount,
                'total' => $afterDiscount + $taxAmount,
            ];
        });
    }
}
