<?php

namespace App\Observers;

use App\Models\Sale;
use App\Services\AnalyticsRollupService;

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

        if (! $sale->wasRecentlyCreated && ! $sale->wasChanged(['status', 'branch_id', 'total_amount', 'discount_amount', 'sale_date'])) {
            return;
        }

        $this->rebuildForSale($sale);

        if ($sale->wasChanged('branch_id')) {
            $origBranch = $sale->getOriginal('branch_id');
            if ($origBranch !== null) {
                $origSaleDate = $sale->getOriginal('sale_date') ?? $sale->sale_date;
                $this->rollupService->rebuildDay(
                    (int) $sale->business_id,
                    (int) $origBranch,
                    $this->rollupService->rollupDayFor((int) $origBranch, $origSaleDate)
                );
            }
        }

        if ($sale->wasChanged('sale_date')) {
            $origSaleDate = $sale->getOriginal('sale_date');
            if ($origSaleDate !== null) {
                $branchForCleanup = (int) ($sale->getOriginal('branch_id') ?? $sale->branch_id);
                $this->rollupService->rebuildDay(
                    (int) $sale->business_id,
                    $branchForCleanup,
                    $this->rollupService->rollupDayFor($branchForCleanup, $origSaleDate)
                );
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
        $saleDate = $useOriginalSnapshot ? ($sale->getOriginal('sale_date') ?? $sale->sale_date) : $sale->sale_date;
        if ($saleDate === null) {
            return;
        }

        $this->rollupService->rebuildDay($businessId, $branchId, $this->rollupService->rollupDayFor($branchId, $saleDate));
    }
}
