<?php

namespace App\Support;

use App\Models\Business;

final class BusinessSettings
{
    public const DEPOSIT_STOCK_MODES = ['reserve_on_create', 'deduct_on_complete'];

    /**
     * Deposits leave stock on the shelf until they are completed unless the business
     * explicitly chose reserve_on_create. Deposits stamp the mode they were opened
     * under in sale metadata, and that stamp always wins over this default.
     */
    public const DEFAULT_DEPOSIT_STOCK_MODE = 'deduct_on_complete';

    public static function allowDecimalQuantities(Business|int|null $business): bool
    {
        if ($business === null) {
            return false;
        }

        return BusinessQuantityPolicy::allowsDecimals($business);
    }

    /**
     * The business's deposit stock mode, falling back to the default when unset or invalid.
     */
    public static function depositStockMode(?Business $business): string
    {
        $settings = $business !== null && is_array($business->settings) ? $business->settings : [];

        return self::normalizeDepositStockMode($settings['deposit_stock_mode'] ?? null);
    }

    public static function normalizeDepositStockMode(mixed $mode): string
    {
        if (is_string($mode) && in_array($mode, self::DEPOSIT_STOCK_MODES, true)) {
            return $mode;
        }

        return self::DEFAULT_DEPOSIT_STOCK_MODE;
    }

    /**
     * @return array{allow_decimal_quantities: bool, deposit_stock_mode: string}
     */
    public static function syncPayload(?Business $business): array
    {
        $settings = $business !== null && is_array($business->settings) ? $business->settings : [];

        return [
            'allow_decimal_quantities' => (bool) ($settings['allow_decimal_quantities'] ?? false),
            'deposit_stock_mode' => self::depositStockMode($business),
        ];
    }
}
