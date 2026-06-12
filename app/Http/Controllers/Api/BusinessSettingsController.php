<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BusinessSettingsController extends Controller
{
    private const DEPOSIT_STOCK_MODES = ['reserve_on_create', 'deduct_on_complete'];

    private const DEFAULT_DEPOSIT_STOCK_MODE = 'reserve_on_create';

    public function show(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json(['message' => 'Business context is required'], 400);
        }

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId((int) $businessId);

        $settings = is_array($business->settings) ? $business->settings : [];
        $currency = $business->currency ?? 'NGN';
        $symbol = $settings['currency_symbol'] ?? $this->defaultSymbol($currency);
        $depositStockMode = $this->normalizeDepositStockMode($settings['deposit_stock_mode'] ?? null);
        $allowDecimalQuantities = (bool) ($settings['allow_decimal_quantities'] ?? false);

        return response()->json([
            'data' => [
                'currency' => $currency,
                'currency_symbol' => $symbol,
                'deposit_stock_mode' => $depositStockMode,
                'allow_decimal_quantities' => $allowDecimalQuantities,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json(['message' => 'Business context is required'], 400);
        }

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId((int) $businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage-settings')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'currency' => ['sometimes', 'required', 'string', 'size:3'],
            'currency_symbol' => ['sometimes', 'required', 'string', 'max:10'],
            'deposit_stock_mode' => ['sometimes', 'required', 'string', 'in:'.implode(',', self::DEPOSIT_STOCK_MODES)],
            'allow_decimal_quantities' => ['sometimes', 'required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if (array_key_exists('currency', $data)) {
            $business->currency = strtoupper($data['currency']);
        }

        $settings = is_array($business->settings) ? $business->settings : [];
        if (array_key_exists('currency_symbol', $data)) {
            $settings['currency_symbol'] = $data['currency_symbol'];
        } elseif (! array_key_exists('currency_symbol', $settings)) {
            $settings['currency_symbol'] = $this->defaultSymbol($business->currency ?? 'NGN');
        }

        if (array_key_exists('deposit_stock_mode', $data)) {
            $settings['deposit_stock_mode'] = $data['deposit_stock_mode'];
        }

        if (array_key_exists('allow_decimal_quantities', $data)) {
            $settings['allow_decimal_quantities'] = (bool) $data['allow_decimal_quantities'];
        }

        $business->settings = $settings;
        $business->save();

        return response()->json([
            'message' => 'Settings updated',
            'data' => [
                'currency' => $business->currency ?? 'NGN',
                'currency_symbol' => $settings['currency_symbol'] ?? $this->defaultSymbol($business->currency ?? 'NGN'),
                'deposit_stock_mode' => $this->normalizeDepositStockMode($settings['deposit_stock_mode'] ?? null),
                'allow_decimal_quantities' => (bool) ($settings['allow_decimal_quantities'] ?? false),
            ],
        ]);
    }

    private function normalizeDepositStockMode(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::DEPOSIT_STOCK_MODES, true)) {
            return $value;
        }

        return self::DEFAULT_DEPOSIT_STOCK_MODE;
    }

    private function defaultSymbol(string $currency): string
    {
        $c = strtoupper($currency);
        return match ($c) {
            'NGN' => '₦',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => $c,
        };
    }
}

