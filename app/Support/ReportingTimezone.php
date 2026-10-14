<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Business;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Report day boundaries. Sales are stored with `sale_date` in UTC (the app timezone);
 * reports bucket them by the local calendar day of the branch (or business) so a sale
 * at 00:30 in Lagos counts on that day, not the previous UTC day.
 *
 * Range filters are computed here in PHP (local day start/end converted to UTC), so the
 * SQL stays portable across mysql, pgsql and sqlite.
 */
final class ReportingTimezone
{
    public const DEFAULT = 'Africa/Lagos';

    public static function forBusiness(int|string|null $businessId): string
    {
        $timezone = $businessId ? Business::query()->whereKey($businessId)->value('time_zone') : null;

        return self::valid($timezone) ?? self::DEFAULT;
    }

    /**
     * The branch's own timezone, else its business's, else the default.
     */
    public static function forBranch(int|string|null $branchId): string
    {
        $branch = $branchId ? Branch::query()->whereKey($branchId)->first(['id', 'business_id', 'time_zone']) : null;
        if (! $branch) {
            return self::DEFAULT;
        }

        return self::valid($branch->time_zone) ?? self::forBusiness($branch->business_id);
    }

    public static function valid(mixed $timezone): ?string
    {
        if (! is_string($timezone) || $timezone === '') {
            return null;
        }

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : null;
    }

    /**
     * The local calendar day (Y-m-d) a stored UTC timestamp falls on.
     */
    public static function localDay(CarbonInterface|string $utcDateTime, string $timezone): string
    {
        $moment = $utcDateTime instanceof CarbonInterface
            ? $utcDateTime->copy()
            : Carbon::parse($utcDateTime, 'UTC');

        return $moment->setTimezone($timezone)->format('Y-m-d');
    }

    /**
     * UTC bounds (as DB-ready 'Y-m-d H:i:s' strings) of one local calendar day.
     *
     * @return array{0: string, 1: string}
     */
    public static function utcBoundsForLocalDay(string $ymd, string $timezone): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $ymd, $timezone)->startOfDay();

        return self::utcBounds($start, $start->copy()->endOfDay());
    }

    /**
     * Convert a local [start, end] range into UTC strings for a BETWEEN filter on a UTC column.
     *
     * @return array{0: string, 1: string}
     */
    public static function utcBounds(CarbonInterface $start, CarbonInterface $end): array
    {
        return [
            $start->copy()->utc()->format('Y-m-d H:i:s'),
            $end->copy()->utc()->format('Y-m-d H:i:s'),
        ];
    }
}
