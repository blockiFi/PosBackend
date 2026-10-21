<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Works out whether a request is a stock or product change made by a
 * manager or storekeeper, and whether it carries that person's PIN.
 */
class StaffStockChange
{
    /** Route prefixes (after api/) whose writes count as stock changes. */
    public const PREFIXES = [
        'products',
        'branch-products',
        'inventory',
        'batches',
        'stock-writeoffs',
        'stock-transfer-requests',
        'shelf-store-move-requests',
        'purchase-orders',
        'goods-received-notes',
    ];

    /** Staff roles whose changes are confirmed with a PIN and recorded. */
    public const ROLES = ['Manager', 'Storekeeper'];

    public static function relativePath(Request $request): string
    {
        return preg_replace('#^api/#', '', trim($request->path(), '/'));
    }

    public static function businessId(Request $request): ?int
    {
        $id = app()->bound('current_business_id') ? app('current_business_id') : $request->header('X-Business-Id');

        return $id ? (int) $id : null;
    }

    public static function isStockWrite(Request $request): bool
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        $path = self::relativePath($request);

        return collect(self::PREFIXES)->contains(
            fn (string $prefix) => $path === $prefix || str_starts_with($path, $prefix.'/')
        );
    }

    /**
     * The staff role ('Manager' or 'Storekeeper') of the user making the
     * request, or null for the business owner and everyone else.
     */
    public static function staffRole(Request $request): ?string
    {
        $user = $request->user();
        $businessId = self::businessId($request);
        if (! $user instanceof User || ! $businessId) {
            return null;
        }

        if ((int) Business::whereKey($businessId)->value('owner_id') === (int) $user->id) {
            return null;
        }

        foreach (self::ROLES as $role) {
            if ($user->hasRole($role)) {
                return $role;
            }
        }

        return null;
    }

    public static function sentPin(Request $request): string
    {
        return trim((string) $request->header('X-Staff-Pin', ''));
    }

    public static function pinMatches(Request $request): bool
    {
        $pin = self::sentPin($request);
        $own = (string) ($request->user()?->pin_code ?? '');

        return $pin !== '' && $own !== '' && hash_equals($own, $pin);
    }
}
