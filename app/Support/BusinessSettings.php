<?php

namespace App\Support;

use App\Models\Business;

final class BusinessSettings
{
    public static function allowDecimalQuantities(Business|int|null $business): bool
    {
        if ($business === null) {
            return false;
        }

        return BusinessQuantityPolicy::allowsDecimals($business);
    }

    /**
     * @return array{allow_decimal_quantities: bool, deposit_stock_mode: string|null}
     */
    public static function syncPayload(Business $business): array
    {
        $settings = is_array($business->settings) ? $business->settings : [];

        return [
            'allow_decimal_quantities' => (bool) ($settings['allow_decimal_quantities'] ?? false),
            'deposit_stock_mode' => $settings['deposit_stock_mode'] ?? null,
        ];
    }
}
