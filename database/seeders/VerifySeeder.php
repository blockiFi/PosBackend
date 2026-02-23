<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BranchAuthorization;
use App\Models\Business;
use App\Models\Customer;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SalesShift;
use App\Models\StockWriteoff;
use Illuminate\Database\Seeder;

/**
 * Verification seeder to check data integrity after seeding
 *
 * Run with: php artisan db:seed --class=VerifySeeder
 */
class VerifySeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🔍 Verifying seeded data...');
        $this->command->newLine();

        $errors = [];
        $warnings = [];

        // Check businesses
        $businessCount = Business::count();
        if ($businessCount === 0) {
            $errors[] = 'No businesses found';
        } else {
            $this->command->info("✅ Found {$businessCount} businesses");
        }

        // Check branches
        $branchCount = Branch::count();
        if ($branchCount === 0) {
            $errors[] = 'No branches found';
        } else {
            $this->command->info("✅ Found {$branchCount} branches");
        }

        // Check products
        $productCount = Product::count();
        if ($productCount === 0) {
            $errors[] = 'No products found';
        } else {
            $this->command->info("✅ Found {$productCount} products");
        }

        // Check sales
        $saleCount = Sale::count();
        if ($saleCount === 0) {
            $warnings[] = 'No sales found - did you run SalesSeeder?';
        } else {
            $this->command->info("✅ Found {$saleCount} sales");
        }

        // Verify sales have items
        $salesWithoutItems = Sale::doesntHave('items')->count();
        if ($salesWithoutItems > 0) {
            $errors[] = "{$salesWithoutItems} sales have no items";
        } else {
            $this->command->info('✅ All sales have items');
        }

        // Verify sales have payments
        $salesWithoutPayments = Sale::doesntHave('payments')->count();
        if ($salesWithoutPayments > 0) {
            $errors[] = "{$salesWithoutPayments} sales have no payments";
        } else {
            $this->command->info('✅ All sales have payments');
        }

        // Check shifts
        $shiftCount = SalesShift::count();
        if ($shiftCount === 0) {
            $warnings[] = 'No shifts found - did you run SalesSeeder?';
        } else {
            $this->command->info("✅ Found {$shiftCount} shifts");
        }

        // Verify shifts have sales
        $shiftsWithoutSales = SalesShift::doesntHave('sales')->count();
        if ($shiftsWithoutSales > 0) {
            $warnings[] = "{$shiftsWithoutSales} shifts have no sales";
        }

        // Check customers
        $customerCount = Customer::count();
        if ($customerCount === 0) {
            $warnings[] = 'No customers found';
        } else {
            $this->command->info("✅ Found {$customerCount} customers");
        }

        // Check batches
        $batchCount = ProductBatch::count();
        if ($batchCount === 0) {
            $warnings[] = 'No product batches found - did you run InventorySeeder?';
        } else {
            $this->command->info("✅ Found {$batchCount} product batches");

            $active = ProductBatch::where('status', 'active')->count();
            $expired = ProductBatch::where('status', 'expired')->count();
            $depleted = ProductBatch::where('status', 'depleted')->count();
            $this->command->line("   - Active: {$active}, Expired: {$expired}, Depleted: {$depleted}");
        }

        // Check branch authorizations
        $authCount = BranchAuthorization::count();
        if ($authCount === 0) {
            $warnings[] = 'No branch authorizations - did you run BranchAuthorizationSeeder?';
        } else {
            $this->command->info("✅ Found {$authCount} branch authorization(s)");
        }

        // Check stock write-offs
        $writeoffCount = StockWriteoff::count();
        if ($writeoffCount === 0) {
            $warnings[] = 'No stock write-offs - did you run WorkflowSeeder?';
        } else {
            $this->command->info("✅ Found {$writeoffCount} stock write-off(s)");
        }

        // Check inventory transactions
        $transactionCount = InventoryTransaction::count();
        if ($transactionCount === 0) {
            $warnings[] = 'No inventory transactions found';
        } else {
            $this->command->info("✅ Found {$transactionCount} inventory transactions");
        }

        // Verify business relationships
        foreach (Business::all() as $business) {
            if ($business->branches->isEmpty()) {
                $errors[] = "Business '{$business->name}' has no branches";
            }
            if ($business->products->isEmpty()) {
                $warnings[] = "Business '{$business->name}' has no products";
            }
        }

        // Verify sales totals match
        $this->command->newLine();
        $this->command->info('🔢 Verifying sales calculations...');

        $salesWithMismatch = 0;
        foreach (Sale::with('items')->get() as $sale) {
            $itemsTotal = $sale->items->sum(function ($item) {
                return (float) $item->total;
            });
            $saleTotal = (float) $sale->total_amount;

            // Allow small rounding differences
            if (abs($itemsTotal - $saleTotal) > 0.02) {
                $salesWithMismatch++;
            }
        }

        if ($salesWithMismatch > 0) {
            $warnings[] = "{$salesWithMismatch} sales have mismatched totals (items vs sale total)";
        } else {
            $this->command->info('✅ All sales have correct totals');
        }

        // Print summary
        $this->command->newLine();

        if (! empty($errors)) {
            $this->command->error('❌ ERRORS FOUND:');
            foreach ($errors as $error) {
                $this->command->line("   - {$error}");
            }
            $this->command->newLine();
        }

        if (! empty($warnings)) {
            $this->command->warn('⚠️  WARNINGS:');
            foreach ($warnings as $warning) {
                $this->command->line("   - {$warning}");
            }
            $this->command->newLine();
        }

        if (empty($errors) && empty($warnings)) {
            $this->command->info('🎉 All checks passed! Database is properly seeded.');
        } elseif (empty($errors)) {
            $this->command->info('✅ No critical errors found. Some warnings present.');
        } else {
            $this->command->error('❌ Critical errors found. Please review and re-seed.');
        }

        $this->command->newLine();
        $this->printQuickStats();
    }

    private function printQuickStats(): void
    {
        $this->command->info('📊 Quick Statistics:');

        $totalRevenue = Sale::where('status', 'completed')->sum('total_amount');
        $avgSaleValue = Sale::where('status', 'completed')->avg('total_amount');
        $totalCustomers = Customer::count();
        $totalProducts = Product::count();
        $totalBranches = Branch::count();

        $this->command->table(
            ['Metric', 'Value'],
            [
                ['Total Revenue', '$'.number_format($totalRevenue, 2)],
                ['Average Sale', '$'.number_format($avgSaleValue, 2)],
                ['Total Customers', number_format($totalCustomers)],
                ['Total Products', number_format($totalProducts)],
                ['Total Branches', number_format($totalBranches)],
            ]
        );
    }
}
