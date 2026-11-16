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

    public function test_ledger_counts_finished_deposits_as_sales_and_keeps_open_ones_on_their_own_line(): void
    {
        [$shiftA, $shiftB] = $this->depositScenario();
        $this->handOverOn('DEP-1', $shiftA, $shiftB);
        $electronics = DeviceGroup::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id, 'name' => 'ELECTRONICS', 'code' => 'ELE', 'is_active' => true,
        ]);
        $shiftA->update(['group_id' => $electronics->id]);
        // A normal cash sale on shift B, which has no section
        $this->sale('SALE-2', 60, 'completed', $shiftB, [[$this->cashMethod, 60, $shiftB]]);

        // Shift A took DEP-1's first payment, but the item was handed over on B: it stays a deposit on A
        $a = ShiftMoney::summary($shiftA);
        $this->assertEquals(260000, $a['sales_total']);
        $this->assertEquals(260000, $a['sales_transfer_card']);
        $this->assertEquals(0, $a['sales_cash']);
        $this->assertEquals(0, $a['sales_paid_earlier']);
        $this->assertEquals(280000, $a['deposits_total']);
        $this->assertEquals(-50, $a['deposits_cash']);
        $this->assertEquals(280050, $a['deposits_transfer_card']);
        $this->assertEquals($a['money_received'], $a['sales_total'] + $a['deposits_total']);

        // Shift B handed DEP-1 over: a sale at full price, with what A took shown as paid earlier
        $b = ShiftMoney::summary($shiftB);
        $this->assertEquals(60, $b['sales_cash']);
        $this->assertEquals(130000, $b['sales_transfer_card']);
        $this->assertEquals(100000, $b['sales_paid_earlier']);
        $this->assertEquals(230060, $b['sales_total']);
        $this->assertEquals($b['items_sold'], $b['sales_total']);
        $this->assertEquals(100000, $b['deposits_total']);
        $this->assertEquals(1, $b['deposits_count']);
        $this->assertEquals($b['money_received'], $b['sales_total'] - $b['sales_paid_earlier'] + $b['deposits_total']);

        // A day with both shifts: DEP-1 was all paid inside it, so nothing was paid earlier
        $ledger = ShiftMoney::ledger(SalesShift::whereIn('id', [$shiftA->id, $shiftB->id]));
        $sections = collect($ledger['sections'])->keyBy(fn ($row) => $row['section_name'] ?? 'none');
        $this->assertEquals(['ELECTRONICS', 'none'], $sections->keys()->all());
        $this->assertEquals(260000, $sections['ELECTRONICS']['transfer_card']);
        $this->assertEquals(1, $sections['ELECTRONICS']['sales_count']);
        // DEP-1 sits under the Section of the till that handed it over
        $this->assertEquals(60, $sections['none']['cash']);
        $this->assertEquals(230000, $sections['none']['transfer_card']);
        $this->assertEquals(0, $sections['none']['paid_earlier']);
        $this->assertEquals(2, $sections['none']['sales_count']);
        $this->assertEquals(490060, $ledger['sales']['total']);
        $this->assertEquals(0, $ledger['sales']['paid_earlier']);
        $this->assertEquals(260060, $ledger['sales']['normal']);
        $this->assertEquals(['count' => 1, 'total' => 230000, 'paid_today' => 230000, 'paid_earlier' => 0],
            collect($ledger['deposits_collected'])->except('rows')->all());

        $deposits = collect($ledger['deposits']['rows'])->keyBy('sale_number');
        $this->assertEquals(['DEP-2', 'DEP-3'], $deposits->keys()->sort()->values()->all());
        $this->assertEquals(280000, $deposits['DEP-2']['total']);
        $this->assertEquals(0, $deposits['DEP-3']['total']);
        $this->assertEquals(280000, $ledger['deposits']['total']);
        $this->assertEquals(2, $ledger['deposits']['count']);
        $this->assertEquals($a['money_received'] + $b['money_received'], $ledger['money_received']);
        $this->assertEquals(['sales' => 360000, 'deposits' => 180000, 'returns' => 0], $ledger['shifts'][$shiftA->id]);
        $this->assertEquals(['sales' => 130060, 'deposits' => 100000, 'returns' => 0], $ledger['shifts'][$shiftB->id]);

        // The day of shift A alone does not change once DEP-1 is finished later
        $dayA = ShiftMoney::ledger(SalesShift::whereIn('id', [$shiftA->id]));
        $this->assertEquals(260000, $dayA['sales']['total']);
        $this->assertEquals(100000, collect($dayA['deposits']['rows'])->keyBy('sale_number')['DEP-1']['total']);
        $this->assertEquals(280000, $dayA['deposits']['total']);
        $this->assertEquals($a['money_received'], $dayA['money_received']);

        // The day of shift B alone: DEP-1 at full price, A's 100,000 as paid earlier
        $dayB = ShiftMoney::ledger(SalesShift::whereIn('id', [$shiftB->id]));
        $this->assertEquals(['cash' => 60, 'transfer_card' => 130000, 'paid_earlier' => 100000, 'total' => 230060, 'normal' => 60, 'from_deposits' => 230000], $dayB['sales']);
        $this->assertEquals(100000, $dayB['deposits']['total']);
        $this->assertEquals($b['money_received'], $dayB['money_received']);
        $this->assertEquals(['sales' => 230060, 'deposits' => 100000, 'returns' => 0], $dayB['shifts'][$shiftB->id]);
        // How the day adds up: normal sales + deposits collected = Sales
        $this->assertEquals(60, $dayB['sales']['normal']);
        $this->assertEquals(230000, $dayB['sales']['from_deposits']);
        $this->assertEquals(1, $dayB['deposits_collected']['count']);
        $collected = $dayB['deposits_collected']['rows'][0];
        $this->assertEquals('DEP-1', $collected['sale_number']);
        $this->assertEquals(230000, $collected['total']);
        $this->assertEquals(130000, $collected['paid_today']);
        $this->assertEquals(100000, $collected['paid_earlier']);
        $this->assertEquals(1, $b['deposits_collected_count']);
        $this->assertEquals(230000, $b['deposits_collected_total']);
        $this->assertEquals(0, $a['deposits_collected_count']);
        $this->assertEquals(0, $dayA['deposits_collected']['count']);
        $this->assertEquals(260000, $dayA['sales']['normal']);

        // Shifts & Sales list carries the same ledger
        $this->actingAs($this->user)->getJson('/api/shifts?branch_id='.$this->branch->id, ['X-Business-Id' => $this->business->id])
            ->assertOk()
            ->assertJsonPath('statistics.ledger.deposits.total', 280000)
            ->assertJsonPath('statistics.ledger.sales.total', 490060);
    }

    public function test_deposit_fully_paid_earlier_counts_its_whole_price_as_paid_earlier(): void
    {
        $shiftB = SalesShift::create([
            'shift_number' => 'SHIFT-B',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now(),
            'opening_balance' => 0,
            'status' => 'open',
        ]);
        // Paid in cash on shift A, collected on B with nothing more to pay
        $this->sale('DEP-9', 34000, 'completed', $this->shift, [[$this->cashMethod, 34000, $this->shift]], 'deposit');
        $this->handOverOn('DEP-9', $this->shift, $shiftB);

        $b = ShiftMoney::summary($shiftB);
        $this->assertEquals(34000, $b['sales_total']);
        $this->assertEquals(34000, $b['sales_paid_earlier']);
        $this->assertEquals(0, $b['sales_cash']);
        $this->assertEquals(0, $b['money_received']);

        $dayB = ShiftMoney::ledger(SalesShift::whereIn('id', [$shiftB->id]));
        $this->assertEquals(['cash' => 0, 'transfer_card' => 0, 'paid_earlier' => 34000, 'total' => 34000, 'normal' => 0, 'from_deposits' => 34000], $dayB['sales']);
        $this->assertEquals(1, $dayB['sections'][0]['sales_count']);
        $this->assertEquals(0, $dayB['deposits']['total']);
        $this->assertEquals(0, $dayB['money_received']);

        // The cash stays on shift A's day under Deposits
        $dayA = ShiftMoney::ledger(SalesShift::whereIn('id', [$this->shift->id]));
        $this->assertEquals(34000, $dayA['deposits']['cash']);
        $this->assertEquals(0, $dayA['sales']['total']);
    }

    /** What complete-deposit does: the sale moves to the shift that handed the item over. */
    private function handOverOn(string $number, SalesShift $startedOn, SalesShift $handedOverOn): void
    {
        Sale::where('sale_number', $number)->firstOrFail()->update([
            'shift_id' => $handedOverOn->id,
            'metadata' => ['deposit_started_shift_id' => $startedOn->id, 'deposit_completed_shift_id' => $handedOverOn->id],
        ]);
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
