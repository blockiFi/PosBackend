<?php

namespace App\Services;

use App\Models\GoodsReceivedNote;
use App\Models\GoodsReceivedNoteLine;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierProductPrice;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GoodsReceivingService
{
    public function __construct(
        private readonly InventoryBatchService $batchService,
    ) {}

    /**
     * Create a draft GRN. Uses client_uuid for idempotency when provided.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createDraft(array $attributes): GoodsReceivedNote
    {
        // Idempotency for offline sync (Phase 4)
        if (! empty($attributes['client_uuid']) && ! empty($attributes['business_id'])) {
            $existing = GoodsReceivedNote::where('business_id', (int) $attributes['business_id'])
                ->where('client_uuid', (string) $attributes['client_uuid'])
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $attributes['status'] = $attributes['status'] ?? 'draft';
        $attributes['grn_number'] = $attributes['grn_number'] ?? $this->generateGrnNumber();

        return GoodsReceivedNote::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addOrUpdateLine(GoodsReceivedNote $grn, array $attributes, ?GoodsReceivedNoteLine $line = null): GoodsReceivedNoteLine
    {
        if ($grn->status !== 'draft') {
            throw new \RuntimeException('Only draft GRNs can be edited.');
        }

        // If this GRN is tied to a Purchase Order, enforce that lines must come from that PO
        // and backfill purchase_order_line_id so posting can update PO received quantities/status.
        if (! empty($grn->purchase_order_id)) {
            $poLineId = $attributes['purchase_order_line_id'] ?? null;
            if (empty($poLineId)) {
                $poLineId = $this->resolvePurchaseOrderLineId($grn, $attributes);
                $attributes['purchase_order_line_id'] = $poLineId;
            } else {
                // Validate the provided po line id belongs to this GRN's PO.
                $ok = PurchaseOrderLine::where('id', (int) $poLineId)
                    ->where('purchase_order_id', (int) $grn->purchase_order_id)
                    ->exists();
                if (! $ok) {
                    throw new \RuntimeException('Line does not belong to the selected purchase order.');
                }
            }
        }

        $attributes['goods_received_note_id'] = $grn->id;

        if (isset($attributes['quantity_received'])) {
            $attributes['quantity_received'] = Quantity::normalize((float) $attributes['quantity_received']);
        }
        if (isset($attributes['quantity_accepted'])) {
            $attributes['quantity_accepted'] = Quantity::normalize((float) $attributes['quantity_accepted']);
        }
        if (isset($attributes['quantity_rejected'])) {
            $attributes['quantity_rejected'] = Quantity::normalize((float) $attributes['quantity_rejected']);
        }
        if (isset($attributes['quantity_ordered'])) {
            $attributes['quantity_ordered'] = Quantity::normalize((float) $attributes['quantity_ordered']);
        }

        return $line ? tap($line)->update($attributes) : GoodsReceivedNoteLine::create($attributes);
    }

    /**
     * Resolve purchase_order_line_id by matching product/branch_product on the PO.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function resolvePurchaseOrderLineId(GoodsReceivedNote $grn, array $attributes): int
    {
        $poId = (int) $grn->purchase_order_id;
        $productId = (int) ($attributes['product_id'] ?? 0);
        $branchProductId = (int) ($attributes['branch_product_id'] ?? 0);

        if ($poId <= 0 || $productId <= 0) {
            throw new \RuntimeException('Purchase order line could not be resolved.');
        }

        // Ensure PO exists under same business/branch (defense-in-depth).
        $po = PurchaseOrder::where('id', $poId)
            ->where('business_id', (int) $grn->business_id)
            ->where('branch_id', (int) $grn->branch_id)
            ->first();
        if (! $po) {
            throw new \RuntimeException('Purchase order not found for this GRN.');
        }

        $q = PurchaseOrderLine::query()
            ->where('purchase_order_id', $poId)
            ->where('product_id', $productId);

        if ($branchProductId > 0) {
            $q->where(function ($w) use ($branchProductId) {
                $w->whereNull('branch_product_id')->orWhere('branch_product_id', $branchProductId);
            });
        }

        /** @var PurchaseOrderLine|null $line */
        $line = $q->orderByDesc('id')->first();
        if (! $line) {
            throw new \RuntimeException('You cannot receive an item that is not on the purchase order.');
        }

        return (int) $line->id;
    }

    public function removeLine(GoodsReceivedNote $grn, GoodsReceivedNoteLine $line): void
    {
        if ($grn->status !== 'draft') {
            throw new \RuntimeException('Only draft GRNs can be edited.');
        }
        if ($line->goods_received_note_id !== $grn->id) {
            throw new \RuntimeException('Line does not belong to this GRN.');
        }

        $line->delete();
    }

    public function submit(GoodsReceivedNote $grn, int $userId): GoodsReceivedNote
    {
        if ($grn->status !== 'draft') {
            throw new \RuntimeException('Only draft GRNs can be submitted.');
        }
        if (! $grn->lines()->exists()) {
            throw new \RuntimeException('Add at least one line before submitting.');
        }

        $grn->update([
            'status' => 'pending_approval',
            'received_by' => $grn->received_by ?? $userId,
            'received_at' => $grn->received_at ?? now(),
        ]);

        return $grn->fresh();
    }

    public function reject(GoodsReceivedNote $grn, int $userId, string $reason): GoodsReceivedNote
    {
        if ($grn->status !== 'pending_approval') {
            throw new \RuntimeException('Only pending GRNs can be rejected.');
        }

        $grn->update([
            'status' => 'rejected',
            'rejected_by' => $userId,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $grn->fresh();
    }

    public function cancel(GoodsReceivedNote $grn, int $userId): GoodsReceivedNote
    {
        if (! in_array($grn->status, ['draft', 'rejected'], true)) {
            throw new \RuntimeException('Only draft or rejected GRNs can be cancelled.');
        }

        $grn->update([
            'status' => 'cancelled',
        ]);

        return $grn->fresh();
    }

    /**
     * Approve and auto-post stock (Phase 1 decision).
     */
    public function approveAndPost(GoodsReceivedNote $grn, int $userId): GoodsReceivedNote
    {
        if ($grn->status !== 'pending_approval') {
            throw new \RuntimeException('Only pending GRNs can be approved.');
        }

        return DB::transaction(function () use ($grn, $userId) {
            $grn->refresh();
            $lines = $grn->lines()->with(['branchProduct.product'])->lockForUpdate()->get();

            foreach ($lines as $line) {
                $this->postLine($grn, $line, $userId);
            }

            $grn->update([
                'status' => 'posted',
                'approved_by' => $userId,
                'approved_at' => now(),
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $grn->fresh();
        });
    }

    private function postLine(GoodsReceivedNote $grn, GoodsReceivedNoteLine $line, int $userId): void
    {
        if ($line->inventory_transaction_id) {
            return;
        }

        $qtyAccepted = Quantity::normalize((float) ($line->quantity_accepted ?? 0));
        if (! Quantity::isPositive($qtyAccepted)) {
            // Nothing to post.
            return;
        }

        $branchProduct = $line->branchProduct;
        $product = $branchProduct->product;
        if ($product instanceof Product && $product->stock_tracking === 'none') {
            // No stock tracking; still record transaction for audit.
        }

        $isShelf = $line->storage_location === 'shelf';
        $shelfDelta = $isShelf ? $qtyAccepted : 0.0;
        $storeDelta = $isShelf ? 0.0 : $qtyAccepted;

        $quantityBefore = (float) ($branchProduct->stock_quantity ?? 0);
        $shelfBefore = (float) ($branchProduct->shelf_quantity ?? 0);
        $storeBefore = (float) ($branchProduct->store_quantity ?? 0);

        $unitCost = $line->unit_cost !== null ? (float) $line->unit_cost : null;
        $totalCost = $unitCost !== null ? $unitCost * $qtyAccepted : null;

        $tx = InventoryTransaction::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $grn->business_id,
            'branch_id' => $grn->branch_id,
            'product_id' => $line->product_id,
            'user_id' => $userId,
            'type' => 'purchase',
            'quantity' => $qtyAccepted,
            'shelf_quantity' => $shelfDelta,
            'store_quantity' => $storeDelta,
            'quantity_before' => $quantityBefore,
            'shelf_quantity_before' => $shelfBefore,
            'store_quantity_before' => $storeBefore,
            'quantity_after' => Quantity::normalize($quantityBefore + $qtyAccepted),
            'shelf_quantity_after' => Quantity::normalize($shelfBefore + $shelfDelta),
            'store_quantity_after' => Quantity::normalize($storeBefore + $storeDelta),
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'reference_number' => $grn->grn_number,
            'notes' => $line->notes,
            'meta_data' => array_merge($line->meta_data ?? [], [
                'goods_received_note_id' => $grn->id,
                'goods_received_note_line_id' => $line->id,
            ]),
            'goods_received_note_line_id' => $line->id,
        ]);

        $batch = $this->batchService->addStockIn(
            productId: (int) $line->product_id,
            branchId: (int) $grn->branch_id,
            businessId: (int) $grn->business_id,
            quantity: $qtyAccepted,
            transaction: $tx,
            existingBatchId: null,
            batchAttributes: [
                'batch_number' => $line->batch_number,
                'lot_number' => $line->lot_number,
                'manufacturing_date' => $line->manufacturing_date,
                'expiry_date' => $line->expiry_date,
                'unit_cost' => $unitCost,
                'supplier_name' => $grn->supplier?->name,
                'supplier_reference' => $grn->supplier_invoice_number,
            ]
        );

        if ($batch) {
            $batch->update(['goods_received_note_line_id' => $line->id]);
        }

        // Update stock quantities
        if ($isShelf) {
            $branchProduct->updateShelfQuantity($qtyAccepted, 'add');
        } else {
            $branchProduct->updateStoreQuantity($qtyAccepted, 'add');
        }

        if ($unitCost !== null) {
            // Phase 3 costing: moving average
            $oldQty = max(0, $quantityBefore);
            $oldAvg = (float) ($branchProduct->avg_cost_price ?? $branchProduct->cost_price ?? 0);
            $newQty = $oldQty + $qtyAccepted;
            $newAvg = $newQty > 0 ? (($oldQty * $oldAvg) + ($qtyAccepted * $unitCost)) / $newQty : $unitCost;

            $branchProduct->update([
                'cost_price' => $unitCost, // still keep "latest cost" for convenience
                'last_received_cost' => $unitCost,
                'avg_cost_price' => $newAvg,
            ]);

            if ($grn->supplier_id) {
                $sp = SupplierProductPrice::firstOrNew([
                    'business_id' => $grn->business_id,
                    'supplier_id' => $grn->supplier_id,
                    'product_id' => $line->product_id,
                ]);
                $prevCount = (int) ($sp->receipt_count ?? 0);
                $prevAvg = (float) ($sp->avg_unit_cost ?? 0);
                $nextCount = $prevCount + 1;
                $nextAvg = $nextCount > 0 ? (($prevAvg * $prevCount) + $unitCost) / $nextCount : $unitCost;

                $sp->fill([
                    'currency' => $grn->currency,
                    'last_unit_cost' => $unitCost,
                    'last_received_at' => now(),
                    'avg_unit_cost' => $nextAvg,
                    'receipt_count' => $nextCount,
                ]);
                $sp->save();
            }
        }

        $line->update([
            'inventory_transaction_id' => $tx->id,
            'batch_id' => $batch?->id,
            'line_total' => $line->line_total ?? ($totalCost !== null ? $totalCost : null),
        ]);

        if ($line->purchase_order_line_id) {
            $poLine = PurchaseOrderLine::where('id', $line->purchase_order_line_id)->first();
            if ($poLine) {
                $poLine->increment('quantity_received', $qtyAccepted);

                $po = $poLine->purchaseOrder()->with('lines')->first();
                if ($po) {
                    $allFulfilled = true;
                    foreach ($po->lines as $l) {
                        $ordered = (float) ($l->quantity_ordered ?? 0);
                        $received = (float) ($l->quantity_received ?? 0);
                        if ($received + 0.0001 < $ordered) {
                            $allFulfilled = false;
                            break;
                        }
                    }
                    $po->update([
                        'status' => $allFulfilled ? 'received' : 'partially_received',
                    ]);
                }
            }
        }
    }

    private function generateGrnNumber(): string
    {
        // Simple unique generator. If you need strict sequence per day/business, replace with a counter table.
        $date = now()->format('Ymd');
        $rand = strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 6));

        return "GRN-{$date}-{$rand}";
    }
}
