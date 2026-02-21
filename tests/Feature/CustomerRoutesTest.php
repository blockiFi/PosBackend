<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerRoutesTest extends TestCase
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

        foreach (['view customers', 'create customers', 'edit customers', 'delete customers'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']);
        }

        $this->role = Role::create(['name' => 'Manager', 'guard_name' => 'api', 'business_id' => $this->business->id]);
        setPermissionsTeamId($this->business->id);
        $this->user->assignRole($this->role);
    }

    public function test_can_create_customer(): void
    {
        $this->role->givePermissionTo('create customers');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/customers?current_business_id='.$this->business->id, [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '1234567890',
            'type' => 'regular',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('customers', ['name' => 'John Doe', 'business_id' => $this->business->id]);
    }

    public function test_can_list_customers(): void
    {
        $this->role->givePermissionTo('view customers');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        Customer::create(['business_id' => $this->business->id, 'customer_code' => 'CUST-001', 'name' => 'Test Customer']);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/customers?current_business_id='.$this->business->id);
        $response->assertStatus(200)->assertJsonStructure(['data']);
    }

    public function test_can_update_customer(): void
    {
        $this->role->givePermissionTo('edit customers');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $customer = Customer::create(['business_id' => $this->business->id, 'customer_code' => 'CUST-001', 'name' => 'Old Name']);

        $response = $this->actingAs($this->user, 'sanctum')->putJson("/api/customers/{$customer->id}?current_business_id=".$this->business->id, [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('New Name', $customer->fresh()->name);
    }

    public function test_can_delete_customer(): void
    {
        $this->role->givePermissionTo('delete customers');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $customer = Customer::create(['business_id' => $this->business->id, 'customer_code' => 'CUST-001', 'name' => 'Test']);

        $response = $this->actingAs($this->user, 'sanctum')->deleteJson("/api/customers/{$customer->id}?current_business_id=".$this->business->id);
        $response->assertStatus(200);
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_enforces_permissions(): void
    {
        $unprivilegedUser = User::factory()->create();
        $unprivilegedUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $response = $this->actingAs($unprivilegedUser, 'sanctum')->getJson('/api/customers?current_business_id='.$this->business->id);
        $response->assertStatus(403);
    }
}
