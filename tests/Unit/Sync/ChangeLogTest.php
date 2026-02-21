<?php

namespace Tests\Unit\Sync;

use App\Models\Business;
use App\Models\ChangeLog;
use App\Models\DeviceRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChangeLogTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_belongs_to_a_business()
    {
        $business = Business::factory()->create();
        $log = ChangeLog::create([
            'business_id' => $business->id,
            'entity_type' => 'products',
            'entity_id' => 1,
            'entity_uuid' => Str::uuid(),
            'action' => 'created',
            'version' => 1,
            'changed_at' => now(),
        ]);

        $this->assertInstanceOf(Business::class, $log->business);
    }

    /** @test */
    public function it_belongs_to_a_user()
    {
        $business = Business::factory()->create();
        $user = User::factory()->create();
        $log = ChangeLog::create([
            'business_id' => $business->id,
            'entity_type' => 'products',
            'entity_id' => 1,
            'entity_uuid' => Str::uuid(),
            'action' => 'created',
            'version' => 1,
            'user_id' => $user->id,
            'changed_at' => now(),
        ]);

        $this->assertInstanceOf(User::class, $log->user);
    }

    /** @test */
    public function it_belongs_to_a_device()
    {
        $device = DeviceRegistration::factory()->create();
        $log = ChangeLog::create([
            'business_id' => $device->business_id,
            'entity_type' => 'products',
            'entity_id' => 1,
            'entity_uuid' => Str::uuid(),
            'action' => 'created',
            'version' => 1,
            'device_id' => $device->device_id,
            'changed_at' => now(),
        ]);

        $this->assertInstanceOf(DeviceRegistration::class, $log->device);
    }

    /** @test */
    public function it_can_scope_for_entity()
    {
        $business = Business::factory()->create();
        ChangeLog::create([
            'business_id' => $business->id,
            'entity_type' => 'products',
            'entity_id' => 1,
            'entity_uuid' => Str::uuid(),
            'action' => 'created',
            'version' => 1,
            'changed_at' => now(),
        ]);
        ChangeLog::create([
            'business_id' => $business->id,
            'entity_type' => 'customers',
            'entity_id' => 2,
            'entity_uuid' => Str::uuid(),
            'action' => 'created',
            'version' => 1,
            'changed_at' => now(),
        ]);

        $productLogs = ChangeLog::forEntity('products')->get();

        $this->assertCount(1, $productLogs);
        $this->assertEquals('products', $productLogs->first()->entity_type);
    }

    /** @test */
    public function it_can_scope_for_entity_with_id()
    {
        $business = Business::factory()->create();
        ChangeLog::create([
            'business_id' => $business->id,
            'entity_type' => 'products',
            'entity_id' => 1,
            'entity_uuid' => Str::uuid(),
            'action' => 'created',
            'version' => 1,
            'changed_at' => now(),
        ]);
        ChangeLog::create([
            'business_id' => $business->id,
            'entity_type' => 'products',
            'entity_id' => 2,
            'entity_uuid' => Str::uuid(),
            'action' => 'created',
            'version' => 1,
            'changed_at' => now(),
        ]);

        $specificProductLogs = ChangeLog::forEntity('products', 1)->get();

        $this->assertCount(1, $specificProductLogs);
        $this->assertEquals(1, $specificProductLogs->first()->entity_id);
    }

    /** @test */
    public function it_can_scope_to_unsynced()
    {
        $business = Business::factory()->create();
        ChangeLog::create([
            'business_id' => $business->id,
            'entity_type' => 'products',
            'entity_id' => 1,
            'entity_uuid' => Str::uuid(),
            'action' => 'created',
            'version' => 1,
            'synced' => false,
            'changed_at' => now(),
        ]);
        ChangeLog::create([
            'business_id' => $business->id,
            'entity_type' => 'products',
            'entity_id' => 2,
            'entity_uuid' => Str::uuid(),
            'action' => 'created',
            'version' => 1,
            'synced' => true,
            'changed_at' => now(),
        ]);

        $unsyncedLogs = ChangeLog::unsynced()->get();

        $this->assertCount(1, $unsyncedLogs);
        $this->assertFalse($unsyncedLogs->first()->synced);
    }

    /** @test */
    public function it_can_scope_since_timestamp()
    {
        $business = Business::factory()->create();
        ChangeLog::create([
            'business_id' => $business->id,
            'entity_type' => 'products',
            'entity_id' => 1,
            'entity_uuid' => Str::uuid(),
            'action' => 'created',
            'version' => 1,
            'changed_at' => now()->subDay(),
        ]);
        ChangeLog::create([
            'business_id' => $business->id,
            'entity_type' => 'products',
            'entity_id' => 2,
            'entity_uuid' => Str::uuid(),
            'action' => 'created',
            'version' => 1,
            'changed_at' => now()->subHour(),
        ]);

        $recentLogs = ChangeLog::since(now()->subHours(2))->get();

        $this->assertCount(1, $recentLogs);
    }

    /** @test */
    public function it_can_log_change_statically()
    {
        $business = Business::factory()->create();
        $user = User::factory()->create();
        $uuid = Str::uuid()->toString();

        $this->actingAs($user);

        $log = ChangeLog::logChange(
            'products',
            1,
            $uuid,
            'created',
            1,
            ['name' => ['old' => null, 'new' => 'Product A']],
            'TEST-DEVICE',
            $user->id,
            $business->id
        );

        $this->assertInstanceOf(ChangeLog::class, $log);
        $this->assertEquals('products', $log->entity_type);
        $this->assertEquals(1, $log->entity_id);
        $this->assertEquals($uuid, $log->entity_uuid);
        $this->assertEquals('created', $log->action);
        $this->assertEquals(1, $log->version);
        $this->assertFalse($log->synced);
    }

    /** @test */
    public function it_can_get_changes_since()
    {
        $business = Business::factory()->create();

        ChangeLog::create([
            'business_id' => $business->id,
            'entity_type' => 'products',
            'entity_id' => 1,
            'entity_uuid' => Str::uuid(),
            'action' => 'created',
            'version' => 1,
            'changed_at' => now()->subHour(),
        ]);
        ChangeLog::create([
            'business_id' => $business->id,
            'entity_type' => 'customers',
            'entity_id' => 2,
            'entity_uuid' => Str::uuid(),
            'action' => 'created',
            'version' => 1,
            'changed_at' => now()->subMinutes(30),
        ]);

        $changes = ChangeLog::getChangesSince($business->id, now()->subHours(2));

        $this->assertCount(2, $changes);
        $this->assertArrayHasKey('products', $changes->toArray());
        $this->assertArrayHasKey('customers', $changes->toArray());
    }

    /** @test */
    public function it_can_filter_changes_by_entity_types()
    {
        $business = Business::factory()->create();

        ChangeLog::create([
            'business_id' => $business->id,
            'entity_type' => 'products',
            'entity_id' => 1,
            'entity_uuid' => Str::uuid(),
            'action' => 'created',
            'version' => 1,
            'changed_at' => now(),
        ]);
        ChangeLog::create([
            'business_id' => $business->id,
            'entity_type' => 'customers',
            'entity_id' => 2,
            'entity_uuid' => Str::uuid(),
            'action' => 'created',
            'version' => 1,
            'changed_at' => now(),
        ]);

        $changes = ChangeLog::getChangesSince(
            $business->id,
            now()->subHour(),
            ['products']
        );

        $this->assertCount(1, $changes);
        $this->assertArrayHasKey('products', $changes->toArray());
        $this->assertArrayNotHasKey('customers', $changes->toArray());
    }

    /** @test */
    public function it_can_get_entity_history()
    {
        $business = Business::factory()->create();
        for ($i = 1; $i <= 5; $i++) {
            ChangeLog::create([
                'business_id' => $business->id,
                'entity_type' => 'products',
                'entity_id' => 1,
                'entity_uuid' => Str::uuid(),
                'action' => 'updated',
                'version' => $i,
                'changed_at' => now()->addMinutes($i),
            ]);
        }

        $history = ChangeLog::getEntityHistory('products', 1, 3);

        $this->assertCount(3, $history);
        $this->assertEquals(5, $history->first()->version);
    }

    /** @test */
    public function it_can_mark_as_synced()
    {
        $business = Business::factory()->create();
        $log = ChangeLog::create([
            'business_id' => $business->id,
            'entity_type' => 'products',
            'entity_id' => 1,
            'entity_uuid' => Str::uuid(),
            'action' => 'created',
            'version' => 1,
            'synced' => false,
            'changed_at' => now(),
        ]);

        $this->assertFalse($log->synced);

        $log->markSynced();

        $this->assertTrue($log->fresh()->synced);
    }

    /** @test */
    public function it_casts_changes_to_array()
    {
        $business = Business::factory()->create();
        $log = ChangeLog::create([
            'business_id' => $business->id,
            'entity_type' => 'products',
            'entity_id' => 1,
            'entity_uuid' => Str::uuid(),
            'action' => 'updated',
            'version' => 1,
            'changes' => ['name' => ['old' => 'A', 'new' => 'B']],
            'changed_at' => now(),
        ]);

        $this->assertIsArray($log->changes);
        $this->assertArrayHasKey('name', $log->changes);
    }
}
