<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Customer;
use App\Models\DeviceGroup;
use App\Models\ManagerActivity;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesShift;
use App\Models\StockWriteoff;
use App\Models\User;
use App\Support\SalesProfit;
use App\Support\ShiftMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Returns and swaps on the cashier's device with a manager's PIN: the old sale stays on its own
 * day and shift; money, stock and Items sold move on the shift where the return happened.
 */
class SaleReturnTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private User $cashier;

    private Business $business;

    private Branch $branch;

    private PaymentMethod $cash;

    private PaymentMethod $transfer;

    private Product $shirt;

    private Product $trouser;

    private SalesShift $oldShift;

    private SalesShift $shift;

    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['create sales', 'view sales', 'manage sales', 'view analytics', 'view all shifts'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->owner = User::factory()->create(['pin_code' => '111111']);
        $this->business = Business::create(['name' => 'Shop', 'owner_id' => $this->owner->id]);
        $this->branch = Branch::create([
            'name' => 'Main', 'code' => 'MAIN', 'business_id' => $this->business->id, 'address' => 'x',
        ]);

        $this->manager = User::factory()->create(['pin_code' => '222222', 'name' => 'Yusuf Sambo']);
        $this->cashier = User::factory()->create(['pin_code' => '333333', 'name' => 'Fatima']);
        foreach ([$this->owner, $this->manager, $this->cashier] as $user) {
            $user->businesses()->attach($this->business->id, ['is_active' => true]);
        }

        setPermissionsTeamId($this->business->id);
        $managerRole = Role::create(['name' => 'Manager', 'guard_name' => 'api', 'business_id' => $this->business->id]);
        $managerRole->givePermissionTo(['create sales', 'view sales', 'manage sales', 'view analytics', 'view all shifts']);
        $cashierRole = Role::create(['name' => 'Cashier', 'guard_name' => 'api', 'business_id' => $this->business->id]);
        $cashierRole->givePermissionTo(['create sales']);
        $ownerRole = Role::create(['name' => 'Owner', 'guard_name' => 'api', 'business_id' => $this->business->id]);
        $ownerRole->givePermissionTo(['create sales', 'view sales', 'manage sales', 'view analytics', 'view all shifts']);
        foreach ([[$this->owner, $ownerRole], [$this->manager, $managerRole], [$this->cashier, $cashierRole]] as [$user, $role]) {
            DB::table('model_has_roles')->insert([
                'role_id' => $role->id,
                'model_type' => User::class,
                'model_id' => $user->id,
                'business_id' => $this->business->id,
            ]);
        }

        $this->cash = PaymentMethod::create(['business_id' => $this->business->id, 'name' => 'Cash', 'type' => 'cash', 'is_active' => true]);
        $this->transfer = PaymentMethod::create(['business_id' => $this->business->id, 'name' => 'Transfer', 'type' => 'bank_transfer', 'is_active' => true]);

        $this->shirt = $this->product('Blue shirt', 'SHIRT', 7000, 10000);
        $this->trouser = $this->product('Black trouser', 'TROUSER', 9000, 15000);

        $clothing = DeviceGroup::create(['business_id' => $this->business->id, 'branch_id' => $this->branch->id, 'name' => 'Clothing', 'code' => 'CLO']);

        // Sold 10 Nov on another cashier's shift, already closed
        $this->oldShift = SalesShift::create([
            'shift_number' => 'SHIFT-OLD', 'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'user_id' => $this->owner->id, 'group_id' => $clothing->id, 'start_time' => now()->subDays(2)->subHours(8),
            'end_time' => now()->subDays(2), 'opening_balance' => 0, 'status' => 'closed',
        ]);
        $this->shift = SalesShift::create([
            'shift_number' => 'SHIFT-NOW', 'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id, 'group_id' => $clothing->id, 'device_id' => 'device-1',
            'start_time' => now()->subHour(), 'opening_balance' => 5000, 'status' => 'open',
        ]);

        $customer = Customer::create(['business_id' => $this->business->id, 'customer_code' => 'C1', 'name' => 'Amina', 'phone' => '08031234567']);
        $this->sale = Sale::create([
            'sale_number' => 'SAL-20261110-0001', 'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'customer_id' => $customer->id, 'user_id' => $this->owner->id, 'shift_id' => $this->oldShift->id,
            'sale_date' => now()->subDays(2)->subHours(2), 'subtotal' => 20000, 'total_amount' => 20000, 'paid_amount' => 20000,
            'status' => 'completed', 'payment_status' => 'paid', 'sale_type' => 'pos',
        ]);
        SaleItem::create([
            'sale_id' => $this->sale->id, 'product_id' => $this->shirt->id, 'product_name' => 'Blue shirt',
            'quantity' => 2, 'unit_price' => 10000, 'subtotal' => 20000, 'total' => 20000,
        ]);
        Payment::create([
            'sale_id' => $this->sale->id, 'shift_id' => $this->oldShift->id, 'payment_method_id' => $this->cash->id,
            'amount' => 20000, 'payment_date' => now()->subDays(2)->subHours(2), 'status' => 'completed',
        ]);
        $this->oldShift->recountAfterClose();
    }

    private function product(string $name, string $sku, float $cost, float $price): Product
    {
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => $name, 'sku' => $sku,
            'cost_price' => $cost, 'selling_price' => $price, 'base_selling_price' => $price, 'is_active' => true,
        ]);
        BranchProduct::create([
            'branch_id' => $this->branch->id, 'product_id' => $product->id, 'stock_quantity' => 5,
            'shelf_quantity' => 3, 'store_quantity' => 2, 'cost_price' => $cost, 'selling_price' => $price,
        ]);

        return $product;
    }

    private function headers(): array
    {
        return ['X-Business-Id' => $this->business->id];
    }

    private function itemId(): int
    {
        return (int) $this->sale->items()->value('id');
    }

    private function sendReturn(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->cashier)->postJson('/api/returns', array_merge([
            'sale_id' => $this->sale->id,
            'items' => [['sale_item_id' => $this->itemId(), 'quantity' => 1, 'condition' => 'good']],
            'payment_method_id' => $this->cash->id,
            'reason' => 'Wrong size',
            'manager_pin' => '222222',
        ], $overrides), $this->headers());
    }

    private function shelf(Product $product): float
    {
        return (float) BranchProduct::where('product_id', $product->id)->value('shelf_quantity');
    }

    public function test_money_back_comes_out_of_todays_shift_and_the_old_sale_keeps_its_day(): void
    {
        $oldBefore = ShiftMoney::summary($this->oldShift);

        $response = $this->sendReturn()->assertCreated();
        $response->assertJsonPath('return.kind', 'refund')
            ->assertJsonPath('return.money_back', 10000)
            ->assertJsonPath('return.money_in', 0)
            ->assertJsonPath('return.manager.name', 'Yusuf Sambo')
            ->assertJsonPath('return.manager.role', 'Manager')
            ->assertJsonPath('return.cashier.name', 'Fatima')
            ->assertJsonPath('return.items_back.0.condition', 'good')
            ->assertJsonPath('return.of_sale.sale_number', 'SAL-20261110-0001');

        // Old shift and old sale are unchanged (the book page for that day stays right)
        $this->assertEquals($oldBefore, ShiftMoney::summary($this->oldShift->fresh()));
        $this->sale->refresh();
        $this->assertSame('completed', $this->sale->status);
        $this->assertEquals(10000, (float) $this->sale->refunded_amount);

        // Today's shift: ₦10,000 cash out, shown as a return, Items sold down by the shirt
        $now = ShiftMoney::summary($this->shift);
        $this->assertEquals(-10000, $now['cash']);
        $this->assertEquals(-10000, $now['money_received']);
        $this->assertEquals(10000, $now['returns_cash']);
        $this->assertEquals(10000, $now['returns_total']);
        $this->assertEquals(10000, $now['returns_value']);
        $this->assertEquals(1, $now['returns_count']);
        $this->assertEquals(-10000, $now['items_sold']);
        $this->assertEquals(0, $now['other_difference']);
        $this->assertEquals(0, $now['sales_total']);
        $this->assertEquals(-10000, $this->shift->computeMetricsFromShiftPayments()['cash_sales']);

        // The drawer: the cashier's device sends 5000 - 10000 = -5000 at close and it balances
        $this->shift->actual_cash = -5000;
        $this->shift->updateSalesMetrics();
        $this->shift->calculateExpectedCash();
        $this->shift->calculateVariance();
        $this->assertEquals(0, (float) $this->shift->variance);

        // Back on the shelf
        $this->assertEquals(4, $this->shelf($this->shirt));
        $this->assertEquals(6, (float) BranchProduct::where('product_id', $this->shirt->id)->value('stock_quantity'));

        // Profit on today's shift goes down by the profit made on the shirt (10000 - 7000)
        $profit = SalesProfit::total(fn ($q) => $q->where('sales.shift_id', $this->shift->id));
        $this->assertEquals(-3000, $profit['profit']);

        // The book view: its own Returns line, nothing taken off the Clothing section
        $ledger = ShiftMoney::ledger(SalesShift::whereKey($this->shift->id));
        $this->assertEquals(10000, $ledger['returns']['cash']);
        $this->assertEquals(10000, $ledger['returns']['total']);
        $this->assertEquals(1, $ledger['returns']['count']);
        $this->assertSame('SAL-20261110-0001', $ledger['returns']['rows'][0]['sale_number']);
        $this->assertSame('Clothing', $ledger['returns']['rows'][0]['section_name']);
        $this->assertEquals([], $ledger['sections']);
        $this->assertEquals(-10000, $ledger['money_received']);
        $this->assertEquals(10000, $ledger['shifts'][$this->shift->id]['returns']);

        // The owner sees who approved it
        $activity = ManagerActivity::where('action', 'approved_return')->first();
        $this->assertNotNull($activity);
        $this->assertSame($this->manager->id, $activity->user_id);
        $this->assertSame('Manager', $activity->role);
        $this->assertTrue($activity->pin_ok);
        $this->assertStringContainsString('₦10,000 given back', $activity->description);
        $this->assertStringContainsString('Fatima', $activity->description);
    }

    public function test_swap_for_dearer_item_takes_the_extra_money_as_a_sale(): void
    {
        $this->sendReturn([
            'new_items' => [['product_id' => $this->trouser->id, 'quantity' => 1]],
            'payment_method_id' => $this->transfer->id,
        ])->assertCreated()
            ->assertJsonPath('return.kind', 'swap')
            ->assertJsonPath('return.returned_value', 10000)
            ->assertJsonPath('return.new_value', 15000)
            ->assertJsonPath('return.money_in', 5000)
            ->assertJsonPath('return.money_back', 0)
            ->assertJsonPath('return.payment_method.name', 'Transfer');

        $this->assertEquals(4, $this->shelf($this->shirt));
        $this->assertEquals(2, $this->shelf($this->trouser));

        $now = ShiftMoney::summary($this->shift);
        $this->assertEquals(5000, $now['money_received']);
        $this->assertEquals(5000, $now['sales_transfer_card']);
        $this->assertEquals(0, $now['returns_total']);
        $this->assertEquals(5000, $now['returns_paid_more']);
        $this->assertEquals(5000, $now['items_sold']);
        $this->assertEquals(0, $now['other_difference']);

        // Profit: trouser (15000 - 9000) minus the shirt's (10000 - 7000)
        $profit = SalesProfit::total(fn ($q) => $q->where('sales.shift_id', $this->shift->id));
        $this->assertEquals(3000, $profit['profit']);

        $ledger = ShiftMoney::ledger(SalesShift::whereKey($this->shift->id));
        $this->assertEquals(5000, $ledger['sections'][0]['transfer_card']);
        $this->assertSame('1 × Black trouser', $ledger['returns']['rows'][0]['items_out']);
        $this->assertEquals(5000, $ledger['money_received']);
    }

    public function test_swap_for_cheaper_item_gives_the_balance_back(): void
    {
        $cheap = $this->product('Socks', 'SOCKS', 1000, 4000);

        $this->sendReturn(['new_items' => [['product_id' => $cheap->id, 'quantity' => 1]]])
            ->assertCreated()
            ->assertJsonPath('return.money_back', 6000)
            ->assertJsonPath('return.kind', 'swap');

        $now = ShiftMoney::summary($this->shift);
        $this->assertEquals(-6000, $now['cash']);
        $this->assertEquals(6000, $now['returns_cash']);
        $this->assertEquals(-6000, $now['items_sold']);
    }

    public function test_same_price_swap_needs_no_money(): void
    {
        $twin = $this->product('Red shirt', 'RSHIRT', 7000, 10000);

        $this->sendReturn([
            'new_items' => [['product_id' => $twin->id, 'quantity' => 1]],
            'payment_method_id' => null,
        ])->assertCreated()
            ->assertJsonPath('return.money_back', 0)
            ->assertJsonPath('return.money_in', 0)
            ->assertJsonPath('return.payment_method', null);

        $this->assertSame(0, Payment::where('shift_id', $this->shift->id)->count());
        $ledger = ShiftMoney::ledger(SalesShift::whereKey($this->shift->id));
        $this->assertEquals(1, $ledger['returns']['count']);
        $this->assertEquals(0, $ledger['returns']['total']);
    }

    public function test_damaged_item_is_written_off_not_put_back(): void
    {
        $this->sendReturn(['items' => [['sale_item_id' => $this->itemId(), 'quantity' => 1, 'condition' => 'damaged']]])
            ->assertCreated()
            ->assertJsonPath('return.items_back.0.condition', 'damaged');

        $this->assertEquals(3, $this->shelf($this->shirt));
        $writeoff = StockWriteoff::first();
        $this->assertNotNull($writeoff);
        $this->assertSame(1, $writeoff->quantity);
        $this->assertSame('return', $writeoff->source);
        $this->assertStringContainsString('SAL-20261110-0001', $writeoff->reason);
    }

    public function test_items_cannot_come_back_twice(): void
    {
        $this->sendReturn(['items' => [['sale_item_id' => $this->itemId(), 'quantity' => 3, 'condition' => 'good']]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only 2 of Blue shirt can still be returned.');

        $this->sendReturn()->assertCreated();
        $this->sendReturn()->assertCreated();
        $this->sendReturn()
            ->assertStatus(422)
            ->assertJsonPath('message', 'Blue shirt was already returned.');

        $this->assertEquals(20000, (float) $this->sale->fresh()->refunded_amount);
        $this->assertTrue((bool) $this->sale->fresh()->is_refunded);

        $found = $this->actingAs($this->cashier)
            ->getJson('/api/returns/sales/'.$this->sale->id, $this->headers())
            ->assertOk();
        $found->assertJsonPath('data.items.0.returned_quantity', 2)
            ->assertJsonPath('data.items.0.returnable_quantity', 0);
        $this->assertCount(2, $found->json('data.returns'));
    }

    public function test_the_same_return_sent_twice_is_saved_once(): void
    {
        $first = $this->sendReturn(['client_uuid' => 'aaaaaaaa-0000-0000-0000-000000000001'])->assertCreated();
        $again = $this->sendReturn(['client_uuid' => 'aaaaaaaa-0000-0000-0000-000000000001'])->assertOk();

        $this->assertSame($first->json('return.id'), $again->json('return.id'));
        $this->assertSame(1, Sale::where('sale_type', 'return')->count());
        $this->assertEquals(4, $this->shelf($this->shirt));
    }

    public function test_needs_a_managers_own_pin(): void
    {
        $this->sendReturn(['manager_pin' => '999999'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'pin_wrong');

        // The cashier's own PIN is not a manager's
        $this->sendReturn(['manager_pin' => '333333'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_manager');

        $this->assertSame(0, Sale::where('sale_type', 'return')->count());

        // The owner's PIN works too
        $this->sendReturn(['manager_pin' => '111111'])
            ->assertCreated()
            ->assertJsonPath('return.manager.role', 'Owner');
    }

    public function test_manager_pin_check_says_who_it_is(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/returns/manager-pin', [
            'pin' => '222222', 'branch_id' => $this->branch->id,
        ], $this->headers())->assertOk()->assertJsonPath('manager.name', 'Yusuf Sambo');

        $this->actingAs($this->cashier)->postJson('/api/returns/manager-pin', [
            'pin' => '123456', 'branch_id' => $this->branch->id,
        ], $this->headers())->assertStatus(422)->assertJsonPath('message', 'Wrong PIN.');
    }

    public function test_needs_an_open_shift(): void
    {
        $this->shift->update(['status' => 'paused']);
        $this->sendReturn()->assertStatus(400)->assertJsonPath('message', 'Your shift is paused. Resume it first.');

        $this->shift->update(['status' => 'closed']);
        $this->sendReturn()->assertStatus(400)->assertJsonPath('message', 'Start a shift first.');
    }

    public function test_money_back_needs_a_payment_method(): void
    {
        $this->sendReturn(['payment_method_id' => null])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose how the money is given back.');
    }

    public function test_cashier_finds_sales_by_receipt_item_phone_and_amount(): void
    {
        $search = fn (string $q) => $this->actingAs($this->cashier)
            ->getJson('/api/returns/sales?branch_id='.$this->branch->id.'&q='.urlencode($q), $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $search('0001'));
        $this->assertCount(1, $search('blue shirt'));
        $this->assertCount(1, $search('0803123'));
        $this->assertCount(1, $search('₦20,000'));
        $this->assertCount(0, $search('trouser'));

        $row = $search('SAL-20261110-0001')[0];
        $this->assertEquals(2, $row['days_old']);
        $this->assertEquals(10000, $row['items'][0]['unit_paid']);
        $this->assertEquals(2, $row['items'][0]['returnable_quantity']);
        $this->assertSame(['Cash'], $row['payment_methods']);

        // Returns themselves are not listed as sales to return
        $this->sendReturn()->assertCreated();
        $this->assertCount(1, $search(''));
    }

    public function test_owner_list_and_overview_count_returns_on_the_day_they_happen(): void
    {
        $this->sendReturn()->assertCreated();
        $this->sendReturn([
            'new_items' => [['product_id' => $this->trouser->id, 'quantity' => 1]],
            'payment_method_id' => $this->transfer->id,
        ])->assertCreated();

        $list = $this->actingAs($this->owner)->getJson('/api/returns', $this->headers())->assertOk();
        $list->assertJsonPath('summary.count', 2)
            ->assertJsonPath('summary.refunds_count', 1)
            ->assertJsonPath('summary.swaps_count', 1)
            ->assertJsonPath('summary.money_back', 10000)
            ->assertJsonPath('summary.money_in', 5000)
            ->assertJsonPath('summary.returned_value', 20000)
            ->assertJsonPath('data.0.kind', 'swap')
            ->assertJsonPath('data.0.shift.section', 'Clothing');

        $this->actingAs($this->owner)->getJson('/api/returns?kind=refund', $this->headers())
            ->assertOk()->assertJsonPath('meta.total', 1);

        $today = now()->format('Y-m-d');
        $sections = $this->actingAs($this->owner)->getJson(
            "/api/analytics/sections?start_date={$today}&end_date={$today}",
            $this->headers()
        )->assertOk();
        $sections->assertJsonPath('returns.count', 2)
            ->assertJsonPath('returns.returned_value', 20000)
            ->assertJsonPath('returns.swaps_value', 15000)
            ->assertJsonPath('returns.net', -5000)
            ->assertJsonPath('normal_sales', 0)
            ->assertJsonPath('sections.0.returns_value', 20000)
            ->assertJsonPath('sections.0.revenue', -5000);
    }
}
