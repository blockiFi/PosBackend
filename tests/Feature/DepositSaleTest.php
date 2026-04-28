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
}
