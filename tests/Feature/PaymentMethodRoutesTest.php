<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentMethodRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->business = Business::create(['name' => 'Test Business', 'email' => 'b@test.com', 'owner_id' => $this->user->id]);
        $this->user->businesses()->attach($this->business->id, ['is_active' => true]);

        foreach (['view payment methods', 'manage payment methods'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']);
        }

        $this->role = Role::create(['name' => 'Manager', 'guard_name' => 'api', 'business_id' => $this->business->id]);
        setPermissionsTeamId($this->business->id);
        $this->user->assignRole($this->role);
    }

    public function test_can_create_payment_method(): void
    {
        $this->role->givePermissionTo('manage payment methods');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/payment-methods?current_business_id='.$this->business->id, [
            'name' => 'Credit Card',
            'type' => 'card',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('payment_methods', ['name' => 'Credit Card', 'business_id' => $this->business->id]);
    }

    public function test_can_list_payment_methods(): void
    {
        $this->role->givePermissionTo('view payment methods');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        PaymentMethod::create(['business_id' => $this->business->id, 'name' => 'Cash', 'type' => 'cash']);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/payment-methods?current_business_id='.$this->business->id);
        $response->assertStatus(200)->assertJsonCount(1);
    }

    public function test_can_update_payment_method(): void
    {
        $this->role->givePermissionTo('manage payment methods');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $pm = PaymentMethod::create(['business_id' => $this->business->id, 'name' => 'Cash', 'type' => 'cash']);

        $response = $this->actingAs($this->user, 'sanctum')->putJson("/api/payment-methods/{$pm->id}?current_business_id=".$this->business->id, [
            'name' => 'Cash Payment',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Cash Payment', $pm->fresh()->name);
    }

    public function test_can_delete_payment_method(): void
    {
        $this->role->givePermissionTo('manage payment methods');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $pm = PaymentMethod::create(['business_id' => $this->business->id, 'name' => 'Cash', 'type' => 'cash']);

        $response = $this->actingAs($this->user, 'sanctum')->deleteJson("/api/payment-methods/{$pm->id}?current_business_id=".$this->business->id);
        $response->assertStatus(200);
        $this->assertSoftDeleted('payment_methods', ['id' => $pm->id]);
    }

    public function test_enforces_permissions(): void
    {
        $unprivilegedUser = User::factory()->create();
        $unprivilegedUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $response = $this->actingAs($unprivilegedUser, 'sanctum')->getJson('/api/payment-methods?current_business_id='.$this->business->id);
        $response->assertStatus(403);
    }
}
