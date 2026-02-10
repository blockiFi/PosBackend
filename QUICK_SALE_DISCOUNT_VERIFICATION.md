# Quick Sale Discount Verification System

## Overview

The system now automatically manages discounts on BranchProduct records, ensuring that only valid quick sale discounts are applied. When a quick sale expires or is ended, the discount is automatically removed from the associated BranchProduct.

## Automatic Discount Management

### When Discounts are Applied

Discounts are **automatically applied** to the BranchProduct when:
- A quick sale is **activated** (status changes to `active`)
- This happens when:
  - A quick sale is approved with `start_time` ≤ now (immediate activation)
  - A scheduled quick sale's `start_time` is reached

### When Discounts are Removed

Discounts are **automatically removed** from the BranchProduct when:
- A quick sale is **ended** manually
- A quick sale **expires** (reaches its `end_time`)
- A quick sale is **rejected** (if previously applied)

## Manual Verification

### Verify Single Product

To manually verify and clean up a single BranchProduct's discount:

```php
use App\Models\BranchProduct;

$branchProduct = BranchProduct::find($id);
$branchProduct->verifyAndCleanQuickSaleDiscount();
```

This method:
1. Checks if the BranchProduct has a discount applied
2. Looks for an active quick sale that matches the discount
3. If no matching active quick sale is found, removes the discount

### Verify Multiple Products

To verify multiple BranchProducts at once:

```php
use App\Models\BranchProduct;

$branchProductIds = [1, 2, 3, 4, 5];
BranchProduct::verifyAndCleanDiscountsForProducts($branchProductIds);
```

### Verify All Products in a Branch

To verify all BranchProducts in a specific branch:

```php
use App\Models\BranchProduct;

$branchId = 3;
BranchProduct::verifyAndCleanDiscountsForBranch($branchId);
```

## Scheduled Cleanup

### Artisan Command

A command is provided to clean up expired quick sale discounts:

```bash
# Clean up all branches
php artisan quicksales:cleanup-discounts --all

# Clean up specific branch
php artisan quicksales:cleanup-discounts --branch-id=3
```

### Schedule in Kernel

Add to `app/Console/Kernel.php` to run automatically:

```php
protected function schedule(Schedule $schedule)
{
    // Clean up expired quick sale discounts every hour
    $schedule->command('quicksales:cleanup-discounts --all')
        ->hourly();
        
    // Or run every 15 minutes for more frequent cleanup
    $schedule->command('quicksales:cleanup-discounts --all')
        ->everyFifteenMinutes();
}
```

## Integration Examples

### Before Displaying Products in POS

When loading products for the POS, verify discounts are still valid:

```php
use App\Models\BranchProduct;

// Load products for a branch
$products = BranchProduct::where('branch_id', $branchId)
    ->with('product')
    ->get();

// Verify and clean discounts before displaying
$productIds = $products->pluck('id')->toArray();
BranchProduct::verifyAndCleanDiscountsForProducts($productIds);

// Refresh products to get updated discount info
$products = $products->fresh();
```

### In API Controller

Example of using verification in a controller:

```php
use App\Models\BranchProduct;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->branch_id;
        
        // Clean up any expired discounts first
        BranchProduct::verifyAndCleanDiscountsForBranch($branchId);
        
        // Then fetch products with current discounts
        $products = BranchProduct::where('branch_id', $branchId)
            ->with('product')
            ->get();
            
        return response()->json($products);
    }
}
```

### Using Model Events (Optional)

You can also add automatic verification on model retrieval by using Laravel's `retrieved` event in the BranchProduct model:

```php
// In BranchProduct model

protected static function booted()
{
    // Automatically verify discount when a BranchProduct is retrieved
    static::retrieved(function ($branchProduct) {
        // Only verify if it has a discount
        if ($branchProduct->discount_type && $branchProduct->discount_amount) {
            $branchProduct->verifyAndCleanQuickSaleDiscount();
        }
    });
}
```

**Note:** This approach verifies on every retrieval, which may impact performance. Use selectively.

## How It Works

### Discount Matching Logic

The system checks if a discount is valid by:

1. **Product & Branch Match**: Quick sale must be for the same product and branch
2. **Status**: Quick sale must be `active`
3. **Discount Match**: 
   - `discount_type` must match (percentage vs fixed)
   - `discount_value` must match the `discount_amount`
4. **Time Window**: Current time must be between `start_time` and `end_time`

### Example Scenario

```php
// 1. Quick sale is approved and activated
$quickSale = QuickSale::find(1);
// Status: active, discount_type: percentage, discount_value: 25
// BranchProduct gets: discount_type: percentage, discount_amount: 25

// 2. Later, quick sale expires or is ended
$quickSale->markAsExpired();
// BranchProduct gets: discount_type: null, discount_amount: null

// 3. If verification runs before expiry
$branchProduct->verifyAndCleanQuickSaleDiscount();
// Discount remains because active quick sale still exists

// 4. If verification runs after expiry
$branchProduct->verifyAndCleanQuickSaleDiscount();
// Discount is removed because no active quick sale matches
```

## Best Practices

### 1. Regular Scheduled Cleanup

Run the cleanup command regularly to ensure discounts don't remain after quick sales expire:

```php
// Recommended: Every 15-30 minutes
$schedule->command('quicksales:cleanup-discounts --all')
    ->everyFifteenMinutes();
```

### 2. Verify Before Critical Operations

Verify discounts before:
- Displaying products to customers
- Processing sales
- Generating price lists or catalogs
- Running reports

### 3. Monitor Cleanup Activity

Log cleanup activities to track system behavior:

```php
use Illuminate\Support\Facades\Log;

$branchProduct->verifyAndCleanQuickSaleDiscount();
if ($branchProduct->wasChanged('discount_type')) {
    Log::info('Quick sale discount cleaned', [
        'product_id' => $branchProduct->product_id,
        'branch_id' => $branchProduct->branch_id,
        'old_discount' => $branchProduct->getOriginal('discount_type'),
    ]);
}
```

### 4. Handle Edge Cases

The system is designed to handle:
- Multiple quick sales for the same product (only matches exact discount)
- Manual discount changes (won't remove non-matching discounts)
- Missing quick sale records (removes orphaned discounts)
- Concurrent operations (database-level consistency)

## Testing

Tests are provided in `tests/Feature/QuickSaleTest.php`:

```bash
php artisan test --filter=QuickSaleTest
```

**Test Coverage:**
- ✅ Discount applied when quick sale activated
- ✅ Discount removed when quick sale ended
- ✅ Discount removed when quick sale rejected
- ✅ Verification removes expired quick sale discounts
- ✅ Verification keeps discount when quick sale still active

## Performance Considerations

### Batch Operations

When verifying many products, use batch methods:

```php
// ✅ GOOD - Single query to load products, then iterate
BranchProduct::verifyAndCleanDiscountsForBranch($branchId);

// ❌ AVOID - N+1 queries
$products->each(fn($p) => $p->verifyAndCleanQuickSaleDiscount());
```

### Caching Active Quick Sales

For high-traffic scenarios, consider caching active quick sales:

```php
use Illuminate\Support\Facades\Cache;

$activeQuickSales = Cache::remember(
    "branch_{$branchId}_active_quick_sales",
    now()->addMinutes(5),
    fn() => QuickSale::where('branch_id', $branchId)
        ->where('status', QuickSale::STATUS_ACTIVE)
        ->get()
);
```

## Troubleshooting

### Discount Not Being Applied

**Possible causes:**
1. Quick sale status is not `active`
2. Current time is outside the `start_time` to `end_time` window
3. `markAsActive()` was not called after approval

**Solution:**
```php
// Check quick sale status and times
$quickSale = QuickSale::find($id);
dd([
    'status' => $quickSale->status,
    'start_time' => $quickSale->start_time,
    'end_time' => $quickSale->end_time,
    'now' => now(),
]);
```

### Discount Not Being Removed

**Possible causes:**
1. Quick sale status changed but `removeDiscountFromBranchProduct()` not called
2. Discount values don't match exactly
3. Different discount was manually applied

**Solution:**
```php
// Manually trigger cleanup
$branchProduct->verifyAndCleanQuickSaleDiscount();

// Or check for orphaned discounts
$orphaned = BranchProduct::whereNotNull('discount_type')
    ->whereDoesntHave('activeQuickSale')
    ->get();
```

### Discount Keeps Getting Removed

**Possible causes:**
1. Verification running too frequently
2. Quick sale time window is incorrect
3. System clock issues

**Solution:**
```php
// Verify quick sale is actually active
$quickSale = QuickSale::where('product_id', $productId)
    ->where('branch_id', $branchId)
    ->where('status', QuickSale::STATUS_ACTIVE)
    ->where('start_time', '<=', now())
    ->where('end_time', '>', now())
    ->first();

if (!$quickSale) {
    Log::warning('No active quick sale found for product', [
        'product_id' => $productId,
        'branch_id' => $branchId,
        'current_time' => now(),
    ]);
}
```

## Summary

The Quick Sale discount verification system ensures data consistency by:

1. **Automatically applying** discounts when quick sales activate
2. **Automatically removing** discounts when quick sales end/expire
3. **Providing verification methods** to clean up orphaned discounts
4. **Offering scheduled cleanup** via Artisan command
5. **Maintaining audit trail** through quick sale records

This system ensures that customers always see current, valid pricing and prevents stale discounts from remaining active after promotions end.
