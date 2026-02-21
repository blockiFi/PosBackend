<?php

namespace Tests\Unit\Sync;

use App\Models\Business;
use App\Models\DeviceRegistration;
use App\Models\SyncSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncSessionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_belongs_to_a_device()
    {
        $device = DeviceRegistration::factory()->create();
        $session = SyncSession::factory()->create([
            'device_id' => $device->id,
        ]);

        $this->assertInstanceOf(DeviceRegistration::class, $session->device);
        $this->assertEquals($device->id, $session->device->id);
    }

    /** @test */
    public function it_belongs_to_a_business()
    {
        $business = Business::factory()->create();
        $session = SyncSession::factory()->create([
            'business_id' => $business->id,
        ]);

        $this->assertInstanceOf(Business::class, $session->business);
    }

    /** @test */
    public function it_belongs_to_a_user()
    {
        $user = User::factory()->create();
        $session = SyncSession::factory()->create([
            'user_id' => $user->id,
        ]);

        $this->assertInstanceOf(User::class, $session->user);
    }

    /** @test */
    public function it_can_scope_to_active_sessions()
    {
        SyncSession::factory()->create(['status' => 'in_progress']);
        SyncSession::factory()->create(['status' => 'initiated']);
        SyncSession::factory()->create(['status' => 'completed']);
        SyncSession::factory()->create(['status' => 'failed']);

        $activeSessions = SyncSession::active()->get();

        $this->assertCount(2, $activeSessions);
    }

    /** @test */
    public function it_can_scope_by_device()
    {
        $device = DeviceRegistration::factory()->create();
        SyncSession::factory()->count(3)->create(['device_id' => $device->id]);
        SyncSession::factory()->count(2)->create();

        $deviceSessions = SyncSession::forDevice($device->id)->get();

        $this->assertCount(3, $deviceSessions);
    }

    /** @test */
    public function it_can_scope_to_recent_sessions()
    {
        SyncSession::factory()->create(['started_at' => now()->subDays(3)]);
        SyncSession::factory()->create(['started_at' => now()->subDays(10)]);
        SyncSession::factory()->create(['started_at' => now()->subHour()]);

        $recentSessions = SyncSession::recent(7)->get();

        $this->assertCount(2, $recentSessions);
    }

    /** @test */
    public function it_can_start_session()
    {
        $session = SyncSession::factory()->create(['status' => 'initiated']);

        $session->startSession();

        $this->assertEquals('in_progress', $session->fresh()->status);
        $this->assertNotNull($session->fresh()->started_at);
    }

    /** @test */
    public function it_can_complete_session()
    {
        $session = SyncSession::factory()->create(['status' => 'in_progress']);

        $session->completeSession();

        $this->assertEquals('completed', $session->fresh()->status);
        $this->assertNotNull($session->fresh()->completed_at);
    }

    /** @test */
    public function it_can_complete_with_custom_status()
    {
        $session = SyncSession::factory()->create(['status' => 'in_progress']);

        $session->completeSession('failed');

        $this->assertEquals('failed', $session->fresh()->status);
    }

    /** @test */
    public function it_can_record_push()
    {
        $session = SyncSession::factory()->create(['records_pushed' => 0]);

        $session->recordPush(10);

        $this->assertEquals(10, $session->fresh()->records_pushed);
    }

    /** @test */
    public function it_can_record_pull()
    {
        $session = SyncSession::factory()->create(['records_pulled' => 0]);

        $session->recordPull(25);

        $this->assertEquals(25, $session->fresh()->records_pulled);
    }

    /** @test */
    public function it_can_record_conflict()
    {
        $session = SyncSession::factory()->create([
            'conflicts_detected' => 0,
            'conflicts_resolved' => 0,
        ]);

        $session->recordConflict(true);

        $this->assertEquals(1, $session->fresh()->conflicts_detected);
        $this->assertEquals(1, $session->fresh()->conflicts_resolved);
    }

    /** @test */
    public function it_can_record_unresolved_conflict()
    {
        $session = SyncSession::factory()->create([
            'conflicts_detected' => 0,
            'conflicts_resolved' => 0,
        ]);

        $session->recordConflict(false);

        $this->assertEquals(1, $session->fresh()->conflicts_detected);
        $this->assertEquals(0, $session->fresh()->conflicts_resolved);
    }

    /** @test */
    public function it_can_record_error()
    {
        $session = SyncSession::factory()->create(['errors_count' => 0]);

        $session->recordError('Test error message');

        $this->assertEquals(1, $session->fresh()->errors_count);
        $this->assertEquals('Test error message', $session->fresh()->error_message);
    }

    /** @test */
    public function it_can_check_if_completed()
    {
        $inProgress = SyncSession::factory()->create(['status' => 'in_progress']);
        $completed = SyncSession::factory()->create(['status' => 'completed']);
        $failed = SyncSession::factory()->create(['status' => 'failed']);

        $this->assertFalse($inProgress->isCompleted());
        $this->assertTrue($completed->isCompleted());
        $this->assertTrue($failed->isCompleted());
    }

    /** @test */
    public function it_can_check_if_has_errors()
    {
        $noErrors = SyncSession::factory()->create(['errors_count' => 0]);
        $withErrors = SyncSession::factory()->create(['errors_count' => 2]);

        $this->assertFalse($noErrors->hasErrors());
        $this->assertTrue($withErrors->hasErrors());
    }

    /** @test */
    public function it_can_check_if_has_conflicts()
    {
        $noConflicts = SyncSession::factory()->create(['conflicts_detected' => 0]);
        $withConflicts = SyncSession::factory()->create(['conflicts_detected' => 3]);

        $this->assertFalse($noConflicts->hasConflicts());
        $this->assertTrue($withConflicts->hasConflicts());
    }
}
