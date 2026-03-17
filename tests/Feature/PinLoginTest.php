<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use App\Models\User_Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PinLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_pin()
    {
        Permission::firstOrCreate(['name' => 'use-pin-login', 'guard_name' => 'api']);

        $teamKey = (string) (config('permission.column_names.team_foreign_key') ?? 'business_id');

        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();
        $user = User::factory()->create([
            'pin_code' => '123456',
        ]);

        // Associate user with businesses
        User_Business::create([
            'user_id' => $user->id,
            'business_id' => $businessA->id,
            'is_active' => true,
        ]);
        User_Business::create([
            'user_id' => $user->id,
            'business_id' => $businessB->id,
            'is_active' => true,
        ]);

        Branch::create([
            'business_id' => $businessA->id,
            'name' => 'Branch A',
            'code' => 'BA',
            'address' => 'Addr A',
        ]);
        Branch::create([
            'business_id' => $businessB->id,
            'name' => 'Branch B',
            'code' => 'BB',
            'address' => 'Addr B',
        ]);

        // Business A role has use-pin-login permission
        setPermissionsTeamId($businessA->id);
        $roleA = Role::create([$teamKey => $businessA->id, 'name' => 'cashier', 'guard_name' => 'api']);
        $roleA->givePermissionTo('use-pin-login');
        $user->assignRole($roleA);

        // Business B role (no special permissions needed for this test)
        setPermissionsTeamId($businessB->id);
        $roleB = Role::create([$teamKey => $businessB->id, 'name' => 'manager', 'guard_name' => 'api']);
        $user->assignRole($roleB);

        $response = $this->postJson('/api/pin-login', [
            'pin_code' => '123456',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'token',
                'token_type',
                'user' => ['id', 'name', 'email'],
                'businesses',
            ])
            ->assertJson([
                'message' => 'Login successful',
                'token_type' => 'Bearer',
            ]);

        $this->assertNotEmpty($response->json('token'));
        $this->assertCount(2, $response->json('businesses'));
    }

    public function test_pin_login_fails_with_invalid_pin()
    {
        User::factory()->create([
            'pin_code' => '123456',
        ]);

        $response = $this->postJson('/api/pin-login', [
            'pin_code' => '999999',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid PIN code',
            ]);
    }

    public function test_pin_login_requires_six_digits()
    {
        $response = $this->postJson('/api/pin-login', [
            'pin_code' => '12345', // Only 5 digits
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['pin_code']);
    }

    public function test_pin_login_only_accepts_numeric_pins()
    {
        $response = $this->postJson('/api/pin-login', [
            'pin_code' => 'abcdef',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['pin_code']);
    }

    public function test_authenticated_user_can_set_pin()
    {
        Permission::firstOrCreate(['name' => 'manage-pin-codes', 'guard_name' => 'api']);

        $business = Business::factory()->create();
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        User_Business::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
        ]);

        setPermissionsTeamId($business->id);
        $role = Role::create(['name' => 'manager', 'guard_name' => 'api']);
        $role->givePermissionTo('manage-pin-codes');
        $user->assignRole($role);

        $response = $this->actingAs($user)->postJson('/api/pin/set', [
            'user_id' => 1,
            'user_id' => $user->id,
            'user_id' => $user->id,
            'pin_code' => '654321',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'PIN code set successfully',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'pin_code' => '654321',
        ]);
    }

    public function test_setting_pin_requires_password_verification()
    {
        Permission::firstOrCreate(['name' => 'manage-pin-codes', 'guard_name' => 'api']);

        $business = Business::factory()->create();
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        User_Business::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
        ]);

        setPermissionsTeamId($business->id);
        $role = Role::create(['name' => 'manager', 'guard_name' => 'api']);
        $role->givePermissionTo('manage-pin-codes');
        $user->assignRole($role);

        $response = $this->actingAs($user)->postJson('/api/pin/set', [
            'user_id' => 1,
            'user_id' => $user->id,
            'user_id' => $user->id,
            'pin_code' => '654321',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid password',
            ]);
    }

    public function test_cannot_set_duplicate_pin()
    {
        Permission::firstOrCreate(['name' => 'manage-pin-codes', 'guard_name' => 'api']);

        $existingUser = User::factory()->create([
            'pin_code' => '111111',
        ]);

        $business = Business::factory()->create();
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        User_Business::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
        ]);

        setPermissionsTeamId($business->id);
        $role = Role::create(['name' => 'manager', 'guard_name' => 'api']);
        $role->givePermissionTo('manage-pin-codes');
        $user->assignRole($role);

        $response = $this->actingAs($user)->postJson('/api/pin/set', [
            'user_id' => 1,
            'user_id' => $user->id,
            'user_id' => $user->id,
            'pin_code' => '111111',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'This PIN code is already in use',
            ]);
    }

    public function test_user_can_update_existing_pin()
    {
        Permission::firstOrCreate(['name' => 'manage-pin-codes', 'guard_name' => 'api']);

        $business = Business::factory()->create();
        $user = User::factory()->create([
            'pin_code' => '111111',
            'password' => Hash::make('password123'),
        ]);

        User_Business::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
        ]);

        setPermissionsTeamId($business->id);
        $role = Role::create(['name' => 'manager', 'guard_name' => 'api']);
        $role->givePermissionTo('manage-pin-codes');
        $user->assignRole($role);

        $response = $this->actingAs($user)->postJson('/api/pin/set', [
            'user_id' => 1,
            'user_id' => $user->id,
            'user_id' => $user->id,
            'pin_code' => '222222',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'pin_code' => '222222',
        ]);
    }

    public function test_authenticated_user_can_remove_pin()
    {
        Permission::firstOrCreate(['name' => 'manage-pin-codes', 'guard_name' => 'api']);

        $business = Business::factory()->create();
        $user = User::factory()->create([
            'pin_code' => '123456',
            'password' => Hash::make('password123'),
        ]);

        User_Business::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
        ]);

        setPermissionsTeamId($business->id);
        $role = Role::create(['name' => 'manager', 'guard_name' => 'api']);
        $role->givePermissionTo('manage-pin-codes');
        $user->assignRole($role);

        $response = $this->actingAs($user)->postJson('/api/pin/remove', [
            'user_id' => 1,
            'user_id' => $user->id,
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'PIN code removed successfully',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'pin_code' => null,
        ]);
    }

    public function test_removing_pin_requires_password()
    {
        Permission::firstOrCreate(['name' => 'manage-pin-codes', 'guard_name' => 'api']);

        $business = Business::factory()->create();
        $user = User::factory()->create([
            'pin_code' => '123456',
            'password' => Hash::make('password123'),
        ]);

        User_Business::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
        ]);

        setPermissionsTeamId($business->id);
        $role = Role::create(['name' => 'manager', 'guard_name' => 'api']);
        $role->givePermissionTo('manage-pin-codes');
        $user->assignRole($role);

        $response = $this->actingAs($user)->postJson('/api/pin/remove', [
            'user_id' => 1,
            'user_id' => $user->id,
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid password',
            ]);
    }

    public function test_pin_is_hidden_in_user_response()
    {
        $user = User::factory()->create([
            'pin_code' => '123456',
        ]);

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJsonMissing(['pin_code']);
    }

    public function test_pin_login_validates_required_field()
    {
        $response = $this->postJson('/api/pin-login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['pin_code']);
    }

    public function test_set_pin_validates_required_fields()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/pin/set', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id', 'pin_code']);
    }

    public function test_unauthenticated_user_cannot_set_pin()
    {
        $response = $this->postJson('/api/pin/set', [
            'user_id' => 1,
            'pin_code' => '123456',
            'password' => 'password123',
        ]);

        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_remove_pin()
    {
        $response = $this->postJson('/api/pin/remove', [
            'user_id' => 1,
            'password' => 'password123',
        ]);

        $response->assertStatus(401);
    }

    public function test_user_without_permission_cannot_set_pin()
    {
        Permission::firstOrCreate(['name' => 'manage-pin-codes', 'guard_name' => 'api']);

        $business = Business::factory()->create();
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);
        $otherUser = User::factory()->create();

        User_Business::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
        ]);

        // User has no permission; setting another user's PIN requires manage-pin-codes
        $response = $this->actingAs($user)->postJson('/api/pin/set', [
            'user_id' => $otherUser->id,
            'pin_code' => '123456',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'You do not have permission to manage PIN codes',
            ]);
    }

    public function test_user_without_permission_cannot_remove_pin()
    {
        Permission::firstOrCreate(['name' => 'manage-pin-codes', 'guard_name' => 'api']);

        $business = Business::factory()->create();
        $user = User::factory()->create([
            'pin_code' => '123456',
            'password' => Hash::make('password123'),
        ]);

        User_Business::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
        ]);

        // User has no permission
        $response = $this->actingAs($user)->postJson('/api/pin/remove', [
            'user_id' => 1,
            'user_id' => $user->id,
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'You do not have permission to manage PIN codes',
            ]);
    }

    public function test_pin_login_is_case_sensitive_for_numeric()
    {
        Permission::firstOrCreate(['name' => 'use-pin-login', 'guard_name' => 'api']);

        $business = Business::factory()->create();
        $user = User::factory()->create([
            'pin_code' => '000000',
        ]);

        User_Business::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
        ]);

        setPermissionsTeamId($business->id);
        $role = Role::create(['name' => 'cashier', 'guard_name' => 'api']);
        $role->givePermissionTo('use-pin-login');
        $user->assignRole($role);

        $response = $this->postJson('/api/pin-login', [
            'pin_code' => '000000',
        ]);

        $response->assertStatus(200);
    }

    public function test_multiple_users_can_have_different_pins()
    {
        Permission::firstOrCreate(['name' => 'use-pin-login', 'guard_name' => 'api']);

        $business = Business::factory()->create();

        $user1 = User::factory()->create(['pin_code' => '111111']);
        $user2 = User::factory()->create(['pin_code' => '222222']);
        $user3 = User::factory()->create(['pin_code' => '333333']);

        // Setup permissions for all users
        setPermissionsTeamId($business->id);
        $role = Role::create(['name' => 'cashier', 'guard_name' => 'api']);
        $role->givePermissionTo('use-pin-login');

        foreach ([$user1, $user2, $user3] as $user) {
            User_Business::create([
                'user_id' => $user->id,
                'business_id' => $business->id,
            ]);
            $user->assignRole($role);
        }

        $response1 = $this->postJson('/api/pin-login', ['pin_code' => '111111']);
        $response2 = $this->postJson('/api/pin-login', ['pin_code' => '222222']);
        $response3 = $this->postJson('/api/pin-login', ['pin_code' => '333333']);

        $response1->assertStatus(200);
        $response2->assertStatus(200);
        $response3->assertStatus(200);

        $this->assertEquals($user1->id, $response1->json('user.id'));
        $this->assertEquals($user2->id, $response2->json('user.id'));
        $this->assertEquals($user3->id, $response3->json('user.id'));
    }

    public function test_pin_login_generates_valid_token()
    {
        Permission::firstOrCreate(['name' => 'use-pin-login', 'guard_name' => 'api']);

        $business = Business::factory()->create();
        $user = User::factory()->create([
            'pin_code' => '123456',
        ]);

        User_Business::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
        ]);

        setPermissionsTeamId($business->id);
        $role = Role::create(['name' => 'cashier', 'guard_name' => 'api']);
        $role->givePermissionTo('use-pin-login');
        $user->assignRole($role);

        $response = $this->postJson('/api/pin-login', [
            'pin_code' => '123456',
        ]);

        $token = $response->json('token');

        // Use the token to access protected route
        $userResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user');

        $userResponse->assertStatus(200)
            ->assertJson([
                'id' => $user->id,
                'email' => $user->email,
            ]);
    }

    public function test_user_without_pin_cannot_login_with_pin()
    {
        $user = User::factory()->create([
            'pin_code' => null,
        ]);

        $response = $this->postJson('/api/pin-login', [
            'pin_code' => '123456',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid PIN code',
            ]);
    }

    public function test_user_without_permission_cannot_login_with_pin()
    {
        // Create the permission but don't assign it to the user
        Permission::firstOrCreate(['name' => 'use-pin-login', 'guard_name' => 'api']);

        $user = User::factory()->create([
            'pin_code' => '123456',
        ]);

        $response = $this->postJson('/api/pin-login', [
            'pin_code' => '123456',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'You do not have permission to use PIN login',
            ]);
    }

    public function test_user_with_permission_can_login_with_pin()
    {
        Permission::firstOrCreate(['name' => 'use-pin-login', 'guard_name' => 'api']);

        // Create business and user
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'pin_code' => '654321',
        ]);

        // Associate user with business
        User_Business::create([
            'user_id' => $user->id,
            'business_id' => $business->id,

        ]);

        // Create role and assign permission
        setPermissionsTeamId($business->id);
        $role = Role::create(['name' => 'cashier', 'guard_name' => 'api']);
        $role->givePermissionTo('use-pin-login');
        $user->assignRole($role);

        $response = $this->postJson('/api/pin-login', [
            'pin_code' => '654321',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Login successful',
                'token_type' => 'Bearer',
            ]);

        $this->assertNotEmpty($response->json('token'));
        $this->assertEquals($user->id, $response->json('user.id'));
    }

    public function test_permission_check_happens_after_pin_validation()
    {
        Permission::firstOrCreate(['name' => 'use-pin-login', 'guard_name' => 'api']);

        $user = User::factory()->create([
            'pin_code' => '123456',
        ]);

        // User doesn't have permission, but trying with wrong PIN
        $response = $this->postJson('/api/pin-login', [
            'pin_code' => '999999',
        ]);

        // Should fail with invalid PIN, not permission error
        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid PIN code',
            ]);
    }

    public function test_multiple_users_with_permission_can_use_pin_login()
    {
        Permission::firstOrCreate(['name' => 'use-pin-login', 'guard_name' => 'api']);

        $business = Business::factory()->create();

        $user1 = User::factory()->create(['pin_code' => '111111']);
        $user2 = User::factory()->create(['pin_code' => '222222']);
        $user3 = User::factory()->create(['pin_code' => '333333']);

        // Setup permissions for all users
        setPermissionsTeamId($business->id);
        $role = Role::create(['name' => 'cashier', 'guard_name' => 'api']);
        $role->givePermissionTo('use-pin-login');

        foreach ([$user1, $user2, $user3] as $user) {
            User_Business::create([
                'user_id' => $user->id,
                'business_id' => $business->id,
            ]);
            $user->assignRole($role);
        }

        $response1 = $this->postJson('/api/pin-login', ['pin_code' => '111111']);
        $response2 = $this->postJson('/api/pin-login', ['pin_code' => '222222']);
        $response3 = $this->postJson('/api/pin-login', ['pin_code' => '333333']);

        $response1->assertStatus(200);
        $response2->assertStatus(200);
        $response3->assertStatus(200);

        $this->assertEquals($user1->id, $response1->json('user.id'));
        $this->assertEquals($user2->id, $response2->json('user.id'));
        $this->assertEquals($user3->id, $response3->json('user.id'));
    }

    public function test_revoking_permission_prevents_pin_login()
    {
        Permission::firstOrCreate(['name' => 'use-pin-login', 'guard_name' => 'api']);

        $business = Business::factory()->create();
        $user = User::factory()->create([
            'pin_code' => '123456',
        ]);

        User_Business::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
        ]);

        setPermissionsTeamId($business->id);
        $role = Role::create(['name' => 'cashier', 'guard_name' => 'api']);
        $role->givePermissionTo('use-pin-login');
        $user->assignRole($role);

        // First login should succeed
        $response = $this->postJson('/api/pin-login', [
            'pin_code' => '123456',
        ]);
        $response->assertStatus(200);

        // Revoke permission by removing role
        $user->removeRole($role);

        // Second login should fail
        $response = $this->postJson('/api/pin-login', [
            'pin_code' => '123456',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'You do not have permission to use PIN login',
            ]);
    }
}
