<?php

namespace App\Console\Commands;

use App\Services\AnalyticsRollupService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AnalyticsRollupCommand extends Command
{
    protected $signature = 'analytics:rollup
                            {--from= : Start date (Y-m-d). Defaults to 7 days ago.}
                            {--to= : End date (Y-m-d). Defaults to today.}
                            {--business= : Optional business id filter}';

    protected $description = 'Rebuild analytics_daily_summaries rows for a date range (upsert).';

    public function handle(AnalyticsRollupService $rollupService): int
    {
        $fromInput = $this->option('from');
        $toInput = $this->option('to');

        $from = $fromInput
            ? Carbon::parse($fromInput)->startOfDay()
            : now()->subDays(7)->startOfDay();

        $to = $toInput
            ? Carbon::parse($toInput)->endOfDay()
            : now()->endOfDay();

        if ($from->gt($to)) {
            $this->error('--from must be on or before --to');

            return self::FAILURE;
        }

        $businessId = $this->option('business');
        $businessId = $businessId !== null && $businessId !== '' ? (int) $businessId : null;

        $this->info(sprintf(
            'Rolling up analytics from %s to %s%s…',
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            $businessId ? " (business {$businessId})" : ''
        ));

        $rows = $rollupService->rollupDateRange($from, $to, $businessId);

        $this->info("Done (affected rows / statements: {$rows}).");

        return self::SUCCESS;
    }
}
