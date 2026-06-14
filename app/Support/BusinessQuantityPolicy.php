<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Validation\ValidationException;

final class BusinessQuantityPolicy
{
    public static function allowsDecimals(Business|int $business): bool
    {
        $model = $business instanceof Business
            ? $business
            : Business::find($business);

        if (! $model) {
            return false;
        }

        $settings = is_array($model->settings) ? $model->settings : [];

        return (bool) ($settings['allow_decimal_quantities'] ?? false);
    }

    public static function minQuantity(Business|int $business): float
    {
        return self::allowsDecimals($business) ? 0.01 : 1.0;
    }

    public static function minStockQuantity(Business|int $business): float
    {
        return self::allowsDecimals($business) ? 0.001 : 1.0;
    }

    /**
     * @return array<int, string>
     */
    public static function saleQuantityRules(Business|int $business): array
    {
        if (self::allowsDecimals($business)) {
            return ['required', 'numeric', 'min:0.01'];
        }

        return ['required', 'integer', 'min:1'];
    }

    /**
     * @return array<int, string>
     */
    public static function stockQuantityRules(Business|int $business): array
    {
        if (self::allowsDecimals($business)) {
            return ['required', 'numeric', 'min:0.001'];
        }

        return ['required', 'integer', 'min:1'];
    }

    public static function assertAllowed(Business|int $business, float $quantity, string $field = 'quantity'): void
    {
        if (self::allowsDecimals($business)) {
            return;
        }

        $normalized = Quantity::normalize($quantity);
        if (abs($normalized - round($normalized)) > Quantity::EPSILON) {
            throw ValidationException::withMessages([
                $field => ['Decimal quantities are not enabled for this business'],
            ]);
        }
    }

    public static function normalizeForBusiness(Business|int $business, float $quantity): float
    {
        $normalized = Quantity::normalize($quantity);
        self::assertAllowed($business, $normalized);

        return $normalized;
    }

    /**
     * JSON-safe quantity for sync/bootstrap payloads (never decimal strings).
     *
     * @return int|float
     */
    public static function serializeQuantity(Business|int $business, mixed $quantity): int|float
    {
        $value = Quantity::normalize((float) ($quantity ?? 0));

        if (! self::allowsDecimals($business)) {
            return (int) round($value);
        }

        return $value;
    }
}
