<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\DeviceGroup;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalesShift;
use App\Models\User;
use App\Support\ShiftMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Shift page cards: money received vs items sold, and rows that add up to them. */
class ShiftMoneyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    private Branch $branch;

    private SalesShift $shift;

    private PaymentMethod $cashMethod;

    private PaymentMethod $cardMethod;

    protected function setUp(): void
    {
        parent::setUp();

        // Create business
        $this->business = Business::factory()->create();

        // Create branch
        $this->branch = Branch::factory()->create([
            'business_id' => $this->business->id,
        ]);

        // Create user
        $this->user = User::factory()->create();
        $this->user->businesses()->attach($this->business->id, ['is_active' => true]);

        // Create permission and role (controller checks 'view all shifts' or 'view user shift')
        Permission::firstOrCreate(['name' => 'view all shifts', 'guard_name' => 'api']);
        $role = Role::create([
            'name' => 'Manager',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $role->givePermissionTo('view all shifts');

        // Assign role to user
        \Illuminate\Support\Facades\DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => 'App\Models\User',
            'model_id' => $this->user->id,
            'business_id' => $this->business->id,
        ]);

        // Create payment methods
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

        // Create a shift
        $this->shift = SalesShift::create([
            'shift_number' => 'SHIFT-A',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subHours(8),
            'end_time' => now(),
            'opening_balance' => 100.00,
            'status' => 'closed',
        ]);
    }

    public function test_money_received_and_items_sold_reconcile_with_deposits(): void
    {
        [$shiftA, $shiftB] = $this->depositScenario();

        $a = ShiftMoney::summary($shiftA);
        $this->assertEquals(540000, $a['money_received']);
        $this->assertEquals(-50, $a['cash']);
        $this->assertEquals(360050, $a['card']);
        $this->assertEquals(180000, $a['other']);
        $this->assertEquals(490000, $a['items_sold']);
        $this->assertEquals(2, $a['items_sold_count']);
        $this->assertEquals(180000, $a['unfinished_deposits']);
        $this->assertEquals(0, $a['older_deposit_balances']);
        $this->assertEquals(130000, $a['paid_on_other_shifts']);
        $this->assertEquals(0, $a['other_difference']);
        // Same money as the stored shift totals the Shifts & Sales list shows
        $this->assertEquals($shiftA->computeMetricsFromShiftPayments()['total_sales'], $a['money_received']);

        $b = ShiftMoney::summary($shiftB);
        $this->assertEquals(230000, $b['money_received']);
        $this->assertEquals(0, $b['items_sold']);
        $this->assertEquals(230000, $b['older_deposit_balances']);
        $this->assertEquals(0, $b['other_difference']);
        $this->assertEquals($shiftB->computeMetricsFromShiftPayments()['total_sales'], $b['money_received']);
    }

    public function test_shift_endpoints_return_money_and_rows_that_add_up(): void
    {
        [$shiftA, $shiftB] = $this->depositScenario();
        $headers = ['X-Business-Id' => $this->business->id];

        $this->actingAs($this->user)->getJson('/api/shifts/'.$shiftA->id, $headers)
            ->assertOk()
            ->assertJsonPath('statistics.money.money_received', 540000)
            ->assertJsonPath('statistics.money.items_sold', 490000);

        $rows = collect($this->actingAs($this->user)->getJson('/api/shifts/'.$shiftA->id.'/sales', $headers)
            ->assertOk()->json('data'))->keyBy('sale_number');
        $this->assertEquals(540000, $rows->sum('received_on_shift'));
        $this->assertEquals(490000, $rows->sum('item_value'));
        $this->assertEquals('deposit_done', $rows['DEP-1']['kind']);
        $this->assertEquals(100000, $rows['DEP-1']['received_on_shift']);
        $this->assertEquals(130000, $rows['DEP-1']['paid_on_other_shifts']);
        $this->assertEquals('deposit_waiting', $rows['DEP-2']['kind']);
        $this->assertEquals('refunded', $rows['DEP-3']['kind']);
        $this->assertEquals('voided', $rows['SALE-VOID']['kind']);
        $this->assertEquals(0, $rows['SALE-VOID']['received_on_shift']);

        // Shift B lists the older deposits whose balance it took
        $rowsB = collect($this->actingAs($this->user)->getJson('/api/shifts/'.$shiftB->id.'/sales', $headers)
            ->assertOk()->json('data'))->keyBy('sale_number');
        $this->assertEquals(['DEP-1', 'DEP-2'], $rowsB->keys()->sort()->values()->all());
        $this->assertEquals('older_deposit', $rowsB['DEP-1']['kind']);
        $this->assertTrue($rowsB['DEP-1']['from_other_shift']);
        $this->assertEquals(230000, $rowsB->sum('received_on_shift'));
        $this->assertEquals(0, $rowsB->sum('item_value'));
    }

    public function test_ledger_splits_sales_by_section_and_keeps_deposits_on_their_own_line(): void
    {
        [$shiftA, $shiftB] = $this->depositScenario();
        $electronics = DeviceGroup::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id, 'name' => 'ELECTRONICS', 'code' => 'ELE', 'is_active' => true,
        ]);
        $shiftA->update(['group_id' => $electronics->id]);
        // A normal cash sale on shift B, which has no section
        $this->sale('SALE-2', 60, 'completed', $shiftB, [[$this->cashMethod, 60, $shiftB]]);

        // Per shift: every naira of a deposit, even the balance, sits under deposits
        $a = ShiftMoney::summary($shiftA);
        $this->assertEquals(260000, $a['sales_total']);
        $this->assertEquals(260000, $a['sales_transfer_card']);
        $this->assertEquals(0, $a['sales_cash']);
        $this->assertEquals(280000, $a['deposits_total']);
        $this->assertEquals(-50, $a['deposits_cash']);
        $this->assertEquals(280050, $a['deposits_transfer_card']);
        $this->assertEquals($a['money_received'], $a['sales_total'] + $a['deposits_total']);
        $b = ShiftMoney::summary($shiftB);
        $this->assertEquals(60, $b['sales_total']);
        $this->assertEquals(230000, $b['deposits_total']);
        $this->assertEquals(2, $b['deposits_count']);

        $ledger = ShiftMoney::ledger(SalesShift::whereIn('id', [$shiftA->id, $shiftB->id]));
        $sections = collect($ledger['sections'])->keyBy(fn ($row) => $row['section_name'] ?? 'none');
        $this->assertEquals(['ELECTRONICS', 'none'], $sections->keys()->all());
        $this->assertEquals(260000, $sections['ELECTRONICS']['transfer_card']);
        $this->assertEquals(1, $sections['ELECTRONICS']['sales_count']);
        $this->assertEquals(60, $sections['none']['cash']);
        $this->assertEquals(260060, $ledger['sales']['total']);

        $deposits = collect($ledger['deposits']['rows'])->keyBy('sale_number');
        $this->assertEquals(230000, $deposits['DEP-1']['total']);
        $this->assertEquals(280000, $deposits['DEP-2']['total']);
        $this->assertEquals(0, $deposits['DEP-3']['total']);
        $this->assertEquals('ELECTRONICS', $deposits['DEP-1']['section_name']);
        $this->assertEquals(510000, $ledger['deposits']['total']);
        $this->assertArrayNotHasKey('SALE-VOID', $deposits->all());
        $this->assertEquals($a['money_received'] + $b['money_received'], $ledger['money_received']);
        $this->assertEquals(['sales' => 260000, 'deposits' => 280000], $ledger['shifts'][$shiftA->id]);
        $this->assertEquals(['sales' => 60, 'deposits' => 230000], $ledger['shifts'][$shiftB->id]);

        // Shifts & Sales list carries the same ledger
        $this->actingAs($this->user)->getJson('/api/shifts?branch_id='.$this->branch->id, ['X-Business-Id' => $this->business->id])
            ->assertOk()
            ->assertJsonPath('statistics.ledger.deposits.total', 510000)
            ->assertJsonPath('statistics.ledger.sales.total', 260060);
    }

    /** @return array{0: SalesShift, 1: SalesShift} */
    private function depositScenario(): array
    {
        $transfer = PaymentMethod::create([
            'business_id' => $this->business->id, 'name' => 'Transfer', 'type' => 'bank_transfer', 'is_active' => true,
        ]);
        $shiftA = $this->shift;
        $shiftB = SalesShift::create([
            'shift_number' => 'SHIFT-B',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now(),
            'opening_balance' => 0,
            'status' => 'open',
        ]);

        $this->sale('SALE-1', 260000, 'completed', $shiftA, [[$this->cardMethod, 260000, $shiftA]]);
        // Deposit started on A, balance paid and item given on B
        $this->sale('DEP-1', 230000, 'completed', $shiftA, [[$this->cardMethod, 100000, $shiftA], [$this->cardMethod, 130000, $shiftB]], 'deposit');
        // Deposit with no item yet, topped up on B
        $this->sale('DEP-2', 0, 'pending', $shiftA, [[$transfer, 180000, $shiftA], [$transfer, 100000, $shiftB]], 'deposit');
        // Deposit cancelled, money given back
        $this->sale('DEP-3', 50, 'refunded', $shiftA, [[$this->cardMethod, 50, $shiftA], [$this->cashMethod, -50, $shiftA]], 'deposit');
        $this->sale('SALE-VOID', 999, 'completed', $shiftA, [[$this->cashMethod, 999, $shiftA]])->delete();

        return [$shiftA, $shiftB];
    }

    /** @param array<int, array{0: PaymentMethod, 1: float, 2: SalesShift}> $payments */
    private function sale(string $number, float $total, string $status, SalesShift $shift, array $payments, string $type = 'pos'): Sale
    {
        $sale = Sale::create([
            'sale_number' => $number,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_id' => $shift->id,
            'user_id' => $this->user->id,
            'sale_date' => now(),
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $total,
            'status' => $status,
            'sale_type' => $type,
        ]);

        foreach ($payments as [$method, $amount, $paidOn]) {
            Payment::create([
                'business_id' => $this->business->id,
                'sale_id' => $sale->id,
                'shift_id' => $paidOn->id,
                'payment_method_id' => $method->id,
                'amount' => $amount,
                'payment_date' => now(),
                'status' => 'completed',
            ]);
        }

        return $sale;
    }
}
