<?php

namespace App\Observers;

use App\Models\ProductBatch;
use App\Models\QuickSale;

class ProductBatchObserver
{
    /**
     * Handle the ProductBatch "created" event.
     */
    public function created(ProductBatch $productBatch): void
    {
        //
    }

    /**
     * Handle the ProductBatch "updated" event.
     * When batch is depleted (current_quantity 0 or status depleted), end any active quick sales for this batch.
     */
    public function updated(ProductBatch $productBatch): void
    {
        if (($productBatch->current_quantity <= 0 || $productBatch->status === 'depleted') && $productBatch->wasChanged(['current_quantity', 'status'])) {
            QuickSale::endActiveForBatch($productBatch->id, null);
        }
    }

    /**
     * Handle the ProductBatch "deleted" event.
     */
    public function deleted(ProductBatch $productBatch): void
    {
        //
    }

    /**
     * Handle the ProductBatch "restored" event.
     */
    public function restored(ProductBatch $productBatch): void
    {
        //
    }

    /**
     * Handle the ProductBatch "force deleted" event.
     */
    public function forceDeleted(ProductBatch $productBatch): void
    {
        //
    }
}
