<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Business;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
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
            $this->command->info("Seeding inventory for business: {$business->name}");
            $this->createInventoryForBusiness($business);
        }
    }

    private function createInventoryForBusiness(Business $business): void
    {
        $products = Product::where('business_id', $business->id)->get();

        if ($products->isEmpty()) {
            $this->command->warn("No products found for {$business->name}. Skipping inventory.");

            return;
        }

        $branches = $business->branches;
        $users = $business->users;

        if ($users->isEmpty()) {
            $user = User::factory()->create();
            $user->businesses()->attach($business->id, [
                'is_active' => true,
            ]);
            $users = collect([$user]);
        }

        foreach ($branches as $branch) {
            $this->command->info("  Creating inventory for branch: {$branch->name}");

            foreach ($products as $product) {
                $this->createInventoryForProduct($business, $branch, $product, $users->first());
            }
        }
    }

    private function createInventoryForProduct(
        Business $business,
        Branch $branch,
        Product $product,
        User $user
    ): void {
        // Check if product is perishable (should use batch tracking)
        // Perishable categories: Groceries, Beverages, Dairy Products
        $perishableCategories = ['Groceries', 'Beverages', 'Dairy Products'];
        $categoryName = $product->category?->name;
        $usesBatchTracking = in_array($categoryName, $perishableCategories);

        if ($usesBatchTracking) {
            $batchRange = config('seeding.limits.'.config('seeding.size', 'large').'.batches_per_product', [2, 5]);
            $batchCount = fake()->numberBetween($batchRange[0], $batchRange[1]);

            for ($i = 0; $i < $batchCount; $i++) {
                $this->createBatch($branch, $product, $user, $i);
            }
        } else {
            // Simple stock tracking - create inventory transactions
            $this->createSimpleInventoryTransactions($branch, $product, $user);
        }
    }

    private function createBatch(Branch $branch, Product $product, User $user, int $index): void
    {
        $initialQty = fake()->numberBetween(50, 500);
        $soldQty = fake()->numberBetween(0, (int) ($initialQty * 0.6));
        $currentQty = $initialQty - $soldQty;

        // Manufacturing date in the past
        $manufacturingDate = Carbon::now()->subDays(fake()->numberBetween(30, 180));

        // Expiry date in the future (or past for some items)
        $daysToExpiry = fake()->numberBetween(-30, 730); // Some expired, some valid
        $expiryDate = Carbon::now()->addDays($daysToExpiry);

        // Determine status
        $status = 'active';
        if ($expiryDate->isPast()) {
            $status = 'expired';
        } elseif ($currentQty === 0) {
            $status = 'depleted';
        }

        $batch = ProductBatch::create([
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'batch_number' => 'BATCH-'.now()->format('Ymd').'-'.str_pad($product->id, 4, '0', STR_PAD_LEFT).'-'.str_pad($index + 1, 3, '0', STR_PAD_LEFT),
            'lot_number' => 'LOT-'.fake()->numerify('######'),
            'received_quantity' => $initialQty,
            'current_quantity' => $currentQty,
            'unit_cost' => $product->base_cost_price,
            'manufacturing_date' => $manufacturingDate,
            'expiry_date' => $expiryDate,
            'supplier_name' => fake()->company(),
            'supplier_reference' => 'PO-'.fake()->numerify('######'),
            'status' => $status,
        ]);

        // Create purchase transaction for initial stock
        InventoryTransaction::create([
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'type' => 'purchase',
            'quantity' => $initialQty,
            'unit_cost' => $product->base_cost_price,
            'notes' => 'Initial stock purchase',
            'user_id' => $user->id,
            'created_at' => $manufacturingDate->copy()->addDays(7),
        ]);

        // Create sale transactions if items were sold
        if ($soldQty > 0) {
            // Split into multiple sale transactions
            $remainingSold = $soldQty;
            $transactionCount = fake()->numberBetween(1, 5);

            for ($i = 0; $i < $transactionCount && $remainingSold > 0; $i++) {
                $qtySold = min(fake()->numberBetween(1, 20), $remainingSold);

                InventoryTransaction::create([
                    'business_id' => $branch->business_id,
                    'branch_id' => $branch->id,
                    'product_id' => $product->id,
                    'batch_id' => $batch->id,
                    'type' => 'sale',
                    'quantity' => -$qtySold,
                    'unit_cost' => $product->base_cost_price,
                    'notes' => 'Batch stock sold',
                    'user_id' => $user->id,
                    'created_at' => fake()->dateTimeBetween($manufacturingDate, 'now'),
                ]);

                $remainingSold -= $qtySold;
            }
        }

        // Occasionally create adjustment transactions
        if (fake()->boolean(20)) {
            $adjustmentQty = fake()->numberBetween(-10, 10);

            InventoryTransaction::create([
                'business_id' => $branch->business_id,
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'batch_id' => $batch->id,
                'type' => 'adjustment',
                'quantity' => $adjustmentQty,
                'unit_cost' => $product->base_cost_price,
                'notes' => 'Stock count adjustment',
                'user_id' => $user->id,
                'created_at' => fake()->dateTimeBetween('-30 days', 'now'),
            ]);
        }
    }

    private function createSimpleInventoryTransactions(Branch $branch, Product $product, User $user): void
    {
        // Create initial purchase
        $initialQty = fake()->numberBetween(100, 500);

        InventoryTransaction::create([
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'batch_id' => null,
            'type' => 'purchase',
            'quantity' => $initialQty,
            'unit_cost' => $product->base_cost_price,
            'notes' => 'Initial stock purchase',
            'user_id' => $user->id,
            'created_at' => Carbon::now()->subDays(60),
        ]);

        // Create some sale transactions
        $salesCount = fake()->numberBetween(5, 20);
        for ($i = 0; $i < $salesCount; $i++) {
            InventoryTransaction::create([
                'business_id' => $branch->business_id,
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'batch_id' => null,
                'type' => 'sale',
                'quantity' => -fake()->numberBetween(1, 20),
                'unit_cost' => $product->base_cost_price,
                'notes' => 'Stock sold',
                'user_id' => $user->id,
                'created_at' => fake()->dateTimeBetween('-60 days', 'now'),
            ]);
        }

        // Occasionally create adjustments
        if (fake()->boolean(30)) {
            InventoryTransaction::create([
                'business_id' => $branch->business_id,
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'batch_id' => null,
                'type' => 'adjustment',
                'quantity' => fake()->numberBetween(-20, 20),
                'unit_cost' => $product->base_cost_price,
                'notes' => 'Stock count adjustment - '.fake()->randomElement(['count correction', 'damage', 'found stock']),
                'user_id' => $user->id,
                'created_at' => fake()->dateTimeBetween('-30 days', 'now'),
            ]);
        }
    }
}
