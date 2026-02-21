<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionRoutesTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Permissions Tests ====================

    public function test_authenticated_user_can_list_permissions()
    {
        // Create some permissions
        Permission::firstOrCreate(['name' => 'create-sales', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'view-sales', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'manage-roles', 'guard_name' => 'api']);

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.test',
            'password' => Hash::make('password'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/permissions');

        $data = $response->json('data');
        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'name']]]);
        $this->assertGreaterThanOrEqual(3, count($data));
        $response->assertJsonFragment(['name' => 'create-sales'])
            ->assertJsonFragment(['name' => 'view-sales'])
            ->assertJsonFragment(['name' => 'manage-roles']);
    }

    public function test_unauthenticated_user_cannot_list_permissions()
    {
        $response = $this->getJson('/api/permissions');

        $response->assertStatus(401);
    }

    // ==================== Role Listing Tests ====================

    public function test_authenticated_user_can_list_roles_with_business_context()
    {
        $user = User::create([
            'name' => 'Business Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $user->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $role1 = Role::create([
            'name' => 'Manager',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $role2 = Role::create([
            'name' => 'Cashier',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->getJson('/api/roles');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Manager'])
            ->assertJsonFragment(['name' => 'Cashier']);
    }

    public function test_listing_roles_requires_business_context()
    {
        $user = User::create([
            'name' => 'User',
            'email' => 'user@example.test',
            'password' => Hash::make('password'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/roles');

        $response->assertStatus(400)
            ->assertJson(['message' => 'Business context is required']);
    }

    public function test_user_cannot_list_roles_for_unauthorized_business()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $unauthorizedUser = User::create([
            'name' => 'Unauthorized',
            'email' => 'unauthorized@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'Private Store',
            'currency' => 'USD',
        ]);

        Sanctum::actingAs($unauthorizedUser);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->getJson('/api/roles');

        $response->assertStatus(403)
            ->assertJson(['message' => 'You do not have access to this business']);
    }

    // ==================== Role Creation Tests ====================

    public function test_owner_can_create_role()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $permission1 = Permission::firstOrCreate(['name' => 'create-sales', 'guard_name' => 'api']);
        $permission2 = Permission::firstOrCreate(['name' => 'view-sales', 'guard_name' => 'api']);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $payload = [
            'name' => 'Sales Manager',
            'permissions' => ['create-sales', 'view-sales'],
        ];

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/roles', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Sales Manager')
            ->assertJsonCount(2, 'data.permissions');

        $this->assertDatabaseHas('roles', [
            'name' => 'Sales Manager',
            'business_id' => $business->id,
            'guard_name' => 'api',
        ]);
    }

    public function test_non_owner_cannot_create_role()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $staff = User::create([
            'name' => 'Staff',
            'email' => 'staff@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $staff->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/roles', [
                'name' => 'Unauthorized Role',
            ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Only business owners can create roles']);

        $this->assertDatabaseMissing('roles', [
            'name' => 'Unauthorized Role',
        ]);
    }

    public function test_role_creation_validates_required_fields()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/roles', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_role_name_must_be_unique_per_business()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $existingRole = Role::create([
            'name' => 'Manager',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/roles', [
                'name' => 'Manager',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_role_can_be_created_without_permissions()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/roles', [
                'name' => 'Empty Role',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Empty Role')
            ->assertJsonCount(0, 'data.permissions');
    }

    public function test_role_creation_validates_permission_existence()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/roles', [
                'name' => 'Invalid Role',
                'permissions' => ['non-existent-permission'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['permissions.0']);
    }

    // ==================== Role Show Tests ====================

    public function test_user_can_view_role_details()
    {
        $user = User::create([
            'name' => 'Member',
            'email' => 'member@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $permission = Permission::firstOrCreate(['name' => 'create-sales', 'guard_name' => 'api']);

        $role = Role::create([
            'name' => 'Sales Role',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $role->givePermissionTo($permission);

        $user->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->getJson("/api/roles/{$role->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Sales Role')
            ->assertJsonPath('data.permissions', ['create-sales'])
            ->assertJsonStructure(['data' => ['id', 'name', 'permissions', 'users']]);
    }

    public function test_viewing_nonexistent_role_returns_404()
    {
        $user = User::create([
            'name' => 'User',
            'email' => 'user@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $user->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->getJson('/api/roles/999');

        $response->assertStatus(404)
            ->assertJson(['message' => 'Role not found']);
    }

    // ==================== Role Update Tests ====================

    public function test_owner_can_update_role()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $permission1 = Permission::firstOrCreate(['name' => 'create-sales', 'guard_name' => 'api']);
        $permission2 = Permission::firstOrCreate(['name' => 'view-sales', 'guard_name' => 'api']);
        $permission3 = Permission::firstOrCreate(['name' => 'edit-sales', 'guard_name' => 'api']);

        $role = Role::create([
            'name' => 'Original Role',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $role->givePermissionTo($permission1);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->putJson("/api/roles/{$role->id}", [
                'name' => 'Updated Role',
                'permissions' => ['view-sales', 'edit-sales'],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Role')
            ->assertJsonCount(2, 'data.permissions');

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'Updated Role',
        ]);
    }

    public function test_non_owner_cannot_update_role()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $staff = User::create([
            'name' => 'Staff',
            'email' => 'staff@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $role = Role::create([
            'name' => 'Protected Role',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $staff->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->putJson("/api/roles/{$role->id}", [
                'name' => 'Hacked Role',
            ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Only business owners can update roles']);

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'Protected Role',
        ]);
    }

    public function test_role_update_validates_name_uniqueness()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $role1 = Role::create([
            'name' => 'Role One',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $role2 = Role::create([
            'name' => 'Role Two',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->putJson("/api/roles/{$role1->id}", [
                'name' => 'Role Two',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    // ==================== Role Delete Tests ====================

    public function test_owner_can_delete_role()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $role = Role::create([
            'name' => 'Deletable Role',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->deleteJson("/api/roles/{$role->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Role deleted']);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->assertDatabaseMissing('roles', [
            'id' => $role->id,
        ]);
    }

    public function test_non_owner_cannot_delete_role()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $manager = User::create([
            'name' => 'Manager',
            'email' => 'manager@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $role = Role::create([
            'name' => 'Safe Role',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $manager->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($manager);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->deleteJson("/api/roles/{$role->id}");

        $response->assertStatus(403)
            ->assertJson(['message' => 'Only business owners can delete roles']);

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
        ]);
    }

    // ==================== Role Assignment Tests ====================

    public function test_owner_can_assign_role_to_user()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $targetUser = User::create([
            'name' => 'Target User',
            'email' => 'target@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $role = Role::create([
            'name' => 'Manager',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $targetUser->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/roles/assign', [
                'user_id' => $targetUser->id,
                'role_id' => $role->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.id', $targetUser->id)
            ->assertJsonPath('data.role.id', $role->id);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->assertDatabaseHas('model_has_roles', [
            'model_type' => User::class,
            'model_id' => $targetUser->id,
            'role_id' => $role->id,
            'business_id' => $business->id,
        ]);
    }

    public function test_user_with_manage_roles_permission_can_assign_role()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $targetUser = User::create([
            'name' => 'Target User',
            'email' => 'target@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $manageRolesPermission = Permission::firstOrCreate(['name' => 'manage-roles', 'guard_name' => 'api']);
        $role = Role::create([
            'name' => 'Manager',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $admin->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $targetUser->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        // Assign manage-roles permission to admin using DB insertion with business_id
        DB::table('model_has_permissions')->insert([
            'permission_id' => $manageRolesPermission->id,
            'model_type' => User::class,
            'model_id' => $admin->id,
            'business_id' => $business->id,
        ]);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Sanctum::actingAs($admin);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/roles/assign', [
                'user_id' => $targetUser->id,
                'role_id' => $role->id,
            ]);

        $response->assertStatus(200);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->assertDatabaseHas('model_has_roles', [
            'model_type' => User::class,
            'model_id' => $targetUser->id,
            'role_id' => $role->id,
            'business_id' => $business->id,
        ]);
    }

    public function test_user_without_permission_cannot_assign_role()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $staff = User::create([
            'name' => 'Staff',
            'email' => 'staff@example.test',
            'password' => Hash::make('password'),
        ]);

        $targetUser = User::create([
            'name' => 'Target User',
            'email' => 'target@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $role = Role::create([
            'name' => 'Manager',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $staff->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $targetUser->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        // Create manage-roles permission (needed for the check, even though user doesn't have it)
        Permission::firstOrCreate(['name' => 'manage-roles', 'guard_name' => 'api']);

        Sanctum::actingAs($staff);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/roles/assign', [
                'user_id' => $targetUser->id,
                'role_id' => $role->id,
            ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'You do not have permission to assign roles']);

        $this->assertFalse($targetUser->hasRole($role->name, 'api', $business->id));
    }

    public function test_cannot_assign_role_to_non_member_user()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $nonMember = User::create([
            'name' => 'Non Member',
            'email' => 'nonmember@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $role = Role::create([
            'name' => 'Manager',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/roles/assign', [
                'user_id' => $nonMember->id,
                'role_id' => $role->id,
            ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'User is not a member of this business']);
    }

    public function test_can_assign_role_with_branch_id()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $targetUser = User::create([
            'name' => 'Target User',
            'email' => 'target@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $branch = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_main' => true,
        ]);

        $role = Role::create([
            'name' => 'Branch Manager',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $targetUser->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/roles/assign', [
                'user_id' => $targetUser->id,
                'role_id' => $role->id,
                'branch_id' => $branch->id,
            ]);

        $response->assertStatus(200);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Verify role assignment with branch_id
        $this->assertDatabaseHas('model_has_roles', [
            'model_type' => User::class,
            'model_id' => $targetUser->id,
            'role_id' => $role->id,
            'business_id' => $business->id,
            'branch_id' => $branch->id,
        ]);
    }

    public function test_role_assignment_validates_required_fields()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/roles/assign', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id', 'role_id']);
    }

    // ==================== Role Removal Tests ====================

    public function test_owner_can_remove_role_from_user()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $targetUser = User::create([
            'name' => 'Target User',
            'email' => 'target@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $role = Role::create([
            'name' => 'Manager',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $targetUser->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        // Assign role first using direct DB insertion (consistent with controller)
        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $targetUser->id,
            'business_id' => $business->id,
        ]);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/roles/remove', [
                'user_id' => $targetUser->id,
                'role_id' => $role->id,
            ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Role removed from user']);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->assertDatabaseMissing('model_has_roles', [
            'model_type' => User::class,
            'model_id' => $targetUser->id,
            'role_id' => $role->id,
            'business_id' => $business->id,
        ]);
    }

    public function test_user_with_manage_roles_permission_can_remove_role()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);

        $targetUser = User::create([
            'name' => 'Target User',
            'email' => 'target@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $manageRolesPermission = Permission::firstOrCreate(['name' => 'manage-roles', 'guard_name' => 'api']);
        $role = Role::create([
            'name' => 'Manager',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $admin->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $targetUser->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        // Assign permission and role using DB insertion with business_id
        DB::table('model_has_permissions')->insert([
            'permission_id' => $manageRolesPermission->id,
            'model_type' => User::class,
            'model_id' => $admin->id,
            'business_id' => $business->id,
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $targetUser->id,
            'business_id' => $business->id,
        ]);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Sanctum::actingAs($admin);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/roles/remove', [
                'user_id' => $targetUser->id,
                'role_id' => $role->id,
            ]);

        $response->assertStatus(200);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->assertDatabaseMissing('model_has_roles', [
            'model_type' => User::class,
            'model_id' => $targetUser->id,
            'role_id' => $role->id,
            'business_id' => $business->id,
        ]);
    }

    public function test_user_without_permission_cannot_remove_role()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        $staff = User::create([
            'name' => 'Staff',
            'email' => 'staff@example.test',
            'password' => Hash::make('password'),
        ]);

        $targetUser = User::create([
            'name' => 'Target User',
            'email' => 'target@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $role = Role::create([
            'name' => 'Manager',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $staff->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $targetUser->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        // Create manage-roles permission (needed for the check)
        Permission::firstOrCreate(['name' => 'manage-roles', 'guard_name' => 'api']);

        // Assign role first using direct DB insertion
        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $targetUser->id,
            'business_id' => $business->id,
        ]);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Sanctum::actingAs($staff);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/roles/remove', [
                'user_id' => $targetUser->id,
                'role_id' => $role->id,
            ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'You do not have permission to remove roles']);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Verify role was not removed
        $this->assertDatabaseHas('model_has_roles', [
            'model_type' => User::class,
            'model_id' => $targetUser->id,
            'role_id' => $role->id,
            'business_id' => $business->id,
        ]);
    }

    // ==================== Get User Roles Tests ====================

    public function test_user_can_view_another_user_roles()
    {
        $user = User::create([
            'name' => 'Member',
            'email' => 'member@example.test',
            'password' => Hash::make('password'),
        ]);

        $targetUser = User::create([
            'name' => 'Target User',
            'email' => 'target@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $permission1 = Permission::firstOrCreate(['name' => 'create-sales', 'guard_name' => 'api']);
        $permission2 = Permission::firstOrCreate(['name' => 'view-sales', 'guard_name' => 'api']);

        $role1 = Role::create([
            'name' => 'Manager',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $role2 = Role::create([
            'name' => 'Cashier',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $role1->givePermissionTo($permission1);
        $role2->givePermissionTo($permission2);

        $user->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $targetUser->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        // Assign roles to target user using direct DB insertion
        DB::table('model_has_roles')->insert([
            ['role_id' => $role1->id, 'model_type' => User::class, 'model_id' => $targetUser->id, 'business_id' => $business->id],
            ['role_id' => $role2->id, 'model_type' => User::class, 'model_id' => $targetUser->id, 'business_id' => $business->id],
        ]);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->getJson("/api/users/{$targetUser->id}/roles");

        $response->assertStatus(200)
            ->assertJsonPath('data.user.id', $targetUser->id)
            ->assertJsonCount(2, 'data.roles')
            ->assertJsonCount(2, 'data.permissions')
            ->assertJsonFragment(['name' => 'Manager'])
            ->assertJsonFragment(['name' => 'Cashier']);
    }

    public function test_cannot_view_roles_for_non_member_user()
    {
        $user = User::create([
            'name' => 'Member',
            'email' => 'member@example.test',
            'password' => Hash::make('password'),
        ]);

        $nonMember = User::create([
            'name' => 'Non Member',
            'email' => 'nonmember@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $user->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->getJson("/api/users/{$nonMember->id}/roles");

        $response->assertStatus(404)
            ->assertJson(['message' => 'User is not a member of this business']);
    }

    // ==================== Authentication Tests ====================

    public function test_unauthenticated_user_cannot_access_role_routes()
    {
        $response = $this->getJson('/api/roles');
        $response->assertStatus(401);

        $response = $this->postJson('/api/roles', []);
        $response->assertStatus(401);

        $response = $this->getJson('/api/roles/1');
        $response->assertStatus(401);

        $response = $this->putJson('/api/roles/1', []);
        $response->assertStatus(401);

        $response = $this->deleteJson('/api/roles/1');
        $response->assertStatus(401);

        $response = $this->postJson('/api/roles/assign', []);
        $response->assertStatus(401);

        $response = $this->postJson('/api/roles/remove', []);
        $response->assertStatus(401);

        $response = $this->getJson('/api/users/1/roles');
        $response->assertStatus(401);
    }
}
