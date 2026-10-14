<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchAuthorization;
use App\Models\Business;
use App\Models\DeviceGroup;
use App\Models\DeviceRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\SeedsPermissions;

/**
 * POS terminals pick a device group during setup (via the branch setup code)
 * and can change it later from the POS admin settings.
 */
class PosDeviceGroupSetupTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPermissions;

    protected User $owner;

    protected Business $business;

    protected Branch $branch;

    protected Branch $otherBranch;

    protected BranchAuthorization $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->business = Business::factory()->create(['owner_id' => $this->owner->id]);
        $this->branch = Branch::factory()->create(['business_id' => $this->business->id]);
        $this->otherBranch = Branch::factory()->create(['business_id' => $this->business->id]);
        $this->owner->businesses()->attach($this->business->id, ['is_active' => true]);

        $this->auth = BranchAuthorization::create([
            'user_id' => $this->owner->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'auth_code' => '123456',
            'expires_at' => now()->addDay(),
        ]);
    }

    private function group(array $attrs = []): DeviceGroup
    {
        static $n = 0;
        $n++;

        return DeviceGroup::create(array_merge([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'name' => "Group {$n}",
            'code' => "G{$n}",
            'is_active' => true,
        ], $attrs));
    }

    private function device(): DeviceRegistration
    {
        return DeviceRegistration::create([
            'device_id' => 'POS-1',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->owner->id,
            'device_name' => 'Till 1',
            'device_type' => 'desktop',
            'status' => 'active',
        ]);
    }

    public function test_setup_code_lists_groups_this_branch_can_use(): void
    {
        $branchGroup = $this->group(['name' => 'Front tills']);
        $businessWide = $this->group(['name' => 'All shops', 'branch_id' => null]);
        $this->group(['name' => 'Other branch', 'branch_id' => $this->otherBranch->id]);
        $this->group(['name' => 'Inactive', 'is_active' => false]);

        $response = $this->postJson('/api/business-details-with-branch-auth', ['auth_code' => '123456']);

        $response->assertOk();
        $ids = collect($response->json('device_groups'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$branchGroup->id, $businessWide->id])->sort()->values()->all(), $ids);
    }

    public function test_setup_code_can_move_a_device_between_groups(): void
    {
        $device = $this->device();
        $group = $this->group();

        $this->postJson('/api/device-group-with-branch-auth', [
            'auth_code' => '123456',
            'device_id' => 'POS-1',
            'group_id' => $group->id,
        ])->assertOk()->assertJsonPath('device.group.id', $group->id);

        $this->assertSame($group->id, $device->fresh()->group_id);

        $this->postJson('/api/device-group-with-branch-auth', [
            'auth_code' => '123456',
            'device_id' => 'POS-1',
            'group_id' => null,
        ])->assertOk();

        $this->assertNull($device->fresh()->group_id);
    }

    public function test_setting_group_rejects_bad_code_unknown_device_and_other_branch_group(): void
    {
        $this->device();
        $otherGroup = $this->group(['branch_id' => $this->otherBranch->id]);

        $this->postJson('/api/device-group-with-branch-auth', [
            'auth_code' => '999999',
            'device_id' => 'POS-1',
            'group_id' => null,
        ])->assertStatus(401);

        $this->postJson('/api/device-group-with-branch-auth', [
            'auth_code' => '123456',
            'device_id' => 'NOPE',
            'group_id' => null,
        ])->assertStatus(404);

        $this->postJson('/api/device-group-with-branch-auth', [
            'auth_code' => '123456',
            'device_id' => 'POS-1',
            'group_id' => $otherGroup->id,
        ])->assertStatus(422);
    }

    public function test_register_device_saves_group_and_drops_an_unusable_one(): void
    {
        $this->seedSyncPermissions();
        setPermissionsTeamId($this->business->id);
        $this->owner->givePermissionTo('sync data');
        Sanctum::actingAs($this->owner);

        $group = $this->group();
        $otherGroup = $this->group(['branch_id' => $this->otherBranch->id]);

        $payload = fn (string $id, int $groupId) => [
            'device_id' => $id,
            'device_name' => 'Till',
            'device_type' => 'desktop',
            'branch_id' => $this->branch->id,
            'group_id' => $groupId,
        ];

        $this->withHeader('X-Business-Id', (string) $this->business->id)
            ->postJson('/api/sync/register-device', $payload('POS-A', $group->id))
            ->assertCreated();
        $this->assertSame($group->id, DeviceRegistration::where('device_id', 'POS-A')->value('group_id'));

        $this->withHeader('X-Business-Id', (string) $this->business->id)
            ->postJson('/api/sync/register-device', $payload('POS-B', $otherGroup->id))
            ->assertCreated();
        $this->assertNull(DeviceRegistration::where('device_id', 'POS-B')->value('group_id'));
    }
}
