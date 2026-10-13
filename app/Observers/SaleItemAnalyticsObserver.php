<?php

namespace App\Observers;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\AnalyticsRollupService;

class SaleItemAnalyticsObserver
{
    public function __construct(
        protected AnalyticsRollupService $rollupService
    ) {}

    public function saved(SaleItem $saleItem): void
    {
        $this->rollupForSaleId($saleItem->sale_id);
    }

    public function deleted(SaleItem $saleItem): void
    {
        $this->rollupForSaleId($saleItem->sale_id);
    }

    protected function rollupForSaleId(?int $saleId): void
    {
        if (! config('analytics.use_rollups') || ! $saleId) {
            return;
        }

        $sale = Sale::withTrashed()->find($saleId);
        if (! $sale) {
            return;
        }

        if ($sale->sale_date === null) {
            return;
        }

        $businessId = (int) $sale->business_id;
        $branchId = (int) $sale->branch_id;

        $this->rollupService->rebuildDay($businessId, $branchId, $this->rollupService->rollupDayFor($branchId, $sale->sale_date));
    }
}
