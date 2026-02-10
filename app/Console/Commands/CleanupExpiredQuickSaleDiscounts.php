<?php

namespace App\Console\Commands;

use App\Models\BranchProduct;
use App\Models\QuickSale;
use Illuminate\Console\Command;

class CleanupExpiredQuickSaleDiscounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'quicksales:cleanup-discounts
                            {--branch-id= : Specific branch ID to clean up}
                            {--all : Clean up all branches}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove expired quick sale discounts from branch products';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $branchId = $this->option('branch-id');
        $all = $this->option('all');

        if (!$branchId && !$all) {
            $this->error('Please specify either --branch-id or --all option');
            return 1;
        }

        $this->info('Starting cleanup of expired quick sale discounts...');

        if ($all) {
            $this->cleanupAllBranches();
        } else {
            $this->cleanupBranch((int) $branchId);
        }

        $this->info('Cleanup completed successfully!');
        return 0;
    }

    /**
     * Clean up all branches
     */
    protected function cleanupAllBranches(): void
    {
        // Get all branch products with discounts
        $branchProducts = BranchProduct::whereNotNull('discount_type')
            ->whereNotNull('discount_amount')
            ->get();

        $cleanedCount = 0;

        foreach ($branchProducts as $branchProduct) {
            $originalDiscountType = $branchProduct->discount_type;
            $originalDiscountAmount = $branchProduct->discount_amount;

            $branchProduct->verifyAndCleanQuickSaleDiscount();
            $branchProduct->refresh();

            // Check if discount was removed
            if ($originalDiscountType !== null && $branchProduct->discount_type === null) {
                $cleanedCount++;
                $this->line("Cleaned discount from product #{$branchProduct->product_id} in branch #{$branchProduct->branch_id}");
            }
        }

        $this->info("Cleaned {$cleanedCount} expired discounts from {$branchProducts->count()} products with discounts");
    }

    /**
     * Clean up a specific branch
     */
    protected function cleanupBranch(int $branchId): void
    {
        $branchProducts = BranchProduct::where('branch_id', $branchId)
            ->whereNotNull('discount_type')
            ->whereNotNull('discount_amount')
            ->get();

        $cleanedCount = 0;

        foreach ($branchProducts as $branchProduct) {
            $originalDiscountType = $branchProduct->discount_type;
            $originalDiscountAmount = $branchProduct->discount_amount;

            $branchProduct->verifyAndCleanQuickSaleDiscount();
            $branchProduct->refresh();

            // Check if discount was removed
            if ($originalDiscountType !== null && $branchProduct->discount_type === null) {
                $cleanedCount++;
                $this->line("Cleaned discount from product #{$branchProduct->product_id}");
            }
        }

        $this->info("Cleaned {$cleanedCount} expired discounts from {$branchProducts->count()} products in branch #{$branchId}");
    }
}
