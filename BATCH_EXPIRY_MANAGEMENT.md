# Batch & Expiry Date Management System

## Overview

This POS system now includes comprehensive batch and expiry date tracking for inventory management, implementing FEFO (First Expired First Out) allocation logic.

## Features

### 1. Batch Tracking
- Unique batch numbers (auto-generated if not provided)
- Lot numbers from suppliers
- Manufacturing and expiry dates
- Quantity tracking (received vs current)
- Unit cost per batch
- Supplier information (name and reference)
- Status tracking (active, depleted, expired, recalled)

### 2. FEFO Allocation
- Automatically allocates inventory from batches closest to expiry
- Prevents selling expired products
- Tracks which batches were used for each sale
- Maintains audit trail of batch movements

### 3. Expiry Monitoring
- Identifies near-expiry products
- Lists expired products with remaining stock
- Calculates days until expiry
- Supports expiry alerts

## Database Schema

### `product_batches` Table
```sql
- id, uuid
- business_id, branch_id, product_id
- batch_number (auto-generated: BATCH-YYYYMMDD-XXXXXX)
- lot_number (supplier's lot number)
- manufacturing_date
- expiry_date
- received_quantity
- current_quantity
- unit_cost
- supplier_name
- supplier_reference
- inventory_transaction_id (link to original purchase)
- status (active, depleted, expired, recalled)
- meta_data (JSON for additional info)
```

### `inventory_transactions` Table
- Added `batch_id` foreign key to link transactions to batches

## API Endpoints

### Batch Management

#### List All Batches
```
GET /api/batches
```
**Query Parameters:**
- `branch_id` - Filter by branch
- `product_id` - Filter by product
- `status` - Filter by status (active, depleted, expired, recalled)
- `expired=true` - Show only expired batches
- `near_expiry=30` - Show batches expiring within X days
- `batch_number` - Search by batch number
- `lot_number` - Search by lot number
- `sort_by` - Sort field (default: expiry_date)
- `sort_direction` - Sort direction (asc/desc)
- `per_page` - Pagination (default: 15)

**Permission:** `view batches`

#### Get Product Batches
```
GET /api/products/{id}/batches
```
Returns all batches for a specific product, ordered by FEFO.

**Permission:** `view batches`

#### Near-Expiry Batches
```
GET /api/batches/near-expiry?days=30
```
Returns batches expiring within specified days (default: 30).

**Response:**
```json
{
  "batches": [...],
  "count": 5,
  "days_threshold": 30
}
```

**Permission:** `view batches`

#### Expired Batches
```
GET /api/batches/expired
```
Returns all expired batches with remaining stock.

**Response:**
```json
{
  "batches": [...],
  "count": 3,
  "total_value": "1234.56"
}
```

**Permission:** `view batches`

#### Get Batch Details
```
GET /api/batches/{id}
```
Returns detailed information about a specific batch including:
- Batch information
- Product details
- Branch information
- Original purchase transaction
- Transaction count
- Expiry status

**Permission:** `view batches`

#### Update Batch
```
PATCH /api/batches/{id}
```
Update batch information (for corrections/recalls).

**Request Body:**
```json
{
  "status": "recalled",
  "lot_number": "LOT-12345",
  "supplier_name": "New Supplier Name",
  "supplier_reference": "REF-12345",
  "notes": "Reason for update"
}
```

**Permission:** `manage batches`

### Inventory Transactions with Batch Support

#### Create Purchase with Batch
```
POST /api/inventory/transactions
```

**Request Body:**
```json
{
  "branch_id": 1,
  "product_id": 5,
  "type": "purchase",
  "quantity": 100,
  "unit_cost": 15.50,
  "batch_number": "BATCH-001",
  "lot_number": "LOT-2024-001",
  "manufacturing_date": "2024-01-01",
  "expiry_date": "2025-01-01",
  "supplier_name": "ABC Suppliers",
  "supplier_reference": "INV-12345"
}
```

**Notes:**
- If `batch_number` is not provided, it will be auto-generated
- Batch is automatically created for all purchases
- `expiry_date` must be after `manufacturing_date`

#### Sale with FEFO Allocation
When creating a sale transaction, the system automatically:
1. Finds batches using FEFO logic
2. Allocates from batches closest to expiry first
3. Creates batch allocation records
4. Updates batch quantities
5. Changes batch status to 'depleted' when quantity reaches 0

## Permissions

### Batch Permissions
- `view batches` - View batch information
- `manage batches` - Update batch information, handle recalls

### Required for Inventory
- `manage inventory` - Create purchases/sales (existing permission)

## Models

### ProductBatch Model

#### Relationships
```php
$batch->business()
$batch->branch()
$batch->product()
$batch->inventoryTransaction() // Original purchase
$batch->transactions() // All related transactions
```

#### Scopes
```php
ProductBatch::active()           // Active batches with stock
ProductBatch::expired()          // Expired batches
ProductBatch::nearExpiry(30)     // Expiring within 30 days
ProductBatch::orderByFEFO()      // Order by FEFO logic
```

#### Helper Methods
```php
$batch->isExpired()              // Check if expired
$batch->isNearExpiry(30)         // Check if near expiry
$batch->daysUntilExpiry()        // Days until expiry (null if no expiry)
$batch->canAllocate(qty)         // Check if can allocate quantity
$batch->allocate(qty)            // Allocate quantity
$batch->increaseQuantity(qty)    // Increase quantity (returns)
```

#### Static Methods
```php
ProductBatch::generateBatchNumber()
ProductBatch::findBatchesToAllocate($productId, $branchId, $quantity)
```

### InventoryTransaction Model
- Added `batch_id` to fillable fields
- New `batch()` relationship

## Workflow Examples

### 1. Purchase Product with Batch Info
```
POST /api/inventory/transactions
{
  "type": "purchase",
  "product_id": 1,
  "branch_id": 2,
  "quantity": 50,
  "unit_cost": 10.00,
  "expiry_date": "2025-06-30",
  "lot_number": "LOT-ABC-123",
  "supplier_name": "ABC Corp"
}
```
**Result:** 
- Inventory transaction created
- ProductBatch created with 50 units
- Stock updated

### 2. Sell Product (FEFO Automatic)
```
POST /api/inventory/transactions
{
  "type": "sale",
  "product_id": 1,
  "branch_id": 2,
  "quantity": 30
}
```
**Result:**
- Sale transaction created
- System finds batches using FEFO
- Allocates from batches closest to expiry
- Creates batch allocation records
- Updates batch quantities
- Stock updated

### 3. Monitor Near-Expiry Products
```
GET /api/batches/near-expiry?days=14
```
**Result:** List of products expiring in next 14 days

### 4. Handle Batch Recall
```
PATCH /api/batches/{id}
{
  "status": "recalled",
  "notes": "Supplier recall notice #12345"
}
```
**Result:** Batch marked as recalled, prevents further allocation

## Business Rules

1. **Purchases**: Always create a batch record
2. **Sales**: Always allocate from existing batches using FEFO
3. **Transfers**: Batch tracking preserved across branches
4. **Adjustments**: Can be linked to specific batches
5. **Expired Batches**: Cannot be allocated for sales
6. **Depleted Batches**: Status automatically updated when quantity reaches 0
7. **Batch Numbers**: Auto-generated if not provided: `BATCH-YYYYMMDD-XXXXXX`

## Status Lifecycle

```
active → depleted (when current_quantity = 0)
active → expired (when expiry_date < today)
active → recalled (manual action)
```

## Reporting & Analytics

### Available Metrics
- Total batches by status
- Near-expiry inventory value
- Expired inventory value
- Batch allocation history
- FEFO compliance tracking
- Supplier batch quality tracking

### Sample Queries

**Near-expiry value by branch:**
```php
$value = ProductBatch::forBranch($branchId)
    ->nearExpiry(30)
    ->get()
    ->sum(fn($batch) => $batch->current_quantity * $batch->unit_cost);
```

**Expired inventory:**
```php
$expired = ProductBatch::expired()
    ->where('current_quantity', '>', 0)
    ->with('product', 'branch')
    ->get();
```

## Benefits

1. **Compliance**: Meet regulatory requirements for batch tracking
2. **Quality Control**: Track supplier batches for quality issues
3. **Waste Reduction**: FEFO minimizes expiry-related waste
4. **Traceability**: Full audit trail from purchase to sale
5. **Financial Accuracy**: Accurate COGS using batch-specific costs
6. **Recall Management**: Quick identification and removal of affected batches

## Future Enhancements

Potential improvements:
- Scheduled notifications for near-expiry items
- Batch transfer between branches
- Batch consolidation
- Barcode/QR code generation for batches
- Advanced analytics dashboard
- Automated recall workflows
- Integration with supplier systems
