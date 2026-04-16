<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\DeviceGroup;
use App\Models\DeviceRegistration;
use App\Models\SalesShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShiftDeviceIdAndOpeningBalanceDiscrepancyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Business $business;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'create shift', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'close shift', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'view all shifts', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'view user shift', 'guard_name' => 'api']);

        $this->user = User::factory()->create(['pin_code' => '123456']);
        $this->business = Business::create([
            'name' => 'Test Business',
            'description' => 'Test',
            'owner_id' => $this->user->id,
        ]);
        $this->user->businesses()->attach($this->business->id);
        $this->branch = Branch::create([
            'name' => 'Main',
            'code' => 'MAIN',
            'business_id' => $this->business->id,
            'address' => '123 Main St',
        ]);

        setPermissionsTeamId($this->business->id);
        $role = Role::create(['name' => 'manager', 'guard_name' => 'api']);
        $role->givePermissionTo(['create shift', 'close shift', 'view all shifts', 'view user shift']);
        $this->user->assignRole($role);
    }

    public function test_opening_shift_without_device_id_returns_422(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/shifts', [
            'branch_id' => $this->branch->id,
            'opening_balance' => 100.00,
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['device_id']);
    }

    public function test_opening_shift_with_device_id_in_body_succeeds_and_stores_device_id(): void
    {
        $group = DeviceGroup::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'name' => 'Bar',
            'code' => 'BAR',
            'is_active' => true,
        ]);
        DeviceRegistration::create([
            'device_id' => 'device-abc-123',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'group_id' => $group->id,
            'device_name' => 'Test Device',
            'device_type' => 'web',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/shifts', [
            'branch_id' => $this->branch->id,
            'device_id' => 'device-abc-123',
            'opening_balance' => 100.00,
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('shift.device_id', 'device-abc-123');
        $this->assertDatabaseHas('sales_shifts', [
            'business_id' => $this->business->id,
            'device_id' => 'device-abc-123',
            'group_id' => $group->id,
            'opening_balance' => 100,
            'status' => 'open',
        ]);
    }

    public function test_opening_shift_with_device_id_in_header_succeeds(): void
    {
        $group = DeviceGroup::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'name' => 'Bar',
            'code' => 'BAR',
            'is_active' => true,
        ]);
        DeviceRegistration::create([
            'device_id' => 'device-header-99',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'group_id' => $group->id,
            'device_name' => 'Test Device',
            'device_type' => 'web',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/shifts', [
            'branch_id' => $this->branch->id,
            'opening_balance' => 50.00,
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => 'device-header-99',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('shift.device_id', 'device-header-99');
        $this->assertDatabaseHas('sales_shifts', ['device_id' => 'device-header-99']);
    }

    public function test_opening_balance_discrepancy_when_previous_shift_closed_with_different_actual_cash(): void
    {
        $group = DeviceGroup::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'name' => 'Bar',
            'code' => 'BAR',
            'is_active' => true,
        ]);
        DeviceRegistration::create([
            'device_id' => 'device-same',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'group_id' => $group->id,
            'device_name' => 'Test Device',
            'device_type' => 'web',
            'status' => 'active',
        ]);

        $previousShift = SalesShift::create([
            'shift_number' => 'SHIFT-PREV-001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'device_id' => 'device-same',
            'group_id' => $group->id,
            'start_time' => now()->subHours(10),
            'end_time' => now()->subHours(2),
            'opening_balance' => 200.00,
            'actual_cash' => 350.00,
            'status' => 'closed',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/shifts', [
            'branch_id' => $this->branch->id,
            'device_id' => 'device-same',
            'opening_balance' => 340.00,
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(201);
        $newShift = SalesShift::where('device_id', 'device-same')->where('status', 'open')->first();
        $this->assertNotNull($newShift);
        $this->assertNotNull($newShift->opening_balance_discrepancy);
        $this->assertEqualsWithDelta(-10.00, (float) $newShift->opening_balance_discrepancy, 0.01);
        $this->assertEquals($previousShift->id, $newShift->previous_shift_id);
    }

    public function test_no_opening_balance_discrepancy_when_opening_balance_matches_previous_actual_cash(): void
    {
        $group = DeviceGroup::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'name' => 'Bar',
            'code' => 'BAR',
            'is_active' => true,
        ]);
        DeviceRegistration::create([
            'device_id' => 'device-match',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'group_id' => $group->id,
            'device_name' => 'Test Device',
            'device_type' => 'web',
            'status' => 'active',
        ]);

        SalesShift::create([
            'shift_number' => 'SHIFT-MATCH-001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'device_id' => 'device-match',
            'group_id' => $group->id,
            'start_time' => now()->subHours(10),
            'end_time' => now()->subHours(2),
            'opening_balance' => 100.00,
            'actual_cash' => 250.00,
            'status' => 'closed',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/shifts', [
            'branch_id' => $this->branch->id,
            'device_id' => 'device-match',
            'opening_balance' => 250.00,
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(201);
        $newShift = SalesShift::where('device_id', 'device-match')->where('status', 'open')->first();
        $this->assertNotNull($newShift);
        $this->assertNull($newShift->opening_balance_discrepancy);
        $this->assertNull($newShift->previous_shift_id);
    }

    public function test_can_filter_shifts_by_device_id(): void
    {
        SalesShift::create([
            'shift_number' => 'SHIFT-A1',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'device_id' => 'device-filter-a',
            'start_time' => now(),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);
        SalesShift::create([
            'shift_number' => 'SHIFT-B1',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'device_id' => 'device-filter-b',
            'start_time' => now(),
            'opening_balance' => 200.00,
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/shifts?device_id=device-filter-a', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200);
        $data = $response->json('shifts.data');
        $this->assertCount(1, $data);
        $this->assertEquals('device-filter-a', $data[0]['device_id']);
        $this->assertEquals('SHIFT-A1', $data[0]['shift_number']);
    }

    public function test_has_discrepancy_filter_includes_opening_balance_discrepancies(): void
    {
        SalesShift::create([
            'shift_number' => 'SHIFT-NODISC',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'device_id' => 'device-d',
            'start_time' => now(),
            'opening_balance' => 100.00,
            'opening_balance_discrepancy' => null,
            'status' => 'open',
        ]);
        $shiftWithOpeningDisc = SalesShift::create([
            'shift_number' => 'SHIFT-OPEN-DISC',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'device_id' => 'device-d',
            'start_time' => now(),
            'opening_balance' => 200.00,
            'opening_balance_discrepancy' => -15.50,
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/shifts?has_discrepancy=1', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200);
        $shiftNumbers = collect($response->json('shifts.data'))->pluck('shift_number')->toArray();
        $this->assertContains('SHIFT-OPEN-DISC', $shiftNumbers, 'Shifts with opening_balance_discrepancy should appear when has_discrepancy=1');
        $this->assertNotContains('SHIFT-NODISC', $shiftNumbers);
    }
}
