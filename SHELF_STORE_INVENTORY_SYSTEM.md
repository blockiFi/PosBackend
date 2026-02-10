# Shelf and Store Inventory System

## Overview

The inventory system has been redesigned to differentiate between items on the **shelf** (available for immediate sale) and items in the **store/warehouse** (backstock).

## Database Changes

### BranchProduct Model
New fields added:
- `shelf_quantity` - Items currently on the shelf (available for sale)
- `store_quantity` - Items in backstock/warehouse
- `stock_quantity` - Total quantity (shelf + store) - automatically calculated

### InventoryTransaction Model
New fields added:
- `shelf_quantity` - Quantity change for shelf
- `store_quantity` - Quantity change for store
- `shelf_quantity_before` - Shelf quantity before transaction
- `store_quantity_before` - Store quantity before transaction
- `shelf_quantity_after` - Shelf quantity after transaction
- `store_quantity_after` - Store quantity after transaction

## API Endpoints

### Branch Products

#### 1. Create Branch Product
**POST** `/api/branch-products`

```json
{
  "branch_id": 1,
  "product_id": 5,
  "shelf_quantity": 50,
  "store_quantity": 100,
  "selling_price": 29.99,
  "cost_price": 15.00
}
```

Or use `stock_quantity` (defaults to shelf):
```json
{
  "branch_id": 1,
  "product_id": 5,
  "stock_quantity": 150,  // All goes to shelf by default
  "selling_price": 29.99
}
```

#### 2. Update Branch Product
**PUT** `/api/branch-products/{id}`

```json
{
  "shelf_quantity": 75,
  "store_quantity": 125
}
```

Total stock is automatically recalculated when shelf or store quantities are updated.

#### 3. Move Stock to Shelf
**POST** `/api/branch-products/{id}/move-to-shelf`

Moves items from store to shelf (restocking).

```json
{
  "quantity": 25
}
```

Response:
```json
{
  "message": "Stock moved to shelf successfully",
  "data": {
    "quantity_moved": 25,
    "previous_shelf_quantity": 50,
    "new_shelf_quantity": 75,
    "previous_store_quantity": 100,
    "new_store_quantity": 75,
    "branch_product": { ... }
  }
}
```

#### 4. Move Stock to Store
**POST** `/api/branch-products/{id}/move-to-store`

Moves items from shelf to store (returning to backstock).

```json
{
  "quantity": 15
}
```

#### 5. List Branch Products
**GET** `/api/branch-products?branch_id=1`

Response includes:
```json
{
  "data": [{
    "id": 1,
    "inventory": {
      "stock_quantity": 150,
      "shelf_quantity": 50,
      "store_quantity": 100,
      "is_in_stock": true,
      "is_low_stock": false,
      "shelf_needs_restocking": false
    }
  }]
}
```

### Inventory Transactions

#### Create Inventory Transaction
**POST** `/api/inventory/transactions`

##### Option 1: Specify Location
```json
{
  "branch_id": 1,
  "product_id": 5,
  "type": "purchase",
  "quantity": 100,
  "location": "shelf",  // Options: "shelf", "store", "both"
  "unit_cost": 15.00,
  "reference_number": "PO-2024-001"
}
```

##### Option 2: Explicit Shelf and Store Quantities
```json
{
  "branch_id": 1,
  "product_id": 5,
  "type": "purchase",
  "quantity": 150,  // Total
  "shelf_quantity": 50,  // 50 to shelf
  "store_quantity": 100, // 100 to store
  "unit_cost": 15.00
}
```

##### Option 3: Split with Location "both"
```json
{
  "branch_id": 1,
  "product_id": 5,
  "type": "purchase",
  "quantity": 150,
  "location": "both",
  "shelf_quantity": 50,  // Rest goes to store (100)
  "unit_cost": 15.00
}
```

## Transaction Types and Behavior

### Purchase / Initial / Return
- **Adds** stock to branch
- Can specify where to add: shelf, store, or both
- Default: adds to shelf

### Sale
- **Removes** stock from shelf (sales always come from shelf)
- If insufficient shelf stock, transaction fails
- Store stock is not affected directly

### Adjustment
- Can adjust shelf, store, or both
- Positive or negative quantities
- Specify location explicitly

### Damage
- Removes stock from specified location
- Can be from shelf or store

### Transfer Out/In
- Transfers between branches
- Can specify which location the stock comes from/goes to

## BranchProduct Model Methods

### New Methods

```php
// Update shelf quantity
$branchProduct->updateShelfQuantity(50, 'add');
$branchProduct->updateShelfQuantity(10, 'subtract');
$branchProduct->updateShelfQuantity(25, 'set');

// Update store quantity
$branchProduct->updateStoreQuantity(100, 'add');
$branchProduct->updateStoreQuantity(20, 'subtract');
$branchProduct->updateStoreQuantity(80, 'set');

// Move stock between locations
$branchProduct->moveToShelf(25);  // Move 25 from store to shelf
$branchProduct->moveToStore(15);  // Move 15 from shelf to store

// Get total stock
$total = $branchProduct->getTotalStockQuantity(); // shelf + store

// Check if shelf needs restocking
if ($branchProduct->shelfNeedsRestocking()) {
    // Shelf is low but store has stock
}
```

## Use Cases

### Scenario 1: Receiving New Stock
When receiving a shipment, you can put some items directly on the shelf and rest in storage:

```json
POST /api/inventory/transactions
{
  "type": "purchase",
  "quantity": 200,
  "shelf_quantity": 50,
  "store_quantity": 150,
  "reference_number": "PO-123"
}
```

### Scenario 2: Restocking Shelf
When shelf is running low, move items from store to shelf:

```json
POST /api/branch-products/5/move-to-shelf
{
  "quantity": 30
}
```

### Scenario 3: Daily Sales
Sales automatically deduct from shelf:

```json
POST /api/inventory/transactions
{
  "type": "sale",
  "quantity": 5,
  "location": "shelf"  // Always from shelf
}
```

### Scenario 4: Checking Inventory
Get complete inventory status:

```json
GET /api/branch-products?branch_id=1

Response:
{
  "inventory": {
    "stock_quantity": 175,      // Total
    "shelf_quantity": 45,        // On display
    "store_quantity": 130,       // In backstock
    "shelf_needs_restocking": true,
    "is_low_stock": false
  }
}
```

## Best Practices

1. **Always specify location** when adding inventory to avoid confusion
2. **Use moveToShelf** for regular restocking operations instead of manual adjustments
3. **Monitor shelf_needs_restocking** flag to know when to replenish the shelf
4. **Keep safety stock** in store for quick restocking
5. **Sales should only come from shelf** - if shelf is empty, restock first

## Validation Rules

- Shelf quantity cannot be negative
- Store quantity cannot be negative
- Total stock = shelf + store (automatically maintained)
- Moving stock validates source has enough quantity
- Sales require sufficient shelf stock

## Migration Notes

- Existing `stock_quantity` values are migrated to `shelf_quantity`
- `store_quantity` starts at 0 for existing records
- `stock_quantity` is now a calculated field (shelf + store)
