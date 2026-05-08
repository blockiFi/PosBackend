<?php

namespace App\Observers;

use App\Models\Sale;
use App\Services\AnalyticsRollupService;
use Carbon\Carbon;

class SaleAnalyticsObserver
{
    public function __construct(
        protected AnalyticsRollupService $rollupService
    ) {}

    public function saved(Sale $sale): void
    {
        if (! config('analytics.use_rollups')) {
            return;
        }

        if (! $sale->wasRecentlyCreated && ! $sale->wasChanged(['status', 'branch_id', 'sale_date', 'total_amount', 'discount_amount'])) {
            return;
        }

        $this->rebuildForSale($sale);

        if ($sale->wasChanged(['branch_id', 'sale_date'])) {
            $origBranch = $sale->getOriginal('branch_id');
            $origDate = $sale->getOriginal('sale_date');
            if ($origBranch !== null && $origDate !== null) {
                $date = Carbon::parse($origDate)->format('Y-m-d');
                $this->rollupService->rebuildDay((int) $sale->business_id, (int) $origBranch, $date);
            }
        }
    }

    public function deleted(Sale $sale): void
    {
        if (! config('analytics.use_rollups')) {
            return;
        }

        $this->rebuildForSale($sale, useOriginalSnapshot: true);
    }

    protected function rebuildForSale(Sale $sale, bool $useOriginalSnapshot = false): void
    {
        $businessId = (int) $sale->business_id;
        $branchId = (int) ($useOriginalSnapshot ? ($sale->getOriginal('branch_id') ?? $sale->branch_id) : $sale->branch_id);
        $dateSource = $useOriginalSnapshot ? ($sale->getOriginal('sale_date') ?? $sale->sale_date) : $sale->sale_date;
        $date = Carbon::parse($dateSource)->format('Y-m-d');

        $this->rollupService->rebuildDay($businessId, $branchId, $date);
    }
}
