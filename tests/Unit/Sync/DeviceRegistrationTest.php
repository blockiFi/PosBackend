<?php

namespace Tests\Unit\Sync;

use App\Models\Branch;
use App\Models\Business;
use App\Models\DeviceRegistration;
use App\Models\SyncSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_belongs_to_a_business()
    {
        $business = Business::factory()->create();
        $device = DeviceRegistration::factory()->create([
            'business_id' => $business->id,
        ]);

        $this->assertInstanceOf(Business::class, $device->business);
        $this->assertEquals($business->id, $device->business->id);
    }

    /** @test */
    public function it_belongs_to_a_branch()
    {
        $branch = Branch::factory()->create();
        $device = DeviceRegistration::factory()->create([
            'branch_id' => $branch->id,
        ]);

        $this->assertInstanceOf(Branch::class, $device->branch);
        $this->assertEquals($branch->id, $device->branch->id);
    }

    /** @test */
    public function it_belongs_to_a_user()
    {
        $user = User::factory()->create();
        $device = DeviceRegistration::factory()->create([
            'user_id' => $user->id,
        ]);

        $this->assertInstanceOf(User::class, $device->user);
        $this->assertEquals($user->id, $device->user->id);
    }

    /** @test */
    public function it_has_many_sync_sessions()
    {
        $device = DeviceRegistration::factory()->create();
        SyncSession::factory()->count(3)->create([
            'device_id' => $device->id,
        ]);

        $this->assertCount(3, $device->syncSessions);
    }

    /** @test */
    public function it_can_scope_to_active_devices()
    {
        DeviceRegistration::factory()->create(['status' => 'active']);
        DeviceRegistration::factory()->create(['status' => 'active']);
        DeviceRegistration::factory()->create(['status' => 'inactive']);
        DeviceRegistration::factory()->create(['status' => 'blocked']);

        $activeDevices = DeviceRegistration::active()->get();

        $this->assertCount(2, $activeDevices);
    }

    /** @test */
    public function it_can_scope_by_business()
    {
        $business1 = Business::factory()->create();
        $business2 = Business::factory()->create();

        DeviceRegistration::factory()->count(3)->create(['business_id' => $business1->id]);
        DeviceRegistration::factory()->count(2)->create(['business_id' => $business2->id]);

        $business1Devices = DeviceRegistration::forBusiness($business1->id)->get();

        $this->assertCount(3, $business1Devices);
    }

    /** @test */
    public function it_can_update_last_seen()
    {
        $device = DeviceRegistration::factory()->create([
            'last_seen_at' => now()->subHour(),
        ]);

        $oldLastSeen = $device->last_seen_at;
        $device->updateLastSeen();

        $this->assertNotEquals($oldLastSeen, $device->fresh()->last_seen_at);
    }

    /** @test */
    public function it_can_record_sync()
    {
        $device = DeviceRegistration::factory()->create([
            'total_syncs' => 5,
            'last_sync_at' => now()->subDay(),
        ]);

        $oldSyncCount = $device->total_syncs;
        $device->recordSync();

        $this->assertEquals($oldSyncCount + 1, $device->fresh()->total_syncs);
        $this->assertNotNull($device->fresh()->last_sync_at);
    }

    /** @test */
    public function it_can_check_if_blocked()
    {
        $activeDevice = DeviceRegistration::factory()->create(['status' => 'active']);
        $blockedDevice = DeviceRegistration::factory()->create(['status' => 'blocked']);

        $this->assertFalse($activeDevice->isBlocked());
        $this->assertTrue($blockedDevice->isBlocked());
    }

    /** @test */
    public function it_can_check_capabilities()
    {
        $device = DeviceRegistration::factory()->create([
            'capabilities' => [
                'offline_mode' => true,
                'auto_sync' => false,
            ],
        ]);

        $this->assertTrue($device->hasCapability('offline_mode'));
        $this->assertFalse($device->hasCapability('auto_sync'));
        $this->assertFalse($device->hasCapability('nonexistent'));
    }

    /** @test */
    public function it_casts_capabilities_to_array()
    {
        $device = DeviceRegistration::factory()->create([
            'capabilities' => ['offline_mode' => true],
        ]);

        $this->assertIsArray($device->capabilities);
    }

    /** @test */
    public function it_casts_metadata_to_array()
    {
        $device = DeviceRegistration::factory()->create([
            'metadata' => ['key' => 'value'],
        ]);

        $this->assertIsArray($device->metadata);
    }
}
