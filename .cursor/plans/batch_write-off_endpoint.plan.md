# Batch write-off by ID

## Goal
Expose a **write-off batch** action that accepts a **batch ID** and a **reason**, and writes off the batch’s **remaining quantity** (`ProductBatch.current_quantity`), creating a stock write-off record and an inventory (damage) transaction, and updating branch product stock while keeping **shelf_quantity + store_quantity = stock_quantity**.

## Key files
- [app/Http/Controllers/StockWriteoffController.php](app/Http/Controllers/StockWriteoffController.php) – add `writeOffBatch`; follow existing `store()` for auth, validation, and transaction pattern.
- [app/Models/StockWriteoff.php](app/Models/StockWriteoff.php) – optional `batch_id` and `batch()` relationship for traceability.
- [app/Models/ProductBatch.php](app/Models/ProductBatch.php) – use `allocate($quantity)` to set `current_quantity` to 0 and status to `depleted`.
- [app/Models/BranchProduct.php](app/Models/BranchProduct.php) – use `updateStoreQuantity` / `updateShelfQuantity` so total stock stays consistent.
- [routes/api.php](routes/api.php) – new route **before** `stock-writeoffs/{id}` so `writeoff-batch` is not matched as `id`.

## Design

**Endpoint:** `POST /api/stock-writeoffs/writeoff-batch`  
**Body:** `current_business_id` (required), `batch_id` (required), `reason` (required, string, max 1000).  
**Permission:** Reuse existing **"write off stock"** (same as `store()`).  
**Scope:** Batch must belong to the given business; user must have branch access to the batch’s branch (reuse `HasBranchAccess`).

**Reducing branch stock (keep shelf + store = stock_quantity):**  
Do **not** use `decrement('stock_quantity')`. Deduct the write-off quantity from physical locations so that `stock_quantity` remains equal to `shelf_quantity + store_quantity`:

- Deduct from **store first**, then from **shelf** for the remainder:
  - `$fromStore = min($quantity, $branchProduct->store_quantity)`
  - `$fromShelf = $quantity - $fromStore`
  - `$branchProduct->updateStoreQuantity($fromStore, 'subtract')` (then refresh if needed before shelf)
  - `$branchProduct->updateShelfQuantity($fromShelf, 'subtract')`
- Each of these methods recalculates `stock_quantity = shelf_quantity + store_quantity`, so the invariant is preserved.

Example: batch 50, shelf 30, store 120 → fromStore=50, fromShelf=0 → store 70, shelf 30, stock_quantity=100.

**Flow (inside a single DB transaction):**
1. Validate `batch_id` and `reason`; load `ProductBatch` with `product` and `branch`; resolve `BranchProduct` from `branch_id` + `product_id`.
2. Auth: same as `store()` (business access + "write off stock" or owner + branch access).
3. If `batch->current_quantity <= 0` → 422 with a clear message.
4. If total branch stock (shelf + store) < batch `current_quantity` → 422 (insufficient stock to write off for this batch).
5. Create `StockWriteoff`: `business_id`, `branch_id`, `branch_product_id`, `product_id`, `sku`, `quantity` = batch’s `current_quantity`, `source` = `'batch'`, `reason`, `written_off_by`, and **`batch_id`**.
6. Deplete batch: `$batch->allocate($batch->current_quantity)`.
7. Reduce branch stock using **store-first-then-shelf** above (so shelf/store and stock_quantity stay in sync).
8. Create one **InventoryTransaction**: `type` = `'damage'`, `batch_id` = batch id, `quantity` = `-quantity`, `shelf_quantity` / `store_quantity` = deltas used (e.g. `-$fromShelf`, `-$fromStore`), `quantity_before` / `quantity_after` and shelf/store before/after from branch product, `notes` = reason, `reference_number` = `'WO-' . str_pad($writeoff->id, 8, '0', STR_PAD_LEFT)`. No call to `InventoryBatchService::allocateStockOut`.

**Schema change:** Add nullable `batch_id` to `stock_writeoffs` with foreign key to `product_batches.id`. Allow `source` = `'batch'` where applicable.

## Implementation steps

1. **Migration**  
   Add nullable `batch_id` to `stock_writeoffs` with foreign key to `product_batches.id` (e.g. `nullOnDelete` or `cascade` per product rules).

2. **Model**  
   In [StockWriteoff](app/Models/StockWriteoff.php): add `batch_id` to `$fillable` and a `batch()` `BelongsTo` relationship to `ProductBatch`.

3. **Controller**  
   In [StockWriteoffController](app/Http/Controllers/StockWriteoffController.php): add `writeOffBatch(Request $request)` that:
   - Validates `current_business_id`, `batch_id`, `reason` (required, string, max 1000).
   - Loads batch; ensures batch exists and `batch->business_id` matches and user has branch access; returns 404/403 as appropriate.
   - If `current_quantity <= 0`, returns 422 ("Batch has no remaining quantity to write off").
   - Ensures branch has enough total stock (shelf + store >= batch current_quantity); otherwise 422.
   - Runs the transaction: create write-off (with `batch_id`, `source` = `'batch'`), `$batch->allocate($qty)`, reduce branch stock via **store-first-then-shelf** (updateStoreQuantity then updateShelfQuantity), create damage transaction with `batch_id`, shelf/store deltas, and quantity before/after.
   - Returns 201 with `message` and `data` (write-off with relations), consistent with `store()`.

4. **Route**  
   In [routes/api.php](routes/api.php), register `POST stock-writeoffs/writeoff-batch` **before** `GET stock-writeoffs/{id}`.

5. **Tests**  
   In [tests/Feature/StockWriteoffTest.php](tests/Feature/StockWriteoffTest.php): add feature tests for:
   - **Success:** batch has remaining qty; branch has sufficient shelf+store; after call, batch depleted, branch product shelf/store/stock reduced consistently (shelf+store=stock_quantity), one StockWriteoff and one InventoryTransaction (type damage, batch_id, correct deltas) exist; response 201.
   - **Validation:** missing `batch_id` or `reason` → 422; batch not found → 404 or 422; batch zero remaining → 422; insufficient branch stock for batch qty → 422.
   - **Authorization:** batch in another business or user without "write off stock" or no branch access → 403.

6. **Pint**  
   Run `vendor/bin/pint --dirty` before finalizing.

## Optional
- **Index/list:** If the stock write-offs index or show response includes `source`, ensure `batch` is handled (and optionally eager-load or expose `batch` when `batch_id` is set).
- **Postman:** If the project maintains a Postman collection, add a request for `POST .../stock-writeoffs/writeoff-batch` with body `current_business_id`, `batch_id`, `reason`.

No new permission is required; reusing "write off stock" keeps the model simple and consistent with product-level write-offs.
