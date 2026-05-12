<?php

namespace App\Observers;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\AnalyticsRollupService;
use Carbon\Carbon;

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

        $businessId = (int) $sale->business_id;
        $branchId = (int) $sale->branch_id;
        $date = Carbon::parse($sale->created_at)->format('Y-m-d');

        $this->rollupService->rebuildDay($businessId, $branchId, $date);
    }
}
