<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure older records get a default currency_symbol, without overwriting custom values.
        $businesses = DB::table('businesses')
            ->select('id', 'currency', 'settings')
            ->get();

        foreach ($businesses as $b) {
            $settings = [];
            if (is_string($b->settings) && $b->settings !== '') {
                $decoded = json_decode($b->settings, true);
                if (is_array($decoded)) $settings = $decoded;
            } elseif (is_array($b->settings)) {
                $settings = $b->settings;
            }

            if (array_key_exists('currency_symbol', $settings) && is_string($settings['currency_symbol']) && $settings['currency_symbol'] !== '') {
                continue;
            }

            $currency = is_string($b->currency) && $b->currency !== '' ? strtoupper($b->currency) : 'NGN';
            $settings['currency_symbol'] = match ($currency) {
                'NGN' => '₦',
                'USD' => '$',
                'EUR' => '€',
                'GBP' => '£',
                default => $currency,
            };

            DB::table('businesses')->where('id', $b->id)->update([
                'settings' => json_encode($settings),
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive: do not remove currency_symbol (could be user-defined).
    }
};

