<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DepositSaleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Business $business;

    protected Branch $branch;

    protected Product $product;

    protected Customer $customer;

    protected PaymentMethod $cashMethod;

    protected PaymentMethod $cardMethod;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'create sales', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'view sales', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'manage sales', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'manage-settings', 'guard_name' => 'api']);

        $this->user = User::factory()->create();

        $this->business = Business::create([
            'name' => 'Test Business',
            'owner_id' => $this->user->id,
        ]);
        $this->user->businesses()->attach($this->business->id);

        $this->branch = Branch::create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'business_id' => $this->business->id,
            'address' => '123 Main St',
        ]);

        setPermissionsTeamId($this->business->id);
        $role = Role::create(['name' => 'manager', 'guard_name' => 'api']);
        $role->givePermissionTo(['create sales', 'view sales', 'manage sales', 'manage-settings']);
        $this->user->assignRole($role);

        $this->product = Product::create([
            'business_id' => $this->business->id,
            'name' => 'Phone',
            'sku' => 'PHN-001',
            'cost_price' => 500,
            'selling_price' => 1000,
            'base_selling_price' => 1000,
            'is_active' => true,
        ]);

        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'stock_quantity' => 10,
            'cost_price' => 500,
            'selling_price' => 1000,
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'customer_code' => 'CUST-001',
            'name' => 'John Doe',
        ]);

        $this->cashMethod = PaymentMethod::create([
            'business_id' => $this->business->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $this->cardMethod = PaymentMethod::create([
            'business_id' => $this->business->id,
            'name' => 'Card',
            'type' => 'card',
            'is_active' => true,
        ]);
    }

    private function openShift(?string $number = null): SalesShift
    {
        return SalesShift::create([
            'shift_number' => $number ?? ('SHIFT-'.uniqid()),
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now(),
            'opening_balance' => 0,
            'status' => 'open',
        ]);
    }

    private function setDepositMode(string $mode): void
    {
        $this->business->settings = array_merge(
            (array) ($this->business->settings ?? []),
            ['deposit_stock_mode' => $mode],
        );
        $this->business->save();
    }

    public function test_deposit_without_business_setting_defers_stock_until_completion(): void
    {
        $this->openShift();

        $response = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'sale_type' => 'deposit',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price' => 1000,
            ]],
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 2000,
            ]],
        ], ['X-Business-Id' => $this->business->id]);

        $response->assertStatus(201);
        $this->assertSame('deduct_on_complete', $response->json('sale.metadata.deposit_stock_mode'));
        $bp = BranchProduct::where('product_id', $this->product->id)->first();
        $this->assertSame(10.0, (float) $bp->stock_quantity);

        $this->actingAs($this->user)->postJson("/api/sales/{$response->json('sale.id')}/complete-deposit", [],
            ['X-Business-Id' => $this->business->id])->assertStatus(200);
        $this->assertSame(8.0, (float) $bp->fresh()->stock_quantity);
    }

    public function test_stamped_reserve_on_create_deposit_is_not_deducted_again_under_new_default(): void
    {
        $this->setDepositMode('reserve_on_create');
        $this->openShift();

        $response = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'sale_type' => 'deposit',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price' => 1000,
            ]],
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 2000,
            ]],
        ], ['X-Business-Id' => $this->business->id])->assertStatus(201);
        $bp = BranchProduct::where('product_id', $this->product->id)->first();
        $this->assertSame(8.0, (float) $bp->stock_quantity);

        // Business drops its explicit setting (falls back to the deduct_on_complete default),
        // and the deposit predates stamping (legacy row without metadata).
        $this->business->settings = [];
        $this->business->save();
        Sale::whereKey($response->json('sale.id'))->update(['metadata' => null]);

        $migration = require database_path('migrations/2026_10_13_100200_stamp_deposit_stock_mode_on_unstamped_deposits.php');
        $migration->up();
        $migration->up();

        $sale = Sale::find($response->json('sale.id'));
        $this->assertSame('reserve_on_create', $sale->metadata['deposit_stock_mode']);

        $this->actingAs($this->user)->postJson("/api/sales/{$sale->id}/complete-deposit", [],
            ['X-Business-Id' => $this->business->id])->assertStatus(200);
        $this->assertSame(8.0, (float) $bp->fresh()->stock_quantity, 'Completing must not deduct stock a second time');
    }

    public function test_deposit_in_reserve_on_create_mode_decrements_stock_and_uses_dep_prefix(): void
    {
        $this->setDepositMode('reserve_on_create');
        $shift = $this->openShift();

        $response = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'sale_type' => 'deposit',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price' => 1000,
            ]],
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 500,
            ]],
        ], ['X-Business-Id' => $this->business->id]);

        $response->assertStatus(201);
        $sale = $response->json('sale');

        $this->assertSame('deposit', $sale['sale_type']);
        $this->assertSame('pending', $sale['status']);
        $this->assertSame('partial', $sale['payment_status']);
        $this->assertStringStartsWith('DEP-', $sale['sale_number']);
        $this->assertSame('reserve_on_create', $sale['metadata']['deposit_stock_mode']);

        // Stock decremented immediately in reserve_on_create mode.
        $bp = BranchProduct::where('product_id', $this->product->id)->first();
        $this->assertSame(8.0, (float) $bp->stock_quantity);

        // Payment stamped with current shift.
        $this->assertDatabaseHas('payments', [
            'sale_id' => $sale['id'],
            'shift_id' => $shift->id,
            'amount' => '500.00',
        ]);
    }

    public function test_deposit_in_deduct_on_complete_mode_does_not_decrement_stock_at_create(): void
    {
        $this->setDepositMode('deduct_on_complete');
        $this->openShift();

        $response = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'sale_type' => 'deposit',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price' => 1000,
            ]],
        ], ['X-Business-Id' => $this->business->id]);

        $response->assertStatus(201);
        $this->assertSame('deduct_on_complete', $response->json('sale.metadata.deposit_stock_mode'));

        $bp = BranchProduct::where('product_id', $this->product->id)->first();
        $this->assertSame(10.0, (float) $bp->stock_quantity);
    }

    public function test_top_up_payment_does_not_complete_a_fully_paid_deposit(): void
    {
        $this->setDepositMode('reserve_on_create');
        $this->openShift();

        $createResponse = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'sale_type' => 'deposit',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 400,
            ]],
        ], ['X-Business-Id' => $this->business->id]);

        $saleId = $createResponse->json('sale.id');

        $topUp = $this->actingAs($this->user)->postJson("/api/sales/{$saleId}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 600,
        ], ['X-Business-Id' => $this->business->id]);

        $topUp->assertStatus(200);

        $sale = Sale::find($saleId);
        $this->assertSame('pending', $sale->status, 'Deposit must stay pending even when fully paid');
        $this->assertSame('paid', $sale->payment_status);
    }

    public function test_complete_deposit_fails_when_not_fully_paid(): void
    {
        $this->setDepositMode('reserve_on_create');
        $this->openShift();

        $createResponse = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'sale_type' => 'deposit',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 200,
            ]],
        ], ['X-Business-Id' => $this->business->id]);

        $saleId = $createResponse->json('sale.id');

        $complete = $this->actingAs($this->user)->postJson("/api/sales/{$saleId}/complete-deposit", [],
            ['X-Business-Id' => $this->business->id]);

        $complete->assertStatus(422)
            ->assertJsonFragment(['message' => 'Deposit is not fully paid; cannot complete']);
    }

    public function test_complete_deposit_with_final_payment_flips_status_and_deducts_stock_in_deduct_mode(): void
    {
        $this->setDepositMode('deduct_on_complete');
        $shift = $this->openShift();

        $createResponse = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'sale_type' => 'deposit',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 3,
                'unit_price' => 1000,
            ]],
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 1500,
            ]],
        ], ['X-Business-Id' => $this->business->id]);

        $saleId = $createResponse->json('sale.id');

        $bpBefore = BranchProduct::where('product_id', $this->product->id)->first();
        $this->assertSame(10.0, (float) $bpBefore->stock_quantity);

        $complete = $this->actingAs($this->user)->postJson("/api/sales/{$saleId}/complete-deposit", [
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 1500,
            ]],
        ], ['X-Business-Id' => $this->business->id]);

        $complete->assertStatus(200);
        $this->assertSame('completed', $complete->json('sale.status'));
        $this->assertSame('paid', $complete->json('sale.payment_status'));

        $bpAfter = BranchProduct::where('product_id', $this->product->id)->first();
        $this->assertSame(7.0, (float) $bpAfter->stock_quantity, 'Stock should be deducted at completion');

        // Final payment was stamped with the active shift.
        $this->assertDatabaseHas('payments', [
            'sale_id' => $saleId,
            'shift_id' => $shift->id,
            'amount' => '1500.00',
        ]);
    }

    public function test_cancel_in_deduct_on_complete_mode_does_not_restore_stock(): void
    {
        $this->setDepositMode('deduct_on_complete');
        $this->openShift();

        $createResponse = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'sale_type' => 'deposit',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price' => 1000,
            ]],
        ], ['X-Business-Id' => $this->business->id]);

        $saleId = $createResponse->json('sale.id');

        $cancel = $this->actingAs($this->user)->postJson("/api/sales/{$saleId}/cancel", [],
            ['X-Business-Id' => $this->business->id]);

        $cancel->assertStatus(200);

        $bp = BranchProduct::where('product_id', $this->product->id)->first();
        $this->assertSame(10.0, (float) $bp->stock_quantity, 'Nothing was deducted, so nothing should be restored');
    }

    public function test_cancel_in_reserve_on_create_mode_restores_stock(): void
    {
        $this->setDepositMode('reserve_on_create');
        $this->openShift();

        $createResponse = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'sale_type' => 'deposit',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price' => 1000,
            ]],
        ], ['X-Business-Id' => $this->business->id]);

        $saleId = $createResponse->json('sale.id');

        $bpMid = BranchProduct::where('product_id', $this->product->id)->first();
        $this->assertSame(8.0, (float) $bpMid->stock_quantity);

        $cancel = $this->actingAs($this->user)->postJson("/api/sales/{$saleId}/cancel", [],
            ['X-Business-Id' => $this->business->id]);

        $cancel->assertStatus(200);

        $bp = BranchProduct::where('product_id', $this->product->id)->first();
        $this->assertSame(10.0, (float) $bp->stock_quantity);
    }

    public function test_find_by_reference_returns_deposit_or_404(): void
    {
        $this->setDepositMode('reserve_on_create');
        $this->openShift();

        $createResponse = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'sale_type' => 'deposit',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
        ], ['X-Business-Id' => $this->business->id]);

        $reference = $createResponse->json('sale.sale_number');

        $hit = $this->actingAs($this->user)->getJson("/api/sales/by-reference/{$reference}",
            ['X-Business-Id' => $this->business->id]);
        $hit->assertStatus(200);
        $this->assertSame($reference, $hit->json('sale.sale_number'));

        $miss = $this->actingAs($this->user)->getJson('/api/sales/by-reference/DEP-NOPE-9999',
            ['X-Business-Id' => $this->business->id]);
        $miss->assertStatus(404);
    }

    public function test_cross_shift_payment_attribution_is_correct(): void
    {
        $this->setDepositMode('reserve_on_create');

        // Shift A: open deposit with ₦1000 cash down.
        $shiftA = $this->openShift('SHIFT-A');
        $createResponse = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'sale_type' => 'deposit',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price' => 1000,
            ]],
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 1000,
            ]],
        ], ['X-Business-Id' => $this->business->id]);

        $saleId = $createResponse->json('sale.id');
        $shiftA->update(['status' => 'closed', 'end_time' => now()]);

        // Shift B: top-up ₦500 cash.
        $shiftB = $this->openShift('SHIFT-B');
        $this->actingAs($this->user)->postJson("/api/sales/{$saleId}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 500,
        ], ['X-Business-Id' => $this->business->id])->assertStatus(200);
        $shiftB->update(['status' => 'closed', 'end_time' => now()]);

        // Shift C: final ₦500 + complete.
        $shiftC = $this->openShift('SHIFT-C');
        $this->actingAs($this->user)->postJson("/api/sales/{$saleId}/complete-deposit", [
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 500,
            ]],
        ], ['X-Business-Id' => $this->business->id])->assertStatus(200);
        $shiftC->update(['status' => 'closed', 'end_time' => now()]);

        // Each shift should account for exactly the cash it received.
        $shiftA->refresh();
        $shiftA->updateSalesMetrics();
        $this->assertSame('1000.00', (string) $shiftA->cash_sales);

        $shiftB->refresh();
        $shiftB->updateSalesMetrics();
        $this->assertSame('500.00', (string) $shiftB->cash_sales);

        $shiftC->refresh();
        $shiftC->updateSalesMetrics();
        $this->assertSame('500.00', (string) $shiftC->cash_sales);
    }

    public function test_existing_payments_are_backfilled_with_shift_id(): void
    {
        // Migrations have already run before each test (RefreshDatabase). We simulate the
        // backfill scenario by creating a regular sale + payment, deleting payment.shift_id,
        // re-running the backfill statement, and asserting parity.
        $shift = $this->openShift();

        $response = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 1000,
            ]],
        ], ['X-Business-Id' => $this->business->id]);

        $saleId = $response->json('sale.id');

        Payment::where('sale_id', $saleId)->update(['shift_id' => null]);

        \DB::statement('UPDATE payments
            SET shift_id = (SELECT shift_id FROM sales WHERE sales.id = payments.sale_id)
            WHERE shift_id IS NULL');

        $this->assertDatabaseHas('payments', [
            'sale_id' => $saleId,
            'shift_id' => $shift->id,
        ]);
    }

    public function test_cancelled_sale_payments_are_excluded_from_open_shift_summary(): void
    {
        $shift = $this->openShift();

        // Sale 1: completed, ₦400 cash — should count.
        $kept = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 400,
            ]],
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 400,
            ]],
        ], ['X-Business-Id' => $this->business->id]);
        $kept->assertStatus(201);

        // Sale 2: ₦300 cash collected, then cancelled — should NOT count toward expected cash.
        $cancelled = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 300,
            ]],
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 300,
            ]],
        ], ['X-Business-Id' => $this->business->id]);
        $cancelled->assertStatus(201);
        $cancelledId = $cancelled->json('sale.id');

        $this->actingAs($this->user)->postJson(
            "/api/sales/{$cancelledId}/cancel",
            [],
            ['X-Business-Id' => $this->business->id],
        )->assertStatus(200);

        // Open-shift summary must reflect only the kept sale's cash.
        $res = $this->actingAs($this->user)->getJson(
            "/api/shifts/{$shift->id}/summary",
            ['X-Business-Id' => (string) $this->business->id],
        );

        $res->assertStatus(200);
        $res->assertJsonPath('data.sales_by_payment_type.cash.amount', 400);
        $res->assertJsonPath('data.expected_cash', 400);
        $res->assertJsonPath('data.gross_sales', 400);
        $res->assertJsonPath('data.total_transactions', 1);

        // Closing the shift should reconcile to ₦400 (not ₦700).
        $this->user->update(['pin_code' => '123456']);
        $close = $this->actingAs($this->user)->postJson(
            "/api/shifts/{$shift->id}/close",
            [
                'actual_cash' => 400,
                'pin_code' => '123456',
            ],
            ['X-Business-Id' => (string) $this->business->id],
        );
        $close->assertStatus(200);

        $shift->refresh();
        $this->assertSame('400.00', (string) $shift->cash_sales);
        $this->assertSame('400.00', (string) $shift->expected_cash);
        $this->assertSame('0.00', (string) $shift->variance);
    }

    public function test_open_shift_summary_includes_pending_deposit_payments_in_expected_cash(): void
    {
        $this->setDepositMode('reserve_on_create');
        $shift = $this->openShift();

        $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'sale_type' => 'deposit',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 2000,
            ]],
            'payments' => [[
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 250,
            ]],
        ], ['X-Business-Id' => $this->business->id])->assertStatus(201);

        $res = $this->actingAs($this->user)->getJson(
            "/api/shifts/{$shift->id}/summary",
            ['X-Business-Id' => (string) $this->business->id],
        );

        $res->assertStatus(200);
        $res->assertJsonPath('data.sales_by_payment_type.cash.amount', 250);
        $res->assertJsonPath('data.expected_cash', 250);
        $res->assertJsonPath('data.gross_sales', 250);
    }

    private function itemlessDeposit(float $paid, ?SalesShift $shift = null, ?User $cashier = null): Sale
    {
        $sale = Sale::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_id' => $shift?->id,
            'customer_id' => $this->customer->id,
            'user_id' => ($cashier ?? $this->user)->id,
            'sale_number' => 'DEP-'.uniqid(),
            'sale_type' => 'deposit',
            'sale_date' => now(),
            'subtotal' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 0,
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'metadata' => ['deposit_stock_mode' => 'deduct_on_complete'],
        ]);
        Payment::create([
            'sale_id' => $sale->id,
            'shift_id' => $shift?->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => $paid,
            'payment_date' => now(),
            'status' => 'completed',
        ]);
        $sale->updatePaymentStatus();

        return $sale->fresh();
    }

    private function staffWith(array $permissions, ?string $pin = null): User
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }
        $staff = User::factory()->create(['pin_code' => $pin]);
        $staff->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $role = Role::create(['name' => 'role-'.uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo($permissions);
        $staff->assignRole($role);

        return $staff;
    }

    private function headers(): array
    {
        return ['X-Business-Id' => $this->business->id];
    }

    public function test_deposit_without_an_item_stays_partial_and_cannot_be_completed(): void
    {
        $this->openShift();
        $sale = $this->itemlessDeposit(500);

        $this->assertSame('partial', $sale->payment_status);

        $this->actingAs($this->user)
            ->postJson("/api/sales/{$sale->id}/complete-deposit", [], $this->headers())
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Choose an item for this deposit first']);
    }

    public function test_choosing_and_changing_the_item_uses_branch_price_and_logs_each_change(): void
    {
        $this->openShift();
        $sale = $this->itemlessDeposit(500);

        $first = $this->actingAs($this->user)->putJson("/api/sales/{$sale->id}/deposit-items", [
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ], $this->headers());

        $first->assertStatus(200);
        $this->assertEquals(2000, (float) $first->json('sale.total_amount'));
        $this->assertSame('partial', $first->json('sale.payment_status'));

        $laptop = Product::create([
            'business_id' => $this->business->id,
            'name' => 'Laptop',
            'sku' => 'LAP-001',
            'selling_price' => 400,
            'base_selling_price' => 400,
            'is_active' => true,
        ]);
        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $laptop->id,
            'stock_quantity' => 3,
            'selling_price' => 400,
        ]);

        $second = $this->actingAs($this->user)->putJson("/api/sales/{$sale->id}/deposit-items", [
            'items' => [['product_id' => $laptop->id, 'quantity' => 1]],
        ], $this->headers());

        $second->assertStatus(200);
        $this->assertEquals(400, (float) $second->json('sale.total_amount'));
        $this->assertSame('overpaid', $second->json('sale.payment_status'));
        $this->assertCount(1, $second->json('sale.items'));

        $changes = Sale::find($sale->id)->metadata['item_changes'];
        $this->assertCount(2, $changes);
        $this->assertSame([], $changes[0]['from']);
        $this->assertSame('Phone', $changes[0]['to'][0]['name']);
        $this->assertSame('Phone', $changes[1]['from'][0]['name']);
        $this->assertSame('Laptop', $changes[1]['to'][0]['name']);
        $this->assertSame($this->user->id, $changes[1]['user_id']);

        // Nothing leaves the shelf until the deposit is completed
        $this->assertSame(10.0, (float) BranchProduct::where('product_id', $this->product->id)->first()->stock_quantity);
    }

    public function test_choosing_an_item_not_sold_at_the_branch_is_refused(): void
    {
        $sale = $this->itemlessDeposit(500);
        $elsewhere = Product::create([
            'business_id' => $this->business->id,
            'name' => 'TV',
            'sku' => 'TV-001',
            'selling_price' => 900,
            'base_selling_price' => 900,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)->putJson("/api/sales/{$sale->id}/deposit-items", [
            'items' => [['product_id' => $elsewhere->id, 'quantity' => 1]],
        ], $this->headers())->assertStatus(422);

        $this->assertSame(0, Sale::find($sale->id)->items()->count());
    }

    public function test_item_cannot_be_changed_on_a_deposit_holding_its_stock(): void
    {
        $this->setDepositMode('reserve_on_create');
        $this->openShift();
        $saleId = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'sale_type' => 'deposit',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1000]],
            'payments' => [['payment_method_id' => $this->cashMethod->id, 'amount' => 200]],
        ], $this->headers())->json('sale.id');

        $this->actingAs($this->user)->putJson("/api/sales/{$saleId}/deposit-items", [
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ], $this->headers())->assertStatus(422);
    }

    public function test_completing_a_deposit_for_a_cheaper_item_gives_the_change_back_from_this_shift(): void
    {
        $earlierShift = $this->openShift();
        $sale = $this->itemlessDeposit(1500, $earlierShift);
        $earlierShift->update(['status' => 'closed']);
        $shift = $this->openShift();

        $this->actingAs($this->user)->putJson("/api/sales/{$sale->id}/deposit-items", [
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ], $this->headers())->assertStatus(200);

        $this->actingAs($this->user)
            ->postJson("/api/sales/{$sale->id}/complete-deposit", [], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('change_due', 500);

        $complete = $this->actingAs($this->user)->postJson("/api/sales/{$sale->id}/complete-deposit", [
            'change_payment_method_id' => $this->cashMethod->id,
        ], $this->headers());

        $complete->assertStatus(200);
        $this->assertEquals(500, $complete->json('change_given'));
        $this->assertSame('completed', $complete->json('sale.status'));
        $this->assertSame('paid', $complete->json('sale.payment_status'));
        $this->assertEquals(1000, (float) $complete->json('sale.paid_amount'));

        $this->assertDatabaseHas('payments', [
            'sale_id' => $sale->id,
            'shift_id' => $shift->id,
            'amount' => '-500.00',
            'notes' => 'Change given back',
        ]);
        $this->assertEquals(1500.0, $earlierShift->fresh()->computeMetricsFromShiftPayments()['cash_sales']);
        $this->assertEquals(-500.0, $shift->fresh()->computeMetricsFromShiftPayments()['cash_sales']);
        $this->assertSame(9.0, (float) BranchProduct::where('product_id', $this->product->id)->first()->stock_quantity);
    }

    public function test_another_cashier_can_top_up_and_finish_a_deposit(): void
    {
        $this->openShift();
        $sale = $this->itemlessDeposit(400);
        $cashier = $this->staffWith(['create sales', 'view sales']);
        SalesShift::create([
            'shift_number' => 'SHIFT-'.uniqid(),
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $cashier->id,
            'start_time' => now(),
            'opening_balance' => 0,
            'status' => 'open',
        ]);

        $this->actingAs($cashier)->putJson("/api/sales/{$sale->id}/deposit-items", [
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ], $this->headers())->assertStatus(200);

        $this->actingAs($cashier)->postJson("/api/sales/{$sale->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 100,
        ], $this->headers())->assertStatus(200);

        $this->actingAs($cashier)->postJson("/api/sales/{$sale->id}/complete-deposit", [
            'payments' => [['payment_method_id' => $this->cashMethod->id, 'amount' => 500]],
        ], $this->headers())->assertStatus(200)->assertJsonPath('sale.status', 'completed');
    }

    public function test_cancelling_a_deposit_gives_all_money_back(): void
    {
        $earlierShift = $this->openShift();
        $sale = $this->itemlessDeposit(700, $earlierShift);
        $earlierShift->update(['status' => 'closed']);
        $shift = $this->openShift();

        $cancel = $this->actingAs($this->user)->postJson("/api/sales/{$sale->id}/cancel-deposit", [
            'payment_method_id' => $this->cashMethod->id,
            'reason' => 'Changed mind',
        ], $this->headers());

        $cancel->assertStatus(200);
        $this->assertEquals(700, $cancel->json('money_given_back'));
        $this->assertSame('refunded', $cancel->json('sale.status'));
        $this->assertEquals(0, (float) $cancel->json('sale.paid_amount'));
        $this->assertSame($this->user->id, Sale::find($sale->id)->metadata['cancelled']['user_id']);

        // The money stays on the shift that took it and leaves from the current one
        $this->assertEquals(700.0, $earlierShift->fresh()->computeMetricsFromShiftPayments()['cash_sales']);
        $this->assertEquals(-700.0, $shift->fresh()->computeMetricsFromShiftPayments()['cash_sales']);

        $this->actingAs($this->user)->postJson("/api/sales/{$sale->id}/cancel-deposit", [
            'payment_method_id' => $this->cashMethod->id,
        ], $this->headers())->assertStatus(422);
    }

    public function test_search_finds_open_deposits_by_reference_phone_or_name(): void
    {
        $this->customer->update(['phone' => '+234 803 123 4567']);
        $open = $this->itemlessDeposit(500);
        $finished = $this->itemlessDeposit(500);
        $finished->update(['status' => 'completed']);

        foreach (['08031234567', 'john', $open->sale_number, substr($open->sale_number, -6)] as $term) {
            $res = $this->actingAs($this->user)->getJson('/api/sales/deposits/search?q='.urlencode($term), $this->headers());
            $res->assertStatus(200);
            $this->assertSame([$open->id], array_column($res->json('deposits'), 'id'), "search: {$term}");
        }

        $this->actingAs($this->user)->getJson('/api/sales/deposits/search?q=nobody', $this->headers())
            ->assertStatus(200)
            ->assertJsonCount(0, 'deposits');
    }
}
