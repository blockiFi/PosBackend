# Quick Sale (Near-Expiry Discount) Workflow Documentation

## Overview

The Quick Sale workflow allows authorized users to request discount approvals for products nearing their expiry dates. This helps reduce waste and optimize inventory management by enabling time-based discounts on products that need to be sold quickly.

## Table of Contents

1. [Workflow States](#workflow-states)
2. [Permissions](#permissions)
3. [API Endpoints](#api-endpoints)
4. [Business Rules](#business-rules)
5. [Database Schema](#database-schema)
6. [Usage Examples](#usage-examples)
7. [Integration Guide](#integration-guide)

---

## Workflow States

Quick sale requests move through the following states:

```
pending → approved → active → expired/ended
       ↓
    rejected
```

### State Definitions

| State | Description |
|-------|-------------|
| **pending** | Initial state when a quick sale is requested. Awaits approval. |
| **approved** | Request has been approved with discount parameters. Waiting for start time. |
| **active** | Quick sale is currently active. Discount should be applied at POS. |
| **expired** | Quick sale ended automatically when end_time was reached. |
| **ended** | Quick sale was manually ended before the scheduled end_time. |
| **rejected** | Request was rejected by an approver with a reason. |

### State Transitions

- `pending` → `approved`: When an approver approves with discount parameters
- `pending` → `rejected`: When an approver rejects the request
- `approved` → `active`: Automatically when start_time is reached (or immediately if start_time ≤ now)
- `active` → `expired`: Automatically when end_time is reached
- `active` → `ended`: Manually by an authorized user before end_time
- `approved` → `ended`: Manually by an authorized user before start_time

---

## Permissions

Two permissions control access to the quick sale workflow:

| Permission | Description | Typical Roles |
|------------|-------------|---------------|
| `request quick sale` | Can create quick sale requests and view own requests | Store Manager, Inventory Manager |
| `approve quick sale` | Can approve/reject requests, view all requests, and end active quick sales | Senior Manager, Store Director |

### Permission Setup

Permissions are seeded using the `QuickSalePermissionSeeder`:

```bash
php artisan db:seed --class=QuickSalePermissionSeeder
```

Assign permissions to roles:

```php
use Spatie\Permission\Models\Role;

$storeManager = Role::findByName('Store Manager');
$storeManager->givePermissionTo('request quick sale');

$seniorManager = Role::findByName('Senior Manager');
$seniorManager->givePermissionTo(['request quick sale', 'approve quick sale']);
```

---

## API Endpoints

All endpoints require:
- **Authentication**: Bearer token via Sanctum
- **Header**: `X-Business-Id` containing the current business ID
- **Base URL**: `/api/quick-sales`

### 1. List Quick Sales

**GET** `/api/quick-sales`

Lists quick sales based on user permissions:
- Users with only "request quick sale" permission see only their own requests
- Users with "approve quick sale" permission see all requests

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | Filter by status: pending, approved, active, expired, ended, rejected |

**Request Example:**

```bash
curl -X GET "https://api.example.com/api/quick-sales?status=active" \
  -H "Authorization: Bearer {token}" \
  -H "X-Business-Id: 1"
```

**Response Example:**

```json
[
  {
    "id": 1,
    "product_id": 42,
    "business_id": 1,
    "branch_id": 3,
    "requested_by": 5,
    "approved_by": 2,
    "ended_by": null,
    "reason": "Product expires in 3 days",
    "expiry_date": "2024-02-12",
    "discount_type": "percentage",
    "discount_value": 25.00,
    "start_time": "2024-02-09T08:00:00Z",
    "end_time": "2024-02-11T20:00:00Z",
    "status": "active",
    "rejection_reason": null,
    "approved_at": "2024-02-09T07:30:00Z",
    "ended_at": null,
    "created_at": "2024-02-09T07:00:00Z",
    "updated_at": "2024-02-09T08:00:00Z",
    "product": {
      "id": 42,
      "name": "Fresh Milk 1L",
      "sku": "MILK-001"
    },
    "branch": {
      "id": 3,
      "name": "Downtown Store"
    }
  }
]
```

---

### 2. Create Quick Sale Request

**POST** `/api/quick-sales`

Creates a new quick sale request. Requires "request quick sale" permission.

**Request Body:**

```json
{
  "product_id": 42,
  "branch_id": 3,
  "reason": "Product expires in 3 days, need to clear stock",
  "expiry_date": "2024-02-12"
}
```

**Field Validations:**

| Field | Type | Rules | Description |
|-------|------|-------|-------------|
| `product_id` | integer | required, exists | Must be a valid product ID |
| `branch_id` | integer | required, exists | Must be a valid branch ID user has access to |
| `reason` | string | required, min:10, max:500 | Explanation for the quick sale request |
| `expiry_date` | date | required, after:today | Product's expiry date |

**Business Validations:**
- Product must exist in the specified branch
- Product must have stock (quantity > 0)
- No pending quick sale request can exist for the same product in the same branch
- User must have access to the specified branch

**Success Response (201):**

```json
{
  "message": "Quick sale request created successfully",
  "quick_sale": {
    "id": 1,
    "product_id": 42,
    "business_id": 1,
    "branch_id": 3,
    "requested_by": 5,
    "reason": "Product expires in 3 days, need to clear stock",
    "expiry_date": "2024-02-12",
    "status": "pending",
    "created_at": "2024-02-09T07:00:00Z"
  }
}
```

**Error Responses:**

```json
// 403 Forbidden - No permission
{
  "message": "Unauthorized"
}

// 400 Bad Request - Out of stock
{
  "message": "Product is out of stock"
}

// 400 Bad Request - Duplicate pending request
{
  "message": "A pending quick sale request already exists for this product in this branch"
}
```

---

### 3. View Quick Sale Details

**GET** `/api/quick-sales/{id}`

Retrieves details of a specific quick sale request.

**Authorization:**
- Users with only "request quick sale" can only view their own requests
- Users with "approve quick sale" can view any request

**Response Example:**

```json
{
  "id": 1,
  "product_id": 42,
  "business_id": 1,
  "branch_id": 3,
  "requested_by": 5,
  "approved_by": 2,
  "ended_by": null,
  "reason": "Product expires in 3 days",
  "expiry_date": "2024-02-12",
  "discount_type": "percentage",
  "discount_value": 25.00,
  "start_time": "2024-02-09T08:00:00Z",
  "end_time": "2024-02-11T20:00:00Z",
  "status": "active",
  "rejection_reason": null,
  "approved_at": "2024-02-09T07:30:00Z",
  "ended_at": null,
  "created_at": "2024-02-09T07:00:00Z",
  "updated_at": "2024-02-09T08:00:00Z",
  "product": {
    "id": 42,
    "name": "Fresh Milk 1L",
    "sku": "MILK-001",
    "branch_products": [
      {
        "branch_id": 3,
        "stock_quantity": 15,
        "selling_price": 4.99
      }
    ]
  },
  "branch": {
    "id": 3,
    "name": "Downtown Store"
  },
  "requested_by_user": {
    "id": 5,
    "name": "John Store Manager"
  },
  "approved_by_user": {
    "id": 2,
    "name": "Jane Senior Manager"
  }
}
```

---

### 4. Approve Quick Sale Request

**POST** `/api/quick-sales/{id}/approve`

Approves a pending quick sale request with discount parameters. Requires "approve quick sale" permission.

**Request Body:**

```json
{
  "discount_type": "percentage",
  "discount_value": 25.0,
  "start_time": "2024-02-09T08:00:00Z",
  "end_time": "2024-02-11T20:00:00Z"
}
```

**Field Validations:**

| Field | Type | Rules | Description |
|-------|------|-------|-------------|
| `discount_type` | string | required, in:percentage,fixed | Type of discount |
| `discount_value` | numeric | required, min:0 | Discount amount (see additional rules below) |
| `start_time` | datetime | required, after_or_equal:now | When discount becomes active |
| `end_time` | datetime | required, after:start_time | When discount expires |

**Additional Business Rules:**

1. **Percentage Discount**:
   - Value must be between 0 and 100
   - Example: 25 means 25% off

2. **Fixed Discount**:
   - Value must be less than the product's selling price
   - Example: For a $4.99 product, maximum fixed discount is $4.98

3. **Time-Based Activation**:
   - If `start_time` ≤ current time, quick sale is activated immediately (status: `active`)
   - If `start_time` > current time, quick sale is scheduled (status: `approved`)

4. **Overlap Prevention**:
   - Cannot approve if another approved/active quick sale exists for the same product in the same branch with overlapping time periods

5. **Self-Approval Prevention**:
   - Users cannot approve their own requests

**Success Response (200):**

```json
{
  "message": "Quick sale approved successfully",
  "quick_sale": {
    "id": 1,
    "product_id": 42,
    "business_id": 1,
    "branch_id": 3,
    "requested_by": 5,
    "approved_by": 2,
    "discount_type": "percentage",
    "discount_value": 25.00,
    "start_time": "2024-02-09T08:00:00Z",
    "end_time": "2024-02-11T20:00:00Z",
    "status": "active",
    "approved_at": "2024-02-09T07:35:00Z",
    "product": {
      "id": 42,
      "name": "Fresh Milk 1L"
    }
  }
}
```

**Error Responses:**

```json
// 422 Unprocessable Entity - Percentage over 100
{
  "message": "Percentage discount cannot exceed 100%",
  "errors": {
    "discount_value": ["Percentage must be between 0 and 100"]
  }
}

// 422 Unprocessable Entity - Fixed discount too high
{
  "message": "Fixed discount amount cannot be greater than or equal to the product price",
  "errors": {
    "discount_value": ["Must be less than product price"]
  }
}

// 400 Bad Request - Overlapping quick sale
{
  "message": "Another quick sale is already scheduled for this product during the selected time period"
}

// 403 Forbidden - Self-approval
{
  "message": "You cannot approve your own quick sale request"
}

// 400 Bad Request - Already approved
{
  "message": "Only pending quick sale requests can be approved",
  "current_status": "approved"
}
```

---

### 5. Reject Quick Sale Request

**POST** `/api/quick-sales/{id}/reject`

Rejects a pending quick sale request. Requires "approve quick sale" permission.

**Request Body:**

```json
{
  "rejection_reason": "Product stock is too high to warrant a discount at this time"
}
```

**Field Validations:**

| Field | Type | Rules | Description |
|-------|------|-------|-------------|
| `rejection_reason` | string | required, min:10, max:1000 | Detailed reason for rejection |

**Success Response (200):**

```json
{
  "message": "Quick sale rejected successfully",
  "quick_sale": {
    "id": 1,
    "status": "rejected",
    "rejection_reason": "Product stock is too high to warrant a discount at this time",
    "rejected_at": "2024-02-09T07:40:00Z"
  }
}
```

---

### 6. End Active Quick Sale

**POST** `/api/quick-sales/{id}/end`

Manually ends an active or approved quick sale before its scheduled end time. Requires "approve quick sale" permission.

**Use Cases:**
- Product sold out before end_time
- Business decision to end promotion early
- Product removed from inventory

**Request Body:**

No body required.

**Success Response (200):**

```json
{
  "message": "Quick sale ended successfully",
  "quick_sale": {
    "id": 1,
    "status": "ended",
    "ended_by": 2,
    "ended_at": "2024-02-10T14:30:00Z"
  }
}
```

**Error Responses:**

```json
// 400 Bad Request - Not active
{
  "message": "Only active or approved quick sales can be ended",
  "current_status": "pending"
}
```

---

## Business Rules

### 1. Discount Calculations

The `QuickSale` model provides methods to calculate discounts:

```php
$quickSale = QuickSale::find(1);
$originalPrice = 4.99;

// Get discount amount
$discountAmount = $quickSale->calculateDiscount($originalPrice);
// For 25% discount: 1.2475

// Get final price after discount
$finalPrice = $quickSale->calculateFinalPrice($originalPrice);
// For 25% discount: 3.7425
```

**Percentage Discount:**
```
Discount Amount = (Original Price × Discount Value) / 100
Final Price = Original Price - Discount Amount
```

**Fixed Discount:**
```
Discount Amount = Discount Value
Final Price = Original Price - Discount Amount
```

### 2. Overlap Detection

The system prevents multiple active discounts for the same product:

```php
// Check if a time period overlaps with existing quick sales
$hasOverlap = QuickSale::hasOverlappingQuickSale(
    $productId,
    $branchId,
    $startTime,
    $endTime
);
```

**Overlap Logic:**
- Two quick sales overlap if they share any time period
- Only checks against `approved` and `active` quick sales
- Prevents: Double discounts, confusion at POS, pricing conflicts

### 3. Auto-Activation

Quick sales automatically activate when:
- They are approved with `start_time` ≤ current time
- A scheduled quick sale's `start_time` is reached (requires background job - see Integration Guide)

### 4. Auto-Expiration

Quick sales automatically expire when:
- An active quick sale's `end_time` is reached (requires background job - see Integration Guide)

### 5. Stock Validation

- Quick sales can only be requested for products with `stock_quantity > 0`
- No validation during approval (stock may have changed)
- POS should verify stock before applying discount

### 6. Branch Isolation

- Users can only create/approve quick sales for branches they have access to
- Quick sales are isolated by business and branch
- Discount applies only at the specific branch

---

## Database Schema

### `quick_sales` Table

```sql
CREATE TABLE quick_sales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    business_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    ended_by BIGINT UNSIGNED NULL,
    reason TEXT NOT NULL,
    expiry_date DATE NOT NULL,
    discount_type ENUM('percentage', 'fixed') NULL,
    discount_value DECIMAL(10, 2) NULL,
    start_time TIMESTAMP NULL,
    end_time TIMESTAMP NULL,
    status ENUM('pending', 'approved', 'active', 'expired', 'ended', 'rejected') NOT NULL DEFAULT 'pending',
    rejection_reason TEXT NULL,
    approved_at TIMESTAMP NULL,
    ended_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (ended_by) REFERENCES users(id),
    
    INDEX idx_business_status (business_id, status),
    INDEX idx_branch_status (branch_id, status),
    INDEX idx_product_status (product_id, status),
    INDEX idx_time_range (start_time, end_time)
);
```

### Field Descriptions

| Field | Type | Nullable | Description |
|-------|------|----------|-------------|
| `product_id` | BIGINT | No | Product being discounted |
| `business_id` | BIGINT | No | Business owning the quick sale |
| `branch_id` | BIGINT | No | Branch where discount applies |
| `requested_by` | BIGINT | No | User who created the request |
| `approved_by` | BIGINT | Yes | User who approved (null if pending/rejected) |
| `ended_by` | BIGINT | Yes | User who manually ended (null if not ended) |
| `reason` | TEXT | No | Why discount is needed (e.g., near expiry) |
| `expiry_date` | DATE | No | Product's actual expiry date |
| `discount_type` | ENUM | Yes | 'percentage' or 'fixed' (null until approved) |
| `discount_value` | DECIMAL | Yes | Discount amount (null until approved) |
| `start_time` | TIMESTAMP | Yes | When discount becomes active (null until approved) |
| `end_time` | TIMESTAMP | Yes | When discount expires (null until approved) |
| `status` | ENUM | No | Current state of the quick sale |
| `rejection_reason` | TEXT | Yes | Why request was rejected (null if not rejected) |
| `approved_at` | TIMESTAMP | Yes | When request was approved |
| `ended_at` | TIMESTAMP | Yes | When quick sale was ended |

---

## Usage Examples

### Example 1: Complete Workflow (Percentage Discount)

```bash
# Step 1: Store manager creates request
curl -X POST "https://api.example.com/api/quick-sales" \
  -H "Authorization: Bearer {store_manager_token}" \
  -H "X-Business-Id: 1" \
  -H "Content-Type: application/json" \
  -d '{
    "product_id": 42,
    "branch_id": 3,
    "reason": "Fresh milk expires in 2 days, 15 units in stock",
    "expiry_date": "2024-02-11"
  }'

# Response: { "id": 1, "status": "pending", ... }

# Step 2: Senior manager approves with 25% discount
curl -X POST "https://api.example.com/api/quick-sales/1/approve" \
  -H "Authorization: Bearer {senior_manager_token}" \
  -H "X-Business-Id: 1" \
  -H "Content-Type: application/json" \
  -d '{
    "discount_type": "percentage",
    "discount_value": 25,
    "start_time": "2024-02-09T08:00:00Z",
    "end_time": "2024-02-11T20:00:00Z"
  }'

# Response: { "status": "active", "discount_value": 25.00, ... }
# (Status is "active" because start_time has passed)

# Step 3: Check active quick sales
curl -X GET "https://api.example.com/api/quick-sales?status=active" \
  -H "Authorization: Bearer {pos_user_token}" \
  -H "X-Business-Id: 1"

# Step 4: Product sells out early, manager ends quick sale
curl -X POST "https://api.example.com/api/quick-sales/1/end" \
  -H "Authorization: Bearer {senior_manager_token}" \
  -H "X-Business-Id: 1"

# Response: { "status": "ended", "ended_at": "2024-02-10T14:30:00Z", ... }
```

### Example 2: Fixed Discount

```bash
# Approve with $1.50 off a $4.99 product
curl -X POST "https://api.example.com/api/quick-sales/2/approve" \
  -H "Authorization: Bearer {senior_manager_token}" \
  -H "X-Business-Id: 1" \
  -H "Content-Type: application/json" \
  -d '{
    "discount_type": "fixed",
    "discount_value": 1.50,
    "start_time": "2024-02-09T08:00:00Z",
    "end_time": "2024-02-11T20:00:00Z"
  }'

# Original Price: $4.99
# Discount: $1.50
# Final Price: $3.49
```

### Example 3: Rejection

```bash
curl -X POST "https://api.example.com/api/quick-sales/3/reject" \
  -H "Authorization: Bearer {senior_manager_token}" \
  -H "X-Business-Id: 1" \
  -H "Content-Type: application/json" \
  -d '{
    "rejection_reason": "Stock level is sufficient. Product will likely sell at full price before expiry."
  }'
```

---

## Integration Guide

### 1. POS Integration

At the point of sale, when scanning a product, check for active quick sales:

```php
use App\Models\QuickSale;
use Carbon\Carbon;

function getProductPrice($productId, $branchId, $originalPrice)
{
    // Check for active quick sale
    $quickSale = QuickSale::where('product_id', $productId)
        ->where('branch_id', $branchId)
        ->where('status', QuickSale::STATUS_ACTIVE)
        ->where('start_time', '<=', Carbon::now())
        ->where('end_time', '>=', Carbon::now())
        ->first();
    
    if ($quickSale) {
        // Apply discount
        return $quickSale->calculateFinalPrice($originalPrice);
    }
    
    // Return original price
    return $originalPrice;
}
```

### 2. Background Jobs for Auto-Activation/Expiration

Create scheduled jobs to automatically transition quick sales:

**app/Console/Commands/ActivateScheduledQuickSales.php:**

```php
<?php

namespace App\Console\Commands;

use App\Models\QuickSale;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ActivateScheduledQuickSales extends Command
{
    protected $signature = 'quicksales:activate';
    protected $description = 'Activate approved quick sales that have reached their start time';

    public function handle()
    {
        $activated = QuickSale::where('status', QuickSale::STATUS_APPROVED)
            ->where('start_time', '<=', Carbon::now())
            ->get();

        foreach ($activated as $quickSale) {
            $quickSale->markAsActive();
            $this->info("Activated quick sale #{$quickSale->id}");
        }

        $this->info("Activated {$activated->count()} quick sales");
    }
}
```

**app/Console/Commands/ExpireActiveQuickSales.php:**

```php
<?php

namespace App\Console\Commands;

use App\Models\QuickSale;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ExpireActiveQuickSales extends Command
{
    protected $signature = 'quicksales:expire';
    protected $description = 'Expire active quick sales that have passed their end time';

    public function handle()
    {
        $expired = QuickSale::where('status', QuickSale::STATUS_ACTIVE)
            ->where('end_time', '<=', Carbon::now())
            ->get();

        foreach ($expired as $quickSale) {
            $quickSale->markAsExpired();
            $this->info("Expired quick sale #{$quickSale->id}");
        }

        $this->info("Expired {$expired->count()} quick sales");
    }
}
```

**Schedule in app/Console/Kernel.php:**

```php
protected function schedule(Schedule $schedule)
{
    // Run every minute to ensure timely activation/expiration
    $schedule->command('quicksales:activate')->everyMinute();
    $schedule->command('quicksales:expire')->everyMinute();
}
```

### 3. Notifications

Send notifications when quick sales are approved/rejected:

```php
use Illuminate\Support\Facades\Notification;
use App\Notifications\QuickSaleApproved;

// In QuickSaleController::approve()
$requester = User::find($quickSale->requested_by);
$requester->notify(new QuickSaleApproved($quickSale));
```

### 4. Reporting

Track quick sale effectiveness:

```php
use App\Models\QuickSale;
use App\Models\Sale;

// Get quick sales that successfully moved stock
$effectiveQuickSales = QuickSale::whereIn('status', ['expired', 'ended'])
    ->with(['product', 'branch'])
    ->get()
    ->map(function ($qs) {
        // Count sales during the quick sale period
        $salesCount = Sale::where('branch_id', $qs->branch_id)
            ->whereHas('items', function ($q) use ($qs) {
                $q->where('product_id', $qs->product_id);
            })
            ->whereBetween('created_at', [$qs->start_time, $qs->ended_at ?? $qs->end_time])
            ->count();
        
        return [
            'quick_sale_id' => $qs->id,
            'product_name' => $qs->product->name,
            'discount' => "{$qs->discount_value}" . ($qs->discount_type === 'percentage' ? '%' : ' fixed'),
            'sales_during_period' => $salesCount,
        ];
    });
```

### 5. Mobile App Integration

For mobile POS apps, implement a visual indicator for products with active discounts:

```javascript
// Fetch active quick sales when opening POS
async function loadActiveQuickSales(branchId) {
  const response = await fetch('/api/quick-sales?status=active', {
    headers: {
      'Authorization': `Bearer ${token}`,
      'X-Business-Id': businessId
    }
  });
  
  const quickSales = await response.json();
  
  // Create lookup map: productId -> quickSale
  return quickSales.reduce((map, qs) => {
    map[qs.product_id] = qs;
    return map;
  }, {});
}

// When displaying product
function renderProduct(product, activeQuickSales) {
  const quickSale = activeQuickSales[product.id];
  
  if (quickSale) {
    const finalPrice = calculateFinalPrice(
      product.selling_price,
      quickSale.discount_type,
      quickSale.discount_value
    );
    
    return `
      <div class="product ${quickSale ? 'has-discount' : ''}">
        <span class="product-name">${product.name}</span>
        ${quickSale ? `
          <span class="original-price strikethrough">$${product.selling_price}</span>
          <span class="discounted-price">$${finalPrice}</span>
          <span class="discount-badge">${quickSale.discount_value}${quickSale.discount_type === 'percentage' ? '%' : '$'} OFF</span>
        ` : `
          <span class="price">$${product.selling_price}</span>
        `}
      </div>
    `;
  }
}
```

---

## Security Considerations

1. **Permission Validation**: All endpoints verify user permissions before processing
2. **Branch Isolation**: Users can only access quick sales for branches they have permission to access
3. **Business Scoping**: All queries are scoped to the current business (via `X-Business-Id` header)
4. **Self-Approval Prevention**: Users cannot approve their own requests
5. **Audit Trail**: All actions are logged with user IDs and timestamps
6. **Input Validation**: All inputs are validated for type, range, and business logic constraints

---

## Testing

Comprehensive tests are available in `tests/Feature/QuickSaleTest.php`:

```bash
php artisan test --filter=QuickSaleTest
```

**Test Coverage:**
- ✅ Permission-based access control
- ✅ Request creation and validation
- ✅ Stock availability checks
- ✅ Duplicate request prevention
- ✅ Approval with percentage discount
- ✅ Approval with fixed discount
- ✅ Auto-activation when start_time ≤ now
- ✅ Percentage validation (≤ 100%)
- ✅ Fixed discount validation (< product price)
- ✅ Overlap prevention
- ✅ Self-approval prevention
- ✅ Rejection workflow
- ✅ Manual ending of active quick sales
- ✅ List filtering by permission
- ✅ Status filtering

---

## Support and Maintenance

### Common Issues

**Issue**: Quick sale not activating at scheduled time
- **Solution**: Ensure scheduled commands are running (`quicksales:activate`)

**Issue**: Discount not applying at POS
- **Solution**: Verify quick sale status is "active" and current time is within start_time/end_time

**Issue**: Cannot approve request (overlap error)
- **Solution**: Check for existing active/approved quick sales for the product, adjust time range or end the conflicting quick sale

### Monitoring

Monitor these metrics:
- Average time from request to approval
- Percentage of requests approved vs rejected
- Active quick sales count per branch
- Revenue impact of quick sales (sales during vs before/after)

---

## Changelog

### Version 1.0.0 (2024-02-08)
- Initial implementation
- Support for percentage and fixed discounts
- Time-based activation and expiration
- Overlap detection
- Complete API with CRUD operations
- Comprehensive test coverage (16 tests, 47 assertions)

---

## License

This documentation is part of the POS Backend API system.
