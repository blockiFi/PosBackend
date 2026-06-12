<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Support\Str;

class InventoryBatchService
{
    /**
     * Allocate stock-out across batches using FEFO and create batch_allocation child transactions.
     * Deducts from multiple batches until quantity is satisfied (e.g. 50 from batches of 20,20,20 → 20+20+10).
     *
     * @param  array<string, mixed>  $context  Optional: reference_number, notes, meta_data for child transactions
     *
     * @throws \RuntimeException When not fully allocated and product has batches at branch (insufficient batch quantity)
     */
    public function allocateStockOut(
        int $productId,
        int $branchId,
        float $quantity,
        InventoryTransaction $parentTransaction,
        array $context = [],
        bool $failOnInsufficientBatches = true
    ): void {
        $quantity = \App\Support\Quantity::normalize($quantity);

        if (! \App\Support\Quantity::isPositive($quantity)) {
            return;
        }

        $product = Product::find($productId);
        if ($product && $product->stock_tracking === 'none') {
            return;
        }

        $result = ProductBatch::findBatchesToAllocate($productId, $branchId, $quantity);

        foreach ($result['allocations'] as $allocation) {
            /** @var ProductBatch $batch */
            $batch = $allocation['batch'];
            $allocateQty = $allocation['quantity'];

            $quantityBefore = $batch->current_quantity;
            $batch->allocate($allocateQty);

            InventoryTransaction::create([
                'uuid' => (string) Str::uuid(),
                'business_id' => $parentTransaction->business_id,
                'branch_id' => $branchId,
                'product_id' => $productId,
                'user_id' => $parentTransaction->user_id,
                'batch_id' => $batch->id,
                'type' => 'batch_allocation',
                'quantity' => -$allocateQty,
                'shelf_quantity' => 0,
                'store_quantity' => 0,
                'quantity_before' => $quantityBefore,
                'shelf_quantity_before' => 0,
                'store_quantity_before' => 0,
                'quantity_after' => $batch->current_quantity,
                'shelf_quantity_after' => 0,
                'store_quantity_after' => 0,
                'unit_cost' => $batch->unit_cost,
                'total_cost' => $allocateQty * (float) ($batch->unit_cost ?? 0),
                'related_transaction_id' => $parentTransaction->id,
                'reference_number' => $context['reference_number'] ?? $parentTransaction->reference_number,
                'notes' => $context['notes'] ?? "FEFO allocation from batch {$batch->batch_number}",
                'meta_data' => array_merge($context['meta_data'] ?? [], [
                    'batch_number' => $batch->batch_number,
                    'lot_number' => $batch->lot_number,
                    'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
                ]),
            ]);
        }

        if (! $result['fully_allocated'] && $failOnInsufficientBatches) {
            $hasBatches = ProductBatch::where('product_id', $productId)->where('branch_id', $branchId)->exists();
            if ($hasBatches) {
                throw new \RuntimeException(
                    "Insufficient batch quantity. Requested: {$quantity}, could allocate: ".($quantity - $result['remaining'])
                );
            }
        }
    }

    /**
     * Add stock-in to a batch: create a new batch or add to an existing active batch. Sets transaction.batch_id.
     *
     * @param  array<string, mixed>  $batchAttributes  Optional: batch_number, lot_number, expiry_date, unit_cost, etc.
     */
    public function addStockIn(
        int $productId,
        int $branchId,
        int $businessId,
        float $quantity,
        InventoryTransaction $transaction,
        ?int $existingBatchId = null,
        array $batchAttributes = []
    ): ?ProductBatch {
        $quantity = \App\Support\Quantity::normalize($quantity);

        if (! \App\Support\Quantity::isPositive($quantity)) {
            return null;
        }

        $product = Product::find($productId);
        if ($product && $product->stock_tracking === 'none') {
            return null;
        }

        $batch = null;

        if ($existingBatchId) {
            $batch = ProductBatch::where('id', $existingBatchId)
                ->where('product_id', $productId)
                ->where('branch_id', $branchId)
                ->first();
            if ($batch) {
                $batch->increaseQuantity($quantity);
            }
        }

        if (! $batch) {
            $batch = ProductBatch::where('product_id', $productId)
                ->where('branch_id', $branchId)
                ->where('status', 'active')
                ->where('current_quantity', '>', 0)
                ->orderBy('expiry_date', 'asc')
                ->orderBy('created_at', 'asc')
                ->first();

            if ($batch) {
                $batch->increaseQuantity($quantity);
            } else {
                $batch = ProductBatch::create([
                    'business_id' => $businessId,
                    'branch_id' => $branchId,
                    'product_id' => $productId,
                    'batch_number' => $batchAttributes['batch_number'] ?? ProductBatch::generateBatchNumber(),
                    'lot_number' => $batchAttributes['lot_number'] ?? null,
                    'manufacturing_date' => $batchAttributes['manufacturing_date'] ?? null,
                    'expiry_date' => $batchAttributes['expiry_date'] ?? null,
                    'received_quantity' => $quantity,
                    'current_quantity' => $quantity,
                    'unit_cost' => $batchAttributes['unit_cost'] ?? null,
                    'supplier_name' => $batchAttributes['supplier_name'] ?? null,
                    'supplier_reference' => $batchAttributes['supplier_reference'] ?? null,
                    'inventory_transaction_id' => $transaction->id,
                    'status' => 'active',
                ]);
            }
        }

        $transaction->update(['batch_id' => $batch->id]);

        return $batch;
    }
}
