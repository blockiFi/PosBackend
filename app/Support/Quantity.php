<?php

namespace App\Support;

/**
 * Normalize inventory/sale quantities to avoid float drift (3 decimal places).
 */
final class Quantity
{
    public const SCALE = 3;

    public const EPSILON = 0.0005;

    public static function normalize(float|int|string $quantity): float
    {
        return round((float) $quantity, self::SCALE);
    }

    public static function isZero(float $quantity): bool
    {
        return abs($quantity) < self::EPSILON;
    }

    public static function isPositive(float $quantity): bool
    {
        return $quantity > self::EPSILON;
    }

    public static function remainingIsZero(float $remaining): bool
    {
        return $remaining <= self::EPSILON;
    }
}
