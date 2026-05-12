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

        if (! $sale->wasRecentlyCreated && ! $sale->wasChanged(['status', 'branch_id', 'total_amount', 'discount_amount', 'created_at'])) {
            return;
        }

        $this->rebuildForSale($sale);

        if ($sale->wasChanged('branch_id')) {
            $origBranch = $sale->getOriginal('branch_id');
            if ($origBranch !== null) {
                $origCreated = $sale->getOriginal('created_at') ?? $sale->created_at;
                $this->rollupService->rebuildDay((int) $sale->business_id, (int) $origBranch, Carbon::parse($origCreated)->format('Y-m-d'));
            }
        }

        if ($sale->wasChanged('created_at')) {
            $origCreated = $sale->getOriginal('created_at');
            if ($origCreated !== null) {
                $branchForCleanup = (int) ($sale->getOriginal('branch_id') ?? $sale->branch_id);
                $this->rollupService->rebuildDay((int) $sale->business_id, $branchForCleanup, Carbon::parse($origCreated)->format('Y-m-d'));
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
        $createdAt = $useOriginalSnapshot ? ($sale->getOriginal('created_at') ?? $sale->created_at) : $sale->created_at;
        $date = Carbon::parse($createdAt)->format('Y-m-d');

        $this->rollupService->rebuildDay($businessId, $branchId, $date);
    }
}
