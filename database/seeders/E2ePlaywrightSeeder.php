<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BranchAuthorization;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Customer;
use App\Models\DeviceGroup;
use App\Models\DeviceRegistration;
use App\Models\InventoryTransaction;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\QuickSale;
use App\Models\RefundRequest;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesShift;
use App\Models\StockWriteoff;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class E2ePlaywrightSeeder extends Seeder
{
    public function run(): void
    {
        $this->callSilent([
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
        ]);

        $owner = User::factory()->create([
            'name' => 'E2E Owner',
            'email' => 'owner@e2e.test',
            'password' => Hash::make('password'),
        ]);

        $storekeeper = User::factory()->create([
            'name' => 'E2E Storekeeper',
            'email' => 'storekeeper@e2e.test',
            'password' => Hash::make('password'),
        ]);

        $staff = User::factory()->create([
            'name' => 'E2E Staff',
            'email' => 'staff@e2e.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'E2E Market',
            'legal_name' => 'E2E Market Ltd',
            'email' => 'owner@e2e.test',
            'phone' => '08000000000',
            'address' => '1 Test Road',
            'city' => 'Lagos',
            'state' => 'LA',
            'postal_code' => '100001',
            'country' => 'NG',
            'currency' => 'NGN',
            'time_zone' => 'Africa/Lagos',
            'default_tax_rate' => 7.5,
            'settings' => [
                'currency_symbol' => '₦',
                'deposit_stock_mode' => 'reserve_on_create',
                'allow_decimal_quantities' => false,
                'enable_loyalty' => false,
                'near_expiry_days' => 30,
                'low_stock_threshold' => 10,
                'loyalty_points_rate' => 1,
                'shift_discrepancy_threshold' => 100,
            ],
        ]);

        $owner->businesses()->attach($business->id, ['is_active' => true]);
        $storekeeper->businesses()->attach($business->id, ['is_active' => true]);
        $staff->businesses()->attach($business->id, ['is_active' => true]);

        $branch = Branch::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'name' => 'Main',
            'code' => 'MAIN',
            'is_main' => true,
            'is_active' => true,
        ]);

        $shelfProduct = $this->product($business->id, 'Shelf Cola', 'E2E-SHELF', '111111');
        $behindProduct = $this->product($business->id, 'Ledger Lag', 'E2E-LAG', '222222');
        $receiveProduct = $this->product($business->id, 'Receive Rice', 'E2E-RICE', '444444');

        $shelfBranchProduct = BranchProduct::create([
            'branch_id' => $branch->id,
            'product_id' => $shelfProduct->id,
            'shelf_quantity' => 10,
            'store_quantity' => 0,
            'stock_quantity' => 10,
            'cost_price' => 50,
            'selling_price' => 100,
            'is_available' => true,
        ]);

        BranchProduct::create([
            'branch_id' => $branch->id,
            'product_id' => $receiveProduct->id,
            'shelf_quantity' => 5,
            'store_quantity' => 5,
            'stock_quantity' => 10,
            'cost_price' => 20,
            'selling_price' => 40,
            'is_available' => true,
        ]);

        $behindBranchProduct = BranchProduct::create([
            'branch_id' => $branch->id,
            'product_id' => $behindProduct->id,
            'shelf_quantity' => 10,
            'store_quantity' => 0,
            'stock_quantity' => 2,
            'cost_price' => 40,
            'selling_price' => 80,
            'is_available' => true,
        ]);

        $cash = PaymentMethod::create([
            'business_id' => $business->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $shift = SalesShift::create([
            'shift_number' => 'E2E-SHIFT-1',
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'user_id' => $owner->id,
            'start_time' => now(),
            'opening_balance' => 0,
            'status' => 'open',
        ]);

        $cashSales = [];
        foreach ([150, 100] as $index => $amount) {
            $sale = $cashSales[] = Sale::create([
                'sale_number' => 'E2E-CASH-'.($index + 1),
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'user_id' => $owner->id,
                'shift_id' => $shift->id,
                'sale_date' => now(),
                'subtotal' => $amount,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => $amount,
                'status' => 'completed',
                'payment_status' => 'paid',
            ]);

            Payment::create([
                'sale_id' => $sale->id,
                'shift_id' => null,
                'payment_method_id' => $cash->id,
                'amount' => $amount,
                'payment_date' => now(),
                'status' => 'completed',
            ]);

            $this->saleItem($sale, $shelfProduct, $amount);
        }

        foreach ([2 => 500, 1 => 800] as $monthsAgo => $amount) {
            Sale::create([
                'sale_number' => 'E2E-GROWTH-'.$monthsAgo,
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'user_id' => $owner->id,
                'sale_date' => now()->subMonths($monthsAgo),
                'subtotal' => $amount,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => $amount,
                'status' => 'completed',
                'payment_status' => 'paid',
            ]);
        }

        $contract = $this->contractFixtures($business, $branch, $owner, $shelfProduct, $shelfBranchProduct, $cash, $cashSales[0]);

        setPermissionsTeamId($business->id);

        foreach ([
            'view analytics',
            'manage inventory',
            'manage branch products',
            'create products',
            'view products',
            'view inventory',
            'view user shift',
            'view all shifts',
            'manage-users',
            'use-pin-login',
        ] as $permission) {
            Permission::findOrCreate($permission, 'api');
        }

        $ownerRole = Role::create([
            'name' => 'Owner',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);
        $ownerRole->givePermissionTo(Permission::query()->where('guard_name', 'api')->get());
        $owner->assignRole($ownerRole);

        $storekeeperRole = Role::create([
            'name' => 'Storekeeper',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);
        $storekeeperRole->givePermissionTo(['manage inventory', 'manage branch products', 'use-pin-login']);
        $storekeeper->assignRole($storekeeperRole);
        $storekeeper->update(['pin_code' => '123456']);

        BranchAuthorization::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'auth_code' => '246810',
            'expires_at' => now()->addDay(),
        ]);

        $manifest = [
            'password' => 'password',
            'owner_email' => $owner->email,
            'storekeeper_email' => $storekeeper->email,
            'staff_email' => $staff->email,
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'shelf_product_id' => $shelfProduct->id,
            'shelf_branch_product_id' => $shelfBranchProduct->id,
            'behind_product_id' => $behindProduct->id,
            'behind_branch_product_id' => $behindBranchProduct->id,
            'receive_product_id' => $receiveProduct->id,
            'setup_code' => '246810',
            'storekeeper_pin' => '123456',
            ...$contract,
        ];

        file_put_contents(storage_path('e2e-manifest.json'), json_encode($manifest, JSON_PRETTY_PRINT));
    }

    /**
     * Rows every web read endpoint needs so contract tests never validate an empty list.
     *
     * @return array<string, int>
     */
    private function contractFixtures(
        Business $business,
        Branch $branch,
        User $owner,
        Product $product,
        BranchProduct $branchProduct,
        PaymentMethod $cash,
        Sale $shiftSale,
    ): array {
        $category = ProductCategory::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'name' => 'Drinks',
            'slug' => 'drinks',
            'description' => 'Soft drinks',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        ProductCategory::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'parent_id' => $category->id,
            'name' => 'Sodas',
            'slug' => 'sodas',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $product->update(['category_id' => $category->id]);

        $customer = Customer::create([
            'business_id' => $business->id,
            'customer_code' => 'CUST-E2E-1',
            'name' => 'Ada Customer',
            'email' => 'ada@e2e.test',
            'phone' => '08011111111',
            'type' => 'regular',
            'credit_limit' => 0,
            'outstanding_balance' => 0,
            'loyalty_points' => 5,
            'metadata' => [],
            'is_active' => true,
        ]);

        $customerSale = Sale::create([
            'sale_number' => 'E2E-CUSTOMER-1',
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'sale_date' => now()->subDay(),
            'subtotal' => 200,
            'total_amount' => 200,
            'paid_amount' => 200,
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);
        $this->saleItem($customerSale, $product, 200, 2);
        Payment::create([
            'sale_id' => $customerSale->id,
            'payment_method_id' => $cash->id,
            'amount' => 200,
            'payment_date' => now()->subDay(),
            'status' => 'completed',
        ]);

        $depositSale = Sale::create([
            'sale_number' => 'E2E-DEPOSIT-1',
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'sale_date' => now()->subDay(),
            'subtotal' => 300,
            'total_amount' => 300,
            'paid_amount' => 100,
            'sale_type' => 'deposit',
            'status' => 'pending',
            'payment_status' => 'partial',
            'metadata' => ['deposit_stock_mode' => 'reserve_on_create'],
        ]);
        $this->saleItem($depositSale, $product, 300, 3);
        Payment::create([
            'sale_id' => $depositSale->id,
            'payment_method_id' => $cash->id,
            'amount' => 100,
            'payment_date' => now()->subDay(),
            'status' => 'completed',
        ]);

        $refund = RefundRequest::create([
            'sale_id' => $shiftSale->id,
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'requested_by' => $owner->id,
            'amount' => 50,
            'reason' => 'Damaged on arrival',
            'status' => 'pending',
        ]);

        $batch = ProductBatch::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'batch_number' => 'E2E-BATCH-1',
            'lot_number' => 'LOT-1',
            'expiry_date' => now()->addDays(10)->toDateString(),
            'received_quantity' => 10,
            'current_quantity' => 10,
            'unit_cost' => 50,
            'status' => 'active',
        ]);

        $quickSale = QuickSale::create([
            'product_id' => $product->id,
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'batch_id' => $batch->id,
            'requested_by' => $owner->id,
            'reason' => 'Expiring soon',
            'expiry_date' => $batch->expiry_date,
            'status' => 'pending',
        ]);

        StockWriteoff::create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'branch_product_id' => $branchProduct->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'quantity' => 1,
            'source' => 'shelf',
            'reason' => 'Broken bottle',
            'written_off_by' => $owner->id,
        ]);

        InventoryTransaction::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'user_id' => $owner->id,
            'type' => 'adjustment',
            'quantity' => 10,
            'quantity_before' => 0,
            'quantity_after' => 10,
            'unit_cost' => 50,
            'total_cost' => 500,
            'notes' => 'Opening stock',
        ]);

        $supplier = Supplier::create([
            'business_id' => $business->id,
            'code' => 'SUP-E2E',
            'name' => 'E2E Supplies',
            'default_currency' => 'NGN',
            'default_payment_terms_days' => 30,
            'is_active' => true,
        ]);

        $purchaseOrder = PurchaseOrder::create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-E2E-1',
            'status' => 'draft',
            'expected_at' => now()->addWeek()->toDateString(),
            'currency' => 'NGN',
            'subtotal' => 500,
            'tax_amount' => 0,
            'total_amount' => 500,
            'created_by' => $owner->id,
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'branch_product_id' => $branchProduct->id,
            'quantity_ordered' => 10,
            'quantity_received' => 0,
            'unit_cost' => 50,
            'tax_rate' => 0,
            'line_total' => 500,
        ]);

        $group = DeviceGroup::create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'name' => 'Front Tills',
            'code' => 'FRONT',
            'is_active' => true,
        ]);

        $device = DeviceRegistration::create([
            'device_id' => 'device-e2e-1',
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'group_id' => $group->id,
            'user_id' => $owner->id,
            'device_name' => 'Till 1',
            'device_type' => 'desktop',
            'os' => 'macOS',
            'app_version' => '1.0.0',
            'status' => 'active',
            'last_seen_at' => now(),
            'total_syncs' => 0,
            'capabilities' => ['offline_mode'],
            'metadata' => [],
        ]);

        return [
            'category_id' => $category->id,
            'customer_id' => $customer->id,
            'sale_id' => $customerSale->id,
            'deposit_sale_id' => $depositSale->id,
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $purchaseOrder->id,
            'device_registration_id' => $device->id,
            'device_group_id' => $group->id,
            'refund_request_id' => $refund->id,
            'quick_sale_id' => $quickSale->id,
        ];
    }

    private function saleItem(Sale $sale, Product $product, float $total, int $quantity = 1): SaleItem
    {
        return SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'quantity' => $quantity,
            'unit_price' => $total / $quantity,
            'subtotal' => $total,
            'total' => $total,
        ]);
    }

    private function product(int $businessId, string $name, string $sku, string $barcode): Product
    {
        return Product::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $businessId,
            'name' => $name,
            'sku' => $sku,
            'barcode' => $barcode,
            'base_cost_price' => 50,
            'base_selling_price' => 100,
            'is_active' => true,
        ]);
    }
}
