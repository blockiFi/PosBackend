# Database Seeder Documentation

## Overview

This document describes the comprehensive database seeder system for the POS Backend application. The seeder generates realistic, large-scale test data with proper relationships and business logic.

## Quick Start

### Basic Usage

```bash
# Seed all data (recommended for first-time setup)
php artisan migrate:fresh --seed

# Run specific seeder
php artisan db:seed --class=SalesSeeder

# Re-run all seeders (without migration)
php artisan db:seed
```

### Scaling Data Volume

To adjust the amount of seeded data, modify these constants:

**SalesSeeder.php**
```php
private int $salesCount = 1000; // Change to 5000 for more sales
```

**ProductSeeder.php**
```php
// Modify the arrays in createProducts() to add more products
$electronicsProducts = [...]; // Add more items here
```

## Seeder Execution Order

The `DatabaseSeeder` orchestrates all seeders in the following order to maintain referential integrity:

```
1. Permissions & Roles         → Foundation for access control
2. Businesses & Branches        → Core business structure
3. Products & Categories        → Product catalog
4. Inventory & Batches          → Stock management
5. Sales & Shifts              → Transaction data
6. Workflows                    → Refunds, quick sales, transfers
```

## Available Seeders

### 1. BusinessSeeder

**Purpose:** Creates demo businesses with branches and users

**What it creates:**
- 2 demo businesses (1 retail, 1 wholesale)
- 2-3 branches per business
- Admin, managers, and cashier users with proper permissions

**Key Features:**
- Realistic business settings (tax rates, currency, timezone)
- Branch hierarchies with main/secondary branches
- User-business-branch relationships

**Demo Credentials:**
```
Admin: admin@acmeretail.com / password
Manager: john.manager@acmeretail.com / password
Cashier: cashier1@acmeretail.com / password
```

**Customize:**
```php
// Add more businesses in the run() method
$this->createCustomBusiness('Your Business Name');
```

---

### 2. ProductSeeder

**Purpose:** Creates product catalog with categories and inventory

**What it creates:**
- 15+ product categories (hierarchical)
- 50+ products across all categories
- Branch product assignments with stock levels

**Categories Created:**
- Electronics (Mobile Phones, Laptops, Accessories)
- Groceries (Dairy, Beverages, Snacks)
- Household Items (Cleaning Supplies)
- Personal Care
- Office Supplies

**Product Distribution:**
- Electronics: ~8 products (high-value items)
- Groceries: ~8 products (perishable items)
- Beverages: ~5 products (batch tracking)
- Household: ~5 products
- Personal Care: ~4 products
- Office Supplies: ~4 products

**Stock Levels:**
- Shelf quantity: 10-100 units per branch
- Store quantity: 50-300 units per branch
- Automatic BranchProduct creation for all branches

**Customize:**
```php
// In ProductSeeder::createProducts(), add more product arrays:
$yourProducts = [
    ['name' => 'Product Name', 'sku' => 'SKU', 'cost' => 10.00, 'price' => 15.99],
    // Add more...
];

foreach ($yourProducts as $productData) {
    // Product creation logic...
}
```

---

### 3. InventorySeeder

**Purpose:** Creates inventory transactions and batch records

**What it creates:**
- Product batches with expiry dates (for batch-tracked items)
- 2-5 batches per batch-tracked product
- Inventory transactions (purchases, sales, adjustments)
- Stock movement history

**Batch Features:**
- Manufacturing dates (30-180 days ago)
- Expiry dates (ranging from expired to 2 years ahead)
- Status tracking (active, near_expiry, expired, sold_out)
- FEFO (First Expiry First Out) support

**Transaction Types:**
- `purchase`: Initial stock and restocking
- `sale`: Stock sold to customers
- `adjustment`: Stock count corrections
- `transfer_in/out`: Inter-branch transfers
- `writeoff`: Damaged/expired stock

**Customize:**
```php
// Adjust batch count per product
$batchCount = fake()->numberBetween(2, 10); // Default is 2-5

// Adjust initial stock quantities
$initialQty = fake()->numberBetween(100, 1000); // Default is 50-500
```

---

### 4. SalesSeeder

**Purpose:** Generates realistic sales transactions with shifts

**What it creates:**
- 1,000 sales by default (configurable)
- Sales distributed over 60 days
- 1-3 shifts per branch per day
- Multi-item sales with realistic totals
- Split payment support (10% of sales)

**Sales Features:**
- **Items per sale:** 1-8 products
- **Customer assignment:** 60% of sales have customers
- **Discounts:** 20% of items have discounts (5-20%)
- **Tax calculation:** Automatic based on product settings
- **Payment methods:** Cash (60%), Card (30%), Other (10%)
- **Split payments:** 10% of sales use multiple payment methods

**Shift Features:**
- Opening balance: $500
- Automatic shift closure for past dates
- Cash reconciliation with small variance (-$20 to +$20)
- Payment method breakdown (cash/card/other)

**Performance:**
- Generates ~16-17 sales per day per business
- Realistic distribution across operating hours
- Random days skipped (10% chance)

**Customize:**
```php
// Adjust total sales count
private int $salesCount = 5000; // Generate 5000 sales

// Adjust sales per day
$salesPerDay = (int) ceil($this->salesCount / 90); // Spread over 90 days

// Adjust items per sale
$itemCount = fake()->numberBetween(1, 15); // More items per sale
```

---

### 5. WorkflowSeeder

**Purpose:** Creates approval workflow requests

**What it creates:**
- 10-20 refund requests per business
- 10-15 quick sale requests (near-expiry discounts)
- 15-30 stock transfer requests

**Refund Requests:**
- Status distribution: 50% pending, 30% approved, 20% rejected
- Linked to actual sales
- Realistic refund reasons

**Quick Sales:**
- Targets near-expiry or expired batches
- 20-50% discount range
- Status: pending, approved, rejected, ended

**Stock Transfers:**
- Inter-branch transfers only (requires 2+ branches)
- Status: pending, approved, in_transit, completed, rejected
- Quantity: 10-50 units per transfer

**Customize:**
```php
// Adjust request counts
$refundCount = fake()->numberBetween(20, 40); // More refunds
$transferCount = fake()->numberBetween(30, 50); // More transfers
```

---

## Factory Definitions

All models have corresponding factories for flexible data generation:

### Core Factories

| Factory | Purpose | States |
|---------|---------|--------|
| `BusinessFactory` | Business organizations | - |
| `BranchFactory` | Branch locations | - |
| `UserFactory` | System users | - |
| `ProductCategoryFactory` | Product categories | - |
| `ProductFactory` | Products | - |
| `BranchProductFactory` | Branch inventory | `needsRestocking()` |
| `CustomerFactory` | Customers | `vip()`, `wholesale()`, `inactive()` |

### Transaction Factories

| Factory | Purpose | States |
|---------|---------|--------|
| `SalesShiftFactory` | Cashier shifts | `open()`, `closed()`, `withDiscrepancy()` |
| `SaleFactory` | Sales | `pending()`, `partiallyPaid()`, `refunded()` |
| `SaleItemFactory` | Sale line items | `noDiscount()`, `withDiscount()` |
| `PaymentFactory` | Payments | `pending()`, `failed()`, `cash()`, `withReference()` |
| `PaymentMethodFactory` | Payment methods | `cash()`, `card()`, `mobileMoney()` |

### Inventory Factories

| Factory | Purpose | States |
|---------|---------|--------|
| `ProductBatchFactory` | Product batches | `nearExpiry()`, `expired()`, `soldOut()` |
| `InventoryTransactionFactory` | Stock movements | `purchase()`, `sale()`, `adjustment()`, `transfer()`, `writeoff()` |

### Workflow Factories

| Factory | Purpose | States |
|---------|---------|--------|
| `RefundRequestFactory` | Refund requests | `pending()`, `approved()`, `rejected()` |
| `QuickSaleFactory` | Quick sale requests | `pending()`, `approved()`, `rejected()` |
| `StockTransferRequestFactory` | Transfer requests | `pending()`, `approved()`, `completed()` |

### Sync Factories

| Factory | Purpose | States |
|---------|---------|--------|
| `DeviceRegistrationFactory` | POS devices | `active()`, `inactive()`, `blocked()` |
| `SyncSessionFactory` | Sync sessions | `initiated()`, `inProgress()`, `failed()`, `withConflicts()` |

## Usage Examples

### Generate Custom Data

```php
use App\Models\Product;
use App\Models\Sale;
use App\Models\Business;

// Create products with specific attributes
$business = Business::first();

Product::factory()
    ->count(10)
    ->for($business)
    ->create([
        'is_taxable' => true,
        'default_tax_rate' => 15,
    ]);

// Create sales with refunds
Sale::factory()
    ->count(50)
    ->refunded()
    ->for($business)
    ->create();

// Create VIP customers
Customer::factory()
    ->count(20)
    ->vip()
    ->for($business)
    ->create();
```

### Extend Seeders

```php
// Create a custom seeder
php artisan make:seeder CustomProductSeeder

// In CustomProductSeeder.php
public function run(): void
{
    $business = Business::first();
    
    Product::factory()
        ->count(100)
        ->for($business)
        ->create();
}

// Call it from DatabaseSeeder
$this->call(CustomProductSeeder::class);
```

## Data Volume Recommendations

### Development Environment
```php
SalesSeeder::$salesCount = 100;
ProductSeeder: ~30 products
Minimal workflows
```

### Testing Environment
```php
SalesSeeder::$salesCount = 1000; // Default
ProductSeeder: ~50 products
Full workflows
```

### Demo Environment
```php
SalesSeeder::$salesCount = 5000;
ProductSeeder: ~100 products
Extensive workflows
```

### Load Testing
```php
SalesSeeder::$salesCount = 50000;
ProductSeeder: ~500 products
Maximum workflows
```

## Performance Tips

### Optimize Seeding Speed

```bash
# Disable query logging during seeding
DB::connection()->disableQueryLog();

# Use chunking for large datasets
Sale::factory()->count(10000)->create()->chunk(1000);

# Disable model events temporarily
Model::unsetEventDispatcher();
```

### Memory Management

```php
// In large seeders, periodically clear memory
if ($i % 1000 === 0) {
    gc_collect_cycles();
}
```

## Troubleshooting

### Common Issues

**Foreign Key Constraint Errors:**
```bash
# Ensure seeders run in correct order
php artisan migrate:fresh --seed
```

**Out of Memory:**
```bash
# Increase PHP memory limit
php -d memory_limit=512M artisan db:seed
```

**Duplicate Entry Errors:**
```bash
# Reset database completely
php artisan migrate:fresh --seed
```

**Missing Relationships:**
```
# Check seeder order in DatabaseSeeder::run()
# Ensure parent records exist before children
```

## Advanced Customization

### Create Business-Specific Seeders

```php
class RestaurantSeeder extends Seeder
{
    public function run(): void
    {
        $restaurant = Business::create([
            'name' => 'Pizza Palace',
            'business_type' => 'restaurant',
            // ...
        ]);
        
        // Create menu categories
        $pizzas = ProductCategory::create([
            'business_id' => $restaurant->id,
            'name' => 'Pizzas',
        ]);
        
        // Create menu items
        Product::factory()->count(20)->create([
            'business_id' => $restaurant->id,
            'category_id' => $pizzas->id,
        ]);
    }
}
```

### Conditional Seeding

```php
// Only seed if environment is not production
if (!app()->environment('production')) {
    $this->call(DemoDataSeeder::class);
}

// Seed based on config
if (config('app.seed_demo_data')) {
    $this->call(SalesSeeder::class);
}
```

## Data Integrity Checks

After seeding, verify data integrity:

```bash
# Check referential integrity
php artisan tinker

# Verify sales totals match
Sale::all()->each(function($sale) {
    $itemsTotal = $sale->items->sum('total');
    $saleTotal = $sale->total_amount;
    if (abs($itemsTotal - $saleTotal) > 0.01) {
        echo "Sale {$sale->id} has mismatched totals\n";
    }
});

# Verify inventory quantities
Product::all()->each(function($product) {
    foreach($product->branches as $branch) {
        $branchProduct = $product->branchProducts()->where('branch_id', $branch->id)->first();
        $expected = $branchProduct->shelf_quantity + $branchProduct->store_quantity;
        if ($expected != $branchProduct->stock_quantity) {
            echo "Product {$product->id} at Branch {$branch->id} has mismatched quantities\n";
        }
    }
});
```

## Reset & Reseed

```bash
# Complete reset with fresh migration and seeding
php artisan migrate:fresh --seed

# Drop all tables and reseed (faster)
php artisan db:wipe
php artisan migrate --seed

# Reseed without migration (preserves schema)
php artisan db:seed --force
```

## Conclusion

This comprehensive seeder system provides:
- ✅ Realistic business data with proper relationships
- ✅ Scalable data volume (100 to 100,000+ records)
- ✅ Referential integrity maintained
- ✅ Business logic compliance (FEFO, shifts, approvals)
- ✅ Easy customization and extension
- ✅ Performance optimized for large datasets

For questions or issues, refer to the Laravel Seeding documentation or contact the development team.
