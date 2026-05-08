<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * This is the master seeder that orchestrates all other seeders
     * in the correct order to ensure referential integrity.
     *
     * Execution order:
     * 1. Permissions & Roles (foundation for access control)
     * 2. Businesses & Branches (core business structure)
     * 3. Products & Categories (catalog setup)
     * 4. Inventory & Batches (stock management)
     * 5. Sales & Shifts (transaction data)
     * 6. Workflows (refunds, quick sales, transfers)
     *
     * Usage:
     *   php artisan db:seed                    # Run all seeders
     *   php artisan db:seed --class=SalesSeeder  # Run specific seeder
     *
     * To scale data volume:
     *   Update the $salesCount property in SalesSeeder
     *   Adjust product count in ProductSeeder::createProducts()
     */
    public function run(): void
    {
        $size = config('seeding.size', 'large');
        $this->command->info('🌱 Starting database seeding...');
        $this->command->info("   Mode: {$size}");
        $this->command->newLine();

        // Step 1: Permissions & Roles
        $this->command->info('📋 Step 1/6: Seeding permissions and roles...');
        $this->call([
            CategoryPermissionSeeder::class,
            ProductPermissionSeeder::class,
            InventoryPermissionSeeder::class,
            BatchPermissionSeeder::class,
            GoodsReceivingPermissionSeeder::class,
            PurchaseOrderPermissionSeeder::class,
            SalesPermissionSeeder::class,
            ShiftPermissionSeeder::class,
            DeviceGroupPermissionSeeder::class,
            QuickSalePermissionSeeder::class,
            RefundPermissionSeeder::class,
            ShelfStoreMovePermissionSeeder::class,
            AdjustInventoryPermissionSeeder::class,
            AnalyticsPermissionSeeder::class,
            BranchSyncPermissionSeeder::class,
            RolePermissionSeeder::class,
            RealisticRoleSeeder::class,  // Realistic role templates
        ]);
        $this->command->info('✅ Permissions and roles seeded successfully!');
        $this->command->newLine();

        // Step 2: Businesses & Branches
        $this->command->info('🏢 Step 2/6: Seeding businesses and branches...');
        $this->call(BusinessSeeder::class);
        $this->call(BranchAuthorizationSeeder::class);
        $this->command->info('✅ Businesses and branches seeded successfully!');
        $this->command->newLine();

        // Step 3: Products & Categories
        $this->command->info('📦 Step 3/6: Seeding products and categories...');
        $this->call(ProductSeeder::class);
        $this->call(ProductTierPriceSeeder::class);
        $this->command->info('✅ Products and categories seeded successfully!');
        $this->command->newLine();

        // Step 4: Inventory & Batches
        $this->command->info('📊 Step 4/6: Seeding inventory and batches...');
        $this->call(InventorySeeder::class);
        $this->command->info('✅ Inventory and batches seeded successfully!');
        $this->command->newLine();

        // Step 5: Sales & Shifts
        $this->command->info('💰 Step 5/6: Seeding sales and shifts...');
        $this->call(SalesSeeder::class);
        $this->command->info('✅ Sales and shifts seeded successfully!');
        $this->command->newLine();

        // Step 6: Workflows (Refunds, Quick Sales, Transfers)
        $this->command->info('🔄 Step 6/6: Seeding workflow requests...');
        $this->call(WorkflowSeeder::class);
        $this->command->info('✅ Workflow requests seeded successfully!');
        $this->command->newLine();

        // Summary
        $this->command->info('🎉 Database seeding completed successfully!');
        $this->command->newLine();
        $this->printSummary();
    }

    /**
     * Print a summary of seeded data
     */
    private function printSummary(): void
    {
        $this->command->info('📊 Seeding Summary:');
        $this->command->table(
            ['Entity', 'Count'],
            [
                ['Businesses', \App\Models\Business::count()],
                ['Branches', \App\Models\Branch::count()],
                ['Users', \App\Models\User::count()],
                ['Roles', \Spatie\Permission\Models\Role::count()],
                ['Permissions', \Spatie\Permission\Models\Permission::count()],
                ['Product Categories', \App\Models\ProductCategory::count()],
                ['Products', \App\Models\Product::count()],
                ['Product Units', \App\Models\ProductUnit::count()],
                ['Branch Products', \App\Models\BranchProduct::count()],
                ['Branch Product Unit Prices', \App\Models\BranchProductUnitPrice::count()],
                ['Branch Product Quantity Tiers', \App\Models\BranchProductQuantityTier::count()],
                ['Product Batches', \App\Models\ProductBatch::count()],
                ['Inventory Transactions', \App\Models\InventoryTransaction::count()],
                ['Customers', \App\Models\Customer::count()],
                ['Payment Methods', \App\Models\PaymentMethod::count()],
                ['Sales Shifts', \App\Models\SalesShift::count()],
                ['Sales', \App\Models\Sale::count()],
                ['Sale Items', \App\Models\SaleItem::count()],
                ['Payments', \App\Models\Payment::count()],
                ['Refund Requests', \App\Models\RefundRequest::count()],
                ['Quick Sales', \App\Models\QuickSale::count()],
                ['Stock Transfers', \App\Models\StockTransferRequest::count()],
                ['Stock Write-offs', \App\Models\StockWriteoff::count()],
                ['Branch Authorizations', \App\Models\BranchAuthorization::count()],
            ]
        );
        $this->command->newLine();

        $this->command->info('🔑 Demo Users:');
        $this->command->line('  Email: admin@acmeretail.com | Password: password');
        $this->command->line('  Email: john.manager@acmeretail.com | Password: password');
        $this->command->line('  Email: cashier1@acmeretail.com | Password: password | PIN: 123456 (pin-login)');
        $this->command->newLine();

        $this->command->info('💡 Tips:');
        $this->command->line('  • Small data (fast): SEED_SIZE=small php artisan db:seed');
        $this->command->line('  • Or: php artisan seed:run --size=small');
        $this->command->line('  • Large data (full): php artisan db:seed or php artisan seed:run --size=large');
        $this->command->line('  • Run "php artisan migrate:fresh --seed" to reset and reseed');
    }
}
