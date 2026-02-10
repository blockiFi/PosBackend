# Database Seeder Quick Reference

## 🚀 Quick Commands

```bash
# Fresh start (recommended)
php artisan migrate:fresh --seed

# Run all seeders only
php artisan db:seed

# Run specific seeder
php artisan db:seed --class=SalesSeeder

# Run with specific count
php artisan db:seed --class=SalesSeeder
```

## 📊 Default Data Generated

| Entity | Count | Notes |
|--------|-------|-------|
| **Businesses** | 2 | 1 Retail, 1 Wholesale |
| **Branches** | 4 | 2-3 per business |
| **Users** | 8+ | Admin, managers, cashiers |
| **Product Categories** | 30+ | Hierarchical structure |
| **Products** | 50+ | Across all categories |
| **Batches** | 100+ | With expiry tracking |
| **Customers** | 50+ | Per business |
| **Sales** | 1,000 | Over 60 days |
| **Sale Items** | 3,000+ | 1-8 items per sale |
| **Shifts** | 180+ | 1-3 per branch per day |
| **Refund Requests** | 20+ | Mixed statuses |
| **Quick Sales** | 15+ | Near-expiry discounts |
| **Stock Transfers** | 30+ | Inter-branch transfers |

## ⚙️ Configuration

### Scale Up/Down Data Volume

**File:** `database/seeders/SalesSeeder.php`
```php
private int $salesCount = 1000; // Change this value
```

### Preset Configurations

**Small (Development)**
```php
$salesCount = 100;      // 100 sales
```

**Medium (Testing)** - Default
```php
$salesCount = 1000;     // 1,000 sales
```

**Large (Demo)**
```php
$salesCount = 5000;     // 5,000 sales
```

**Extra Large (Load Testing)**
```php
$salesCount = 50000;    // 50,000 sales
```

## 🔑 Demo Credentials

```
Email: admin@acmeretail.com
Password: password

Email: john.manager@acmeretail.com
Password: password

Email: cashier1@acmeretail.com
Password: password
```

## 📁 Seeder Files

```
database/seeders/
├── DatabaseSeeder.php          # Master orchestrator
├── BusinessSeeder.php          # Businesses & branches
├── ProductSeeder.php           # Products & categories
├── InventorySeeder.php         # Batches & transactions
├── SalesSeeder.php             # Sales & shifts
└── WorkflowSeeder.php          # Refunds, quick sales, transfers
```

## 🏭 Factory Files

```
database/factories/
├── BusinessFactory.php
├── BranchFactory.php
├── UserFactory.php
├── ProductCategoryFactory.php
├── ProductFactory.php
├── BranchProductFactory.php
├── CustomerFactory.php
├── PaymentMethodFactory.php
├── SalesShiftFactory.php
├── SaleFactory.php
├── SaleItemFactory.php
├── PaymentFactory.php
├── ProductBatchFactory.php
├── InventoryTransactionFactory.php
├── RefundRequestFactory.php
├── QuickSaleFactory.php
├── StockTransferRequestFactory.php
├── DeviceRegistrationFactory.php
└── SyncSessionFactory.php
```

## ⏱️ Execution Times

| Data Volume | Estimated Time | Memory Usage |
|-------------|---------------|--------------|
| 100 sales | ~10 seconds | ~128 MB |
| 1,000 sales | ~30 seconds | ~256 MB |
| 5,000 sales | ~2 minutes | ~512 MB |
| 50,000 sales | ~15 minutes | ~1 GB |

*Times are approximate and depend on system specifications*

## 🔄 Execution Order

```
1. Permissions & Roles
   ├── CategoryPermissionSeeder
   ├── ProductPermissionSeeder
   ├── InventoryPermissionSeeder
   ├── BatchPermissionSeeder
   ├── SalesPermissionSeeder
   ├── ShiftPermissionSeeder
   ├── QuickSalePermissionSeeder
   ├── RefundPermissionSeeder
   ├── AdjustInventoryPermissionSeeder
   ├── AnalyticsPermissionSeeder
   └── RolePermissionSeeder

2. BusinessSeeder
   └── Creates businesses, branches, users

3. ProductSeeder
   └── Creates categories, products, branch products

4. InventorySeeder
   └── Creates batches, inventory transactions

5. SalesSeeder
   └── Creates shifts, sales, sale items, payments

6. WorkflowSeeder
   └── Creates refunds, quick sales, transfers
```

## 🎯 Use Cases

### Development
```bash
# Quick setup with minimal data
# Edit SalesSeeder: $salesCount = 50
php artisan migrate:fresh --seed
```

### Testing
```bash
# Default setup - good for feature testing
php artisan migrate:fresh --seed
```

### Demo/Presentation
```bash
# Rich dataset for demonstrations
# Edit SalesSeeder: $salesCount = 5000
php artisan migrate:fresh --seed
```

### Performance Testing
```bash
# Large dataset
# Edit SalesSeeder: $salesCount = 50000
php -d memory_limit=1G artisan migrate:fresh --seed
```

## 🛠️ Customization Examples

### Add More Products
**File:** `database/seeders/ProductSeeder.php`

```php
private function createProducts(Business $business, array $categories): void
{
    // Add your custom products here
    $customProducts = [
        ['name' => 'Your Product', 'sku' => 'SKU', 'cost' => 10.00, 'price' => 15.99],
    ];
    
    foreach ($customProducts as $productData) {
        $product = Product::create([...]);
        $this->addProductToBranches($product, $branches);
    }
}
```

### Create Custom Seeder
```bash
php artisan make:seeder YourCustomSeeder
```

```php
class YourCustomSeeder extends Seeder
{
    public function run(): void
    {
        // Your custom seeding logic
    }
}
```

Add to `DatabaseSeeder.php`:
```php
$this->call(YourCustomSeeder::class);
```

## 🐛 Troubleshooting

### Foreign Key Errors
```bash
php artisan migrate:fresh --seed
```

### Out of Memory
```bash
php -d memory_limit=512M artisan db:seed
```

### Unique Constraint Violations
```bash
# Fresh start
php artisan migrate:fresh --seed

# Or manually truncate tables
php artisan tinker
>> DB::table('sales')->truncate();
```

### Slow Performance
```php
// Add to seeder
DB::connection()->disableQueryLog();
Model::unsetEventDispatcher();
```

## 📝 Verification

```bash
php artisan tinker
```

```php
// Check record counts
\App\Models\Business::count();
\App\Models\Product::count();
\App\Models\Sale::count();

// Verify a business has data
$business = \App\Models\Business::first();
$business->products()->count();
$business->sales()->count();

// Check sales totals
\App\Models\Sale::sum('total_amount');

// Find sales with items
\App\Models\Sale::has('items')->count();
```

## 💡 Pro Tips

1. **Always start fresh** for consistent results:
   ```bash
   php artisan migrate:fresh --seed
   ```

2. **Use specific seeders** during development:
   ```bash
   php artisan db:seed --class=ProductSeeder
   ```

3. **Adjust volume** based on your needs - don't over-seed for development

4. **Monitor memory** for large datasets:
   ```bash
   php -d memory_limit=1G artisan db:seed
   ```

5. **Backup before seeding** production-like data:
   ```bash
   php artisan backup:run
   ```

## 📚 Additional Resources

- Full documentation: `DATABASE_SEEDER_DOCUMENTATION.md`
- Laravel Seeding: https://laravel.com/docs/seeding
- Factory Documentation: https://laravel.com/docs/eloquent-factories

---

**Need Help?** Check the full documentation or run:
```bash
php artisan db:seed --help
```
