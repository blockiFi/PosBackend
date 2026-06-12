<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BusinessSettingsRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->business = Business::create([
            'name' => 'Settings Business',
            'email' => 'settings@test.com',
            'owner_id' => $this->owner->id,
        ]);
        $this->owner->businesses()->attach($this->business->id, ['is_active' => true]);
    }

    public function test_get_business_settings_includes_allow_decimal_quantities_default_false(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/settings/business?current_business_id='.$this->business->id, [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.allow_decimal_quantities', false);
    }

    public function test_owner_can_update_allow_decimal_quantities(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/settings/business?current_business_id='.$this->business->id, [
                'allow_decimal_quantities' => true,
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.allow_decimal_quantities', true);

        $this->business->refresh();
        $settings = is_array($this->business->settings) ? $this->business->settings : [];
        $this->assertTrue($settings['allow_decimal_quantities'] ?? false);

        $get = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/settings/business?current_business_id='.$this->business->id, [
                'X-Business-Id' => $this->business->id,
            ]);

        $get->assertOk()->assertJsonPath('data.allow_decimal_quantities', true);
    }

    public function test_non_owner_without_permission_cannot_update_settings(): void
    {
        Permission::firstOrCreate(['name' => 'manage-settings', 'guard_name' => 'api']);

        $member = User::factory()->create();
        $member->businesses()->attach($this->business->id, ['is_active' => true]);

        $response = $this->actingAs($member, 'sanctum')
            ->putJson('/api/settings/business?current_business_id='.$this->business->id, [
                'allow_decimal_quantities' => true,
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertForbidden();
    }
}
