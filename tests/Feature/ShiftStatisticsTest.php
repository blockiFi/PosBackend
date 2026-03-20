<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\SalesShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShiftStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    private Branch $branch;

    private SalesShift $shift;

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

        // Create a shift with sales data
        $this->shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260208-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subHours(8),
            'end_time' => now(),
            'opening_balance' => 100.00,
            'cash_sales' => 300.00,
            'card_sales' => 700.00,
            'total_sales' => 1000.00,
            'transactions_count' => 20,
            'expected_cash' => 400.00,
            'actual_cash' => 400.00,
            'variance' => 0.00,
            'status' => 'closed',
        ]);
    }

    public function test_can_view_shifts_with_statistics(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/shifts', [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'shifts' => [
                    'data' => [
                        '*' => [
                            'id',
                            'shift_number',
                            'total_sales',
                            'transactions_count',
                            'cash_sales',
                            'card_sales',
                            'variance',
                            'status',
                            'statistics' => [
                                'gross_sales',
                                'total_transactions',
                                'average_basket_value',
                                'payment_breakdown' => [
                                    'pos_percentage',
                                    'cash_percentage',
                                    'pos_amount',
                                    'cash_amount',
                                ],
                                'reconciliation_status',
                                'variance',
                            ],
                        ],
                    ],
                ],
                'statistics',
            ]);

        $shiftData = $response->json('shifts.data.0');

        // Verify statistics calculations
        $this->assertEquals(1000.00, $shiftData['statistics']['gross_sales']);
        $this->assertEquals(20, $shiftData['statistics']['total_transactions']);
        $this->assertEquals(50.00, $shiftData['statistics']['average_basket_value']); // 1000/20
        $this->assertEquals(70.00, $shiftData['statistics']['payment_breakdown']['pos_percentage']); // 700/1000
        $this->assertEquals(30.00, $shiftData['statistics']['payment_breakdown']['cash_percentage']); // 300/1000
        $this->assertEquals('balanced', $shiftData['statistics']['reconciliation_status']);
    }

    public function test_can_filter_shifts_by_today(): void
    {
        // Create shift from yesterday
        SalesShift::create([
            'shift_number' => 'SHIFT-20260207-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subDay()->setTime(10, 0),
            'status' => 'closed',
        ]);

        // Create shift for today
        $todayShift = SalesShift::create([
            'shift_number' => 'SHIFT-20260208-0002',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => today()->setTime(14, 0),
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/shifts?filter=today', [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200);

        $shifts = $response->json('shifts.data');
        // The shift from setUp and todayShift should both be today
        $this->assertGreaterThanOrEqual(1, count($shifts));
        $shiftNumbers = collect($shifts)->pluck('shift_number')->toArray();
        $this->assertContains($todayShift->shift_number, $shiftNumbers);
    }

    public function test_can_filter_shifts_by_last_7_days(): void
    {
        // Create shift from 10 days ago (should not be included)
        SalesShift::create([
            'shift_number' => 'SHIFT-20260128-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subDays(10),
            'status' => 'closed',
        ]);

        // Create shift from 3 days ago (should be included)
        $recentShift = SalesShift::create([
            'shift_number' => 'SHIFT-20260205-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subDays(3),
            'status' => 'closed',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/shifts?filter=last_7_days', [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200);

        $shiftNumbers = collect($response->json('shifts.data'))->pluck('shift_number')->toArray();
        $this->assertContains($this->shift->shift_number, $shiftNumbers);
        $this->assertContains($recentShift->shift_number, $shiftNumbers);
        $this->assertNotContains('SHIFT-20260128-0001', $shiftNumbers);
    }

    public function test_can_filter_shifts_by_custom_date_range(): void
    {
        // Create shifts on different dates
        $shift1 = SalesShift::create([
            'shift_number' => 'SHIFT-20260201-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => '2026-02-01 10:00:00',
            'status' => 'closed',
        ]);

        $shift2 = SalesShift::create([
            'shift_number' => 'SHIFT-20260205-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => '2026-02-05 10:00:00',
            'status' => 'closed',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/shifts?start_date=2026-02-01 00:00:00&end_date=2026-02-06 23:59:59', [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200);

        $shiftNumbers = collect($response->json('shifts.data'))->pluck('shift_number')->toArray();
        $this->assertContains($shift1->shift_number, $shiftNumbers);
        $this->assertContains($shift2->shift_number, $shiftNumbers);
    }

    public function test_discrepancy_status_shown_when_variance_exists(): void
    {
        // Create shift with variance
        $shiftWithVariance = SalesShift::create([
            'shift_number' => 'SHIFT-20260208-0003',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subHours(4),
            'end_time' => now(),
            'opening_balance' => 100.00,
            'cash_sales' => 300.00,
            'card_sales' => 700.00,
            'total_sales' => 1000.00,
            'transactions_count' => 10,
            'expected_cash' => 400.00,
            'actual_cash' => 380.00,
            'variance' => -20.00,
            'status' => 'closed',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/shifts/'.$shiftWithVariance->id, [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('statistics.reconciliation_status', 'discrepancy');

        $this->assertEquals(-20.0, $response->json('statistics.variance'));
    }

    public function test_shift_summary_returns_gross_sales_and_total_transactions(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/shifts/'.$this->shift->id.'/summary', [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.shift_id', $this->shift->id)
            ->assertJsonPath('data.shift_number', $this->shift->shift_number)
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.reconciliation_status', 'balanced');

        $data = $response->json('data');
        $this->assertSame(1000, (int) $data['gross_sales']);
        $this->assertSame(20, $data['total_transactions']);
        $this->assertEqualsWithDelta(50.0, (float) $data['average_basket_value'], 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $data['opening_balance'], 0.01);
        $this->assertEqualsWithDelta(400.0, (float) $data['expected_cash'], 0.01);
        $this->assertEqualsWithDelta(400.0, (float) $data['actual_cash'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $data['variance'], 0.01);
        $this->assertArrayHasKey('sales_by_payment_type', $data);
        $this->assertEqualsWithDelta(300.0, (float) $data['sales_by_payment_type']['cash']['amount'], 0.01);
        $this->assertEqualsWithDelta(700.0, (float) $data['sales_by_payment_type']['card']['amount'], 0.01);
        $this->assertArrayHasKey('shift_duration', $data);
        $this->assertArrayHasKey('branch', $data);
        $this->assertArrayHasKey('user', $data);
    }

    public function test_branch_shifts_summary_returns_aggregated_totals(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/shifts/branch-summary?branch_id='.$this->branch->id, [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertSame($this->branch->id, $data['branch_id']);
        $this->assertSame($this->branch->name, $data['branch']['name']);
        $this->assertSame(1000, (int) $data['total_gross_sales']);
        $this->assertSame(20, (int) $data['total_transactions']);
        $this->assertSame(1, $data['total_shifts_count']);
        $this->assertArrayHasKey('filters', $data);
        $this->assertArrayHasKey('shifts_by_status', $data);
        $this->assertSame(1, $data['shifts_by_status']['closed']);
        $this->assertEqualsWithDelta(50.0, (float) $data['average_basket_value'], 0.01);
        $this->assertEqualsWithDelta(300.0, (float) $data['sales_by_payment_type']['cash']['amount'], 0.01);
        $this->assertEqualsWithDelta(700.0, (float) $data['sales_by_payment_type']['card']['amount'], 0.01);
    }

    public function test_branch_shifts_summary_respects_date_and_user_filters(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->businesses()->attach($this->business->id, ['is_active' => true]);

        SalesShift::create([
            'shift_number' => 'SHIFT-20260201-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $otherUser->id,
            'start_time' => '2026-02-01 10:00:00',
            'end_time' => '2026-02-01 18:00:00',
            'total_sales' => 500.00,
            'transactions_count' => 5,
            'status' => 'closed',
        ]);

        $startDate = now()->subDays(1)->format('Y-m-d H:i:s');
        $endDate = now()->addDay()->format('Y-m-d H:i:s');
        $response = $this->actingAs($this->user)
            ->getJson('/api/shifts/branch-summary?branch_id='.$this->branch->id.'&start_date='.urlencode($startDate).'&end_date='.urlencode($endDate).'&user_id='.$this->user->id, [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertSame(1, $data['total_shifts_count']);
        $this->assertSame(1000, (int) $data['total_gross_sales']);
        $this->assertSame($startDate, $data['filters']['start_date']);
        $this->assertSame($endDate, $data['filters']['end_date']);
        $this->assertSame($this->user->id, (int) $data['filters']['user_id']);
    }
}
