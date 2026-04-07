<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BusinessSettingsController extends Controller
{
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

        return response()->json([
            'data' => [
                'currency' => $currency,
                'currency_symbol' => $symbol,
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

        $business->settings = $settings;
        $business->save();

        return response()->json([
            'message' => 'Settings updated',
            'data' => [
                'currency' => $business->currency ?? 'NGN',
                'currency_symbol' => $settings['currency_symbol'] ?? $this->defaultSymbol($business->currency ?? 'NGN'),
            ],
        ]);
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

