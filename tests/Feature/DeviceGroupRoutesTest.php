<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\DeviceGroup;
use App\Models\DeviceRegistration;
use App\Models\Sale;
use App\Models\SalesShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeviceGroupRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Business $business;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'view device groups', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'manage device groups', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'assign device to group', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'create shift', 'guard_name' => 'api']);
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
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'business_id' => $this->business->id,
            'address' => '123 Main St',
        ]);

        setPermissionsTeamId($this->business->id);
        $role = Role::create(['name' => 'manager', 'guard_name' => 'api']);
        $role->givePermissionTo([
            'view device groups',
            'manage device groups',
            'assign device to group',
            'create shift',
            'view all shifts',
            'view user shift',
        ]);
        $this->user->assignRole($role);
    }

    public function test_can_create_device_group(): void
    {
        $res = $this->actingAs($this->user)->postJson('/api/device-groups', [
            'branch_id' => $this->branch->id,
            'name' => 'Bar',
            'code' => 'BAR',
            'description' => 'Main bar',
            'is_active' => true,
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $res->assertStatus(201)->assertJsonPath('data.code', 'BAR');
        $this->assertDatabaseHas('device_groups', [
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'code' => 'BAR',
        ]);
    }

    public function test_can_assign_device_to_group(): void
    {
        $group = DeviceGroup::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'name' => 'Kitchen',
            'code' => 'KITCHEN',
            'is_active' => true,
        ]);

        DeviceRegistration::create([
            'device_id' => 'device-1',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'group_id' => null,
            'device_name' => 'Test Device',
            'device_type' => 'web',
            'status' => 'active',
        ]);

        $res = $this->actingAs($this->user)->postJson("/api/device-groups/{$group->id}/assign-device", [
            'device_id' => 'device-1',
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $res->assertStatus(200)->assertJsonPath('data.group_id', $group->id);
        $this->assertDatabaseHas('device_registrations', [
            'device_id' => 'device-1',
            'group_id' => $group->id,
        ]);
    }

    public function test_can_open_shift_if_device_has_no_group(): void
    {
        DeviceRegistration::create([
            'device_id' => 'device-nogroup',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'group_id' => null,
            'device_name' => 'Test Device',
            'device_type' => 'web',
            'status' => 'active',
        ]);

        $res = $this->actingAs($this->user)->postJson('/api/shifts', [
            'branch_id' => $this->branch->id,
            'device_id' => 'device-nogroup',
            'opening_balance' => 100.00,
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $res->assertStatus(201)->assertJsonPath('shift.device_id', 'device-nogroup');
        $this->assertDatabaseHas('sales_shifts', [
            'business_id' => $this->business->id,
            'device_id' => 'device-nogroup',
            'group_id' => null,
        ]);
    }

    public function test_device_group_report_aggregates_sales_by_group(): void
    {
        $group = DeviceGroup::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'name' => 'Bar',
            'code' => 'BAR',
            'is_active' => true,
        ]);

        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-1',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'device_id' => 'device-1',
            'group_id' => $group->id,
            'start_time' => now(),
            'opening_balance' => 0,
            'status' => 'open',
        ]);

        Sale::create([
            'sale_number' => 'SALE-1',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'shift_id' => $shift->id,
            'group_id' => $group->id,
            'sale_date' => now(),
            'total_amount' => 120.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'paid_amount' => 120.00,
        ]);

        $res = $this->actingAs($this->user)->getJson('/api/device-groups/report', [
            'X-Business-Id' => $this->business->id,
        ]);

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertNotEmpty($data);
        $this->assertEquals($group->id, $data[0]['group_id']);
    }
}
