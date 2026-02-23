<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\QuickSale;
use App\Models\RefundRequest;
use App\Models\Sale;
use App\Models\StockTransferRequest;
use App\Models\StockWriteoff;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class WorkflowSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $businesses = Business::with('branches')->get();

        if ($businesses->isEmpty()) {
            $this->command->warn('No businesses found. Please run BusinessSeeder first.');

            return;
        }

        foreach ($businesses as $business) {
            $this->command->info("Seeding workflows for business: {$business->name}");
            $this->createWorkflowsForBusiness($business);
        }
    }

    private function createWorkflowsForBusiness(Business $business): void
    {
        $sales = Sale::where('business_id', $business->id)->get();
        $products = Product::where('business_id', $business->id)->get();
        $batches = ProductBatch::whereHas('product', function ($query) use ($business) {
            $query->where('business_id', $business->id);
        })->get();
        $branches = $business->branches;
        $users = $business->users;

        if ($sales->isEmpty() || $products->isEmpty() || $users->isEmpty()) {
            $this->command->warn("Insufficient data for {$business->name}. Skipping workflows.");

            return;
        }

        // Create refund requests
        $this->createRefundRequests($business, $sales, $users);

        // Create quick sale requests
        if ($batches->isNotEmpty()) {
            $this->createQuickSaleRequests($business, $branches, $products, $batches, $users);
        }

        // Create stock transfer requests
        if ($branches->count() > 1) {
            $this->createStockTransferRequests($business, $branches, $products, $batches, $users);
        }

        // Create stock write-offs
        $this->createStockWriteoffs($business, $users);
    }

    private function createRefundRequests(Business $business, $sales, $users): void
    {
        $this->command->info('  Creating refund requests...');

        $range = config('seeding.limits.'.config('seeding.size', 'large').'.refund_requests', [10, 20]);
        $refundCount = fake()->numberBetween($range[0], $range[1]);
        $completedSales = $sales->where('status', 'completed')->take($refundCount * 2);

        foreach ($completedSales->random($refundCount) as $sale) {
            $status = fake()->randomElement(['pending', 'pending', 'approved', 'rejected']); // More pending

            RefundRequest::create([
                'sale_id' => $sale->id,
                'business_id' => $business->id,
                'branch_id' => $sale->branch_id,
                'requested_by' => $sale->user_id,
                'reviewed_by' => $status !== 'pending' ? $users->random()->id : null,
                'amount' => $sale->total_amount,
                'reason' => fake()->randomElement([
                    'Product defective',
                    'Wrong item ordered',
                    'Customer changed mind',
                    'Product damaged',
                    'Not as described',
                    'Quality issue',
                ]),
                'rejection_reason' => $status === 'rejected' ? 'No receipt provided' : null,
                'status' => $status,
                'reviewed_at' => $status !== 'pending' ? fake()->dateTimeBetween('-7 days', 'now') : null,
                'created_at' => $sale->sale_date->copy()->addHours(fake()->numberBetween(1, 48)),
            ]);
        }

        $this->command->info("    Created {$refundCount} refund requests");
    }

    private function createQuickSaleRequests(Business $business, $branches, $products, $batches, $users): void
    {
        $this->command->info('  Creating quick sale requests...');

        // Find products with batches that are perishable
        $perishableProducts = $products->filter(function ($product) {
            $categoryName = $product->category?->name;

            return in_array($categoryName, ['Groceries', 'Beverages', 'Dairy Products']);
        });

        if ($perishableProducts->isEmpty()) {
            $perishableProducts = $products->random(min(10, $products->count()));
        }

        $quickSalesLimit = (int) config('seeding.limits.'.config('seeding.size', 'large').'.quick_sales', 15);
        $quickSalesCount = 0;
        foreach ($perishableProducts->take($quickSalesLimit) as $product) {
            $branch = $branches->random();
            $branchId = $branch->id;
            $matchingBatches = $batches->where('product_id', $product->id)->where('branch_id', $branchId);
            $batch = $matchingBatches->isNotEmpty() ? $matchingBatches->random() : null;

            $status = fake()->randomElement(['pending', 'approved', 'approved', 'rejected', 'ended']);

            $discountType = fake()->randomElement(['percentage', 'fixed']);
            $discountValue = $discountType === 'percentage'
                ? fake()->randomFloat(2, 20, 50)
                : fake()->randomFloat(2, 5, ($product->base_selling_price ?? 0) * 0.3);

            QuickSale::create([
                'business_id' => $business->id,
                'branch_id' => $branchId,
                'batch_id' => $batch?->id,
                'product_id' => $product->id,
                'reason' => fake()->randomElement([
                    'Product expiring in '.fake()->numberBetween(5, 30).' days',
                    'Batch near expiry',
                    'Need to clear old stock',
                    'Overstock situation',
                ]),
                'expiry_date' => Carbon::now()->addDays(fake()->numberBetween(5, 30)),
                'discount_type' => in_array($status, ['approved', 'active', 'ended']) ? $discountType : null,
                'discount_value' => in_array($status, ['approved', 'active', 'ended']) ? $discountValue : null,
                'status' => $status,
                'requested_by' => $users->random()->id,
                'approved_by' => in_array($status, ['approved', 'active', 'ended']) ? $users->random()->id : null,
                'approved_at' => in_array($status, ['approved', 'active', 'ended']) ? fake()->dateTimeBetween('-20 days', '-1 day') : null,
                'start_time' => in_array($status, ['approved', 'active', 'ended']) ? fake()->dateTimeBetween('-20 days', '-1 day') : null,
                'end_time' => fake()->dateTimeBetween('now', '+30 days'),
                'ended_by' => $status === 'ended' ? $users->random()->id : null,
                'ended_at' => $status === 'ended' ? fake()->dateTimeBetween('-10 days', 'now') : null,
                'rejection_reason' => $status === 'rejected' ? fake()->sentence() : null,
            ]);
            $quickSalesCount++;
        }

        $this->command->info("    Created {$quickSalesCount} quick sale requests");
    }

    private function createStockTransferRequests(Business $business, $branches, $products, $batches, $users): void
    {
        $this->command->info('  Creating stock transfer requests...');

        // Get branch products for this business
        $branchProducts = \App\Models\BranchProduct::whereHas('branch', function ($q) use ($business) {
            $q->where('business_id', $business->id);
        })->with(['branch', 'product'])->get();

        if ($branchProducts->isEmpty()) {
            $this->command->info('    No branch products found for transfers');

            return;
        }

        $trRange = config('seeding.limits.'.config('seeding.size', 'large').'.stock_transfers', [5, 15]);
        $transferCount = min(fake()->numberBetween($trRange[0], $trRange[1]), $branchProducts->count());
        $created = 0;

        $branchIds = $branches->pluck('id')->toArray();

        for ($i = 0; $i < $transferCount; $i++) {
            $branchProduct = $branchProducts->random();
            $branchFromId = $branchProduct->branch_id;
            $otherBranchIds = array_values(array_diff($branchIds, [$branchFromId]));
            $branchToId = count($otherBranchIds) > 0 ? $otherBranchIds[array_rand($otherBranchIds)] : $branchFromId;
            if ($branchToId === $branchFromId) {
                continue;
            }
            $status = fake()->randomElement(['pending', 'approved', 'rejected', 'confirmed', 'cancelled']);

            try {
                StockTransferRequest::create([
                    'request_number' => 'TR-'.now()->format('Ymd').'-'.str_pad(fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
                    'business_id' => $business->id,
                    'branch_id' => $branchFromId,
                    'branch_from_id' => $branchFromId,
                    'branch_to_id' => $branchToId,
                    'direction' => 'out',
                    'branch_product_id' => $branchProduct->id,
                    'quantity_requested' => fake()->numberBetween(10, 100),
                    'quantity_transferred' => in_array($status, ['confirmed']) ? fake()->numberBetween(10, 100) : null,
                    'reason' => fake()->randomElement([
                        'Low stock at destination branch',
                        'Rebalancing inventory',
                        'Requested by manager',
                        'Excess stock at source',
                    ]),
                    'priority' => fake()->randomElement(['low', 'normal', 'high', 'urgent']),
                    'status' => $status,
                    'requested_by' => $users->random()->id,
                    'requested_at' => fake()->dateTimeBetween('-30 days', 'now'),
                    'reviewed_by' => in_array($status, ['approved', 'rejected', 'confirmed']) ? $users->random()->id : null,
                    'reviewed_at' => in_array($status, ['approved', 'rejected', 'confirmed']) ? fake()->dateTimeBetween('-20 days', 'now') : null,
                    'review_notes' => in_array($status, ['approved', 'rejected']) ? fake()->optional(0.5)->sentence() : null,
                    'confirmed_by' => $status === 'confirmed' ? $users->random()->id : null,
                    'confirmed_at' => $status === 'confirmed' ? fake()->dateTimeBetween('-10 days', 'now') : null,
                    'confirmation_notes' => $status === 'confirmed' ? fake()->optional(0.3)->sentence() : null,
                ]);
                $created++;
            } catch (\Exception $e) {
                // Skip on error (likely unique constraint on request_number)
                continue;
            }
        }

        $this->command->info("    Created {$created} stock transfer requests");
    }

    private function createStockWriteoffs(Business $business, $users): void
    {
        $this->command->info('  Creating stock write-offs...');

        $branchProducts = \App\Models\BranchProduct::whereHas('branch', function ($q) use ($business) {
            $q->where('business_id', $business->id);
        })->with(['branch', 'product'])->get();

        if ($branchProducts->isEmpty()) {
            $this->command->info('    No branch products found for write-offs');

            return;
        }

        $woRange = config('seeding.limits.'.config('seeding.size', 'large').'.stock_writeoffs', [5, 15]);
        $count = min(fake()->numberBetween($woRange[0], $woRange[1]), $branchProducts->count());
        $created = 0;

        foreach ($branchProducts->random($count) as $bp) {
            $qty = min(fake()->numberBetween(1, 20), (int) $bp->shelf_quantity + (int) $bp->store_quantity);
            if ($qty < 1) {
                $qty = 1;
            }

            StockWriteoff::create([
                'business_id' => $business->id,
                'branch_id' => $bp->branch_id,
                'branch_product_id' => $bp->id,
                'product_id' => $bp->product_id,
                'sku' => $bp->product->sku ?? 'N/A',
                'quantity' => $qty,
                'source' => fake()->randomElement(['shelf', 'store']),
                'reason' => fake()->randomElement([
                    'Damaged in storage',
                    'Expired',
                    'Quality defect',
                    'Lost or missing',
                    'Pilot write-off',
                ]),
                'written_off_by' => $users->random()->id,
                'written_off_at' => fake()->dateTimeBetween('-30 days', 'now'),
            ]);
            $created++;
        }

        $this->command->info("    Created {$created} stock write-offs");
    }
}
