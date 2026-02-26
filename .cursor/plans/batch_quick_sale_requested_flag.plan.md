# Add `quick_sale_requested` attribute to ProductBatch (active or pending only)

## Goal

Add an attribute on **ProductBatch** that indicates whether the batch has an **active or pending** QuickSale request. The value comes **directly from the model** whenever a batch is accessed: the model is the single source of truth via `$appends` and an accessor. Any endpoint that returns batch data (single batch, list, or batches-for-product) will expose this flag—either automatically when the model is serialized, or by reading `$batch->quick_sale_requested` when building a custom response array.

## 1. ProductBatch model

**File:** [app/Models/ProductBatch.php](app/Models/ProductBatch.php)

- **Relationship:** Add `quickSales()` HasMany to QuickSale (same pattern as `transactions()`):
```php
public function quickSales(): HasMany
{
    return $this->hasMany(QuickSale::class, 'batch_id');
}
```

- **Appended attribute:** Add `'quick_sale_requested'` to `$appends` (new property if not present):
```php
protected $appends = ['quick_sale_requested'];
```

- **Accessor:** Add `getQuickSaleRequestedAttribute()` that:
  - Returns true when the batch has at least one QuickSale with status **active** or **pending**.
  - If the model was loaded with `withCount(['quickSales as quick_sale_requested_count' => ...]) `(used in list endpoints to avoid N+1), use that count so no extra query runs; otherwise run an `exists()` query.
```php
public function getQuickSaleRequestedAttribute(): bool
{
    if (array_key_exists('quick_sale_requested_count', $this->attributes)) {
        return (bool) ($this->attributes['quick_sale_requested_count'] ?? 0);
    }
    return $this->quickSales()
        ->whereIn('status', [QuickSale::STATUS_ACTIVE, QuickSale::STATUS_PENDING])
        ->exists();
}
```

- Add `use App\Models\QuickSale;` at the top of the file.

With `$appends`, whenever a ProductBatch is serialized (e.g. `toArray()`, `toJson()`, or in a JSON response), `quick_sale_requested` is included automatically. Endpoints that return the model (or a collection of models) will get the flag without manually adding it; endpoints that build a custom array should include it by reading `$batch->quick_sale_requested` from the model.

## 2. BatchController::show()

**File:** [app/Http/Controllers/Api/BatchController.php](app/Http/Controllers/Api/BatchController.php)

- Inside the `'batch' => [...] `array returned by `show()`, add (e.g. after `'transaction_count'`):
```php
'quick_sale_requested' => $batch->quick_sale_requested,
```


Value comes directly from the model; the accessor runs one `exists()` query for this single batch.

## 3. BatchController::forProduct()

**File:** [app/Http/Controllers/Api/BatchController.php](app/Http/Controllers/Api/BatchController.php)

- Add `withCount` so the accessor can use the count and avoid N+1:
```php
$query = ProductBatch::with(['branch'])
    ->withCount(['quickSales as quick_sale_requested_count' => function ($q) {
        $q->whereIn('status', [\App\Models\QuickSale::STATUS_ACTIVE, \App\Models\QuickSale::STATUS_PENDING]);
    }])
    ->forBusiness($businessId)
    ->where('product_id', $productId);
```

- In the `map()` callback that builds each batch array, add:
```php
'quick_sale_requested' => $batch->quick_sale_requested,
```


Because `quick_sale_requested_count` is set, the accessor uses it and does not run extra queries. Each mapped batch gets `quick_sale_requested` from the model.

## 4. BatchController::index() (optional but recommended)

**File:** [app/Http/Controllers/Api/BatchController.php](app/Http/Controllers/Api/BatchController.php)

- The controller returns `response()->json($batches)` (paginated ProductBatch). With `$appends`, each batch in the JSON will automatically include `quick_sale_requested`. To avoid N+1 (one `exists()` per batch), add the same `withCount` to the index query:
```php
$query = ProductBatch::with(['product', 'branch'])
    ->withCount(['quickSales as quick_sale_requested_count' => function ($q) {
        $q->whereIn('status', [\App\Models\QuickSale::STATUS_ACTIVE, \App\Models\QuickSale::STATUS_PENDING]);
    }])
    ->forBusiness($businessId);
```


Then when the paginated collection is serialized, the accessor will use `quick_sale_requested_count` and no per-batch query will run.

## Summary

| File | Change |

|------|--------|

| [app/Models/ProductBatch.php](app/Models/ProductBatch.php) | Add `quickSales()` relationship; add `$appends` and `getQuickSaleRequestedAttribute()` (active/pending only; use count when present). Single source of truth—anytime the batch is accessed, the attribute comes from the model. |

| [app/Http/Controllers/Api/BatchController.php](app/Http/Controllers/Api/BatchController.php) | `show()`: add `quick_sale_requested` from `$batch->quick_sale_requested`. `forProduct()`: add constrained `withCount`; in map include `quick_sale_requested` from `$batch->quick_sale_requested`. `index()`: add same `withCount` so serialized batches include the flag without N+1. |

Result: `quick_sale_requested` comes directly from the model whenever a batch is accessed. It is true only when the batch has at least one QuickSale with status **active** or **pending**.