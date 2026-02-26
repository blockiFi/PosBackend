<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserBusinessRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $otherUser;

    protected User $thirdUser;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        // Create owner and business
        $this->owner = User::factory()->create();
        $this->business = Business::factory()->create([
            'owner_id' => $this->owner->id,
        ]);

        // Attach owner to business
        $this->business->users()->attach($this->owner->id, [
            'is_active' => true,
        ]);

        // Create other users
        $this->otherUser = User::factory()->create();
        $this->thirdUser = User::factory()->create();
    }

    public function test_can_list_users_in_business()
    {
        // Add another user to business
        $this->business->users()->attach($this->otherUser->id, [
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson('/api/business-users', [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'email',
                        'is_active',
                        'joined_at',
                        'roles',
                    ],
                ],
            ]);
    }

    public function test_owner_can_add_user_to_business()
    {
        $response = $this->actingAs($this->owner)
            ->postJson('/api/business-users', [
                'email' => $this->otherUser->email,
                'name' => $this->otherUser->name,
                'is_active' => true,
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'User added to business',
                'data' => [
                    'user' => [
                        'id' => $this->otherUser->id,
                        'email' => $this->otherUser->email,
                    ],
                    'business' => [
                        'id' => $this->business->id,
                    ],
                    'is_active' => true,
                ],
            ]);

        // Verify in database
        $this->assertDatabaseHas('user__businesses', [
            'user_id' => $this->otherUser->id,
            'business_id' => $this->business->id,
            'is_active' => true,
        ]);
    }

    public function test_adding_new_user_returns_generated_password()
    {
        $response = $this->actingAs($this->owner)
            ->postJson('/api/business-users', [
                'email' => 'newuser@example.com',
                'name' => 'New User',
                'is_active' => true,
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.is_new_user', true)
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'password',
                ],
            ]);

        $password = $response->json('data.password');
        $this->assertNotEmpty($password);
        $this->assertSame(16, strlen($password));

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check($password, $user->password));
    }

    public function test_owner_can_add_user_to_business_with_roles()
    {
        $role = Role::create([
            'name' => 'staff',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson('/api/business-users', [
                'email' => $this->thirdUser->email,
                'name' => $this->thirdUser->name,
                'is_active' => true,
                'role_ids' => [$role->id],
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'User added to business',
                'data' => [
                    'user' => [
                        'id' => $this->thirdUser->id,
                        'email' => $this->thirdUser->email,
                    ],
                    'roles' => [
                        ['id' => $role->id, 'name' => 'staff'],
                    ],
                ],
            ]);

        $this->assertDatabaseHas('model_has_roles', [
            'model_type' => User::class,
            'model_id' => $this->thirdUser->id,
            'role_id' => $role->id,
            'business_id' => $this->business->id,
        ]);
    }

    public function test_adding_user_with_cashier_role_auto_generates_and_returns_pin(): void
    {
        $cashierRole = Role::create([
            'name' => 'Cashier',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson('/api/business-users', [
                'email' => $this->thirdUser->email,
                'name' => $this->thirdUser->name,
                'is_active' => true,
                'role_ids' => [$cashierRole->id],
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'user',
                    'roles',
                    'pin_code',
                ],
            ])
            ->assertJsonPath('data.roles.0.name', 'Cashier');

        $pinCode = $response->json('data.pin_code');
        $this->assertNotNull($pinCode);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $pinCode);

        $this->thirdUser->refresh();
        $this->assertSame($pinCode, $this->thirdUser->pin_code);
    }

    public function test_adding_cashier_with_existing_pin_does_not_overwrite_or_return_pin(): void
    {
        $this->thirdUser->update(['pin_code' => '123456']);

        $cashierRole = Role::create([
            'name' => 'Cashier',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson('/api/business-users', [
                'email' => $this->thirdUser->email,
                'name' => $this->thirdUser->name,
                'is_active' => true,
                'role_ids' => [$cashierRole->id],
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(201);
        $this->assertArrayNotHasKey('pin_code', $response->json('data'));

        $this->thirdUser->refresh();
        $this->assertSame('123456', $this->thirdUser->pin_code);
    }

    public function test_cannot_add_user_twice_to_business()
    {
        // Add user first time
        $this->business->users()->attach($this->otherUser->id, [
            'is_active' => true,
        ]);

        // Try to add again
        $response = $this->actingAs($this->owner)
            ->postJson('/api/business-users', [
                'email' => $this->otherUser->email,
                'name' => $this->otherUser->name,
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'User is already a member of this business',
            ]);
    }

    public function test_non_owner_cannot_add_users()
    {
        // Add otherUser as regular member
        $this->business->users()->attach($this->otherUser->id, [
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->otherUser)
            ->postJson('/api/business-users', [
                'user_id' => $this->thirdUser->id,
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Only business owners can add users',
            ]);
    }

    public function test_owner_can_update_user_status()
    {
        // Add user to business
        $this->business->users()->attach($this->otherUser->id, [
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->owner)
            ->putJson("/api/business-users/{$this->otherUser->id}", [
                'is_active' => false,
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'User status updated',
                'data' => [
                    'user' => [
                        'id' => $this->otherUser->id,
                    ],
                    'is_active' => false,
                ],
            ]);

        // Verify in database
        $this->assertDatabaseHas('user__businesses', [
            'user_id' => $this->otherUser->id,
            'business_id' => $this->business->id,
            'is_active' => false,
        ]);
    }

    public function test_owner_cannot_deactivate_themselves()
    {
        $response = $this->actingAs($this->owner)
            ->putJson("/api/business-users/{$this->owner->id}", [
                'is_active' => false,
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Business owner cannot deactivate themselves',
            ]);
    }

    public function test_non_owner_cannot_update_user_status()
    {
        // Add both users to business
        $this->business->users()->attach($this->otherUser->id, [
            'is_active' => true,
        ]);
        $this->business->users()->attach($this->thirdUser->id, [
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->otherUser)
            ->putJson("/api/business-users/{$this->thirdUser->id}", [
                'is_active' => false,
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Only business owners can update users',
            ]);
    }

    public function test_owner_can_remove_user_from_business()
    {
        // Add user to business
        $this->business->users()->attach($this->otherUser->id, [
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->owner)
            ->deleteJson("/api/business-users/{$this->otherUser->id}", [], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'User removed from business',
            ]);

        // Verify removed from database
        $this->assertDatabaseMissing('user__businesses', [
            'user_id' => $this->otherUser->id,
            'business_id' => $this->business->id,
        ]);
    }

    public function test_owner_cannot_remove_themselves()
    {
        $response = $this->actingAs($this->owner)
            ->deleteJson("/api/business-users/{$this->owner->id}", [], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Business owner cannot remove themselves from the business',
            ]);
    }

    public function test_non_owner_cannot_remove_users()
    {
        // Add both users to business
        $this->business->users()->attach($this->otherUser->id, [
            'is_active' => true,
        ]);
        $this->business->users()->attach($this->thirdUser->id, [
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->otherUser)
            ->deleteJson("/api/business-users/{$this->thirdUser->id}", [], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Only business owners can remove users',
            ]);
    }

    public function test_can_get_specific_user_details_in_business()
    {
        // Add user to business
        $this->business->users()->attach($this->otherUser->id, [
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/business-users/{$this->otherUser->id}", [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'email',
                    'is_active',
                    'is_owner',
                    'joined_at',
                    'updated_at',
                    'roles',
                    'permissions',
                ],
            ])
            ->assertJson([
                'data' => [
                    'id' => $this->otherUser->id,
                    'is_owner' => false,
                ],
            ]);
    }

    public function test_cannot_get_user_not_in_business()
    {
        $response = $this->actingAs($this->owner)
            ->getJson("/api/business-users/{$this->otherUser->id}", [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'User is not a member of this business',
            ]);
    }

    public function test_removing_user_also_removes_role_assignments()
    {
        // Add user to business
        $this->business->users()->attach($this->otherUser->id, [
            'is_active' => true,
        ]);

        // Assign a role
        $role = \Spatie\Permission\Models\Role::create([
            'name' => 'Test Role',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);

        \Illuminate\Support\Facades\DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $this->otherUser->id,
            'business_id' => $this->business->id,
        ]);

        // Verify role is assigned
        $this->assertDatabaseHas('model_has_roles', [
            'model_id' => $this->otherUser->id,
            'business_id' => $this->business->id,
        ]);

        // Remove user from business
        $this->actingAs($this->owner)
            ->deleteJson("/api/business-users/{$this->otherUser->id}", [], [
                'X-Business-Id' => $this->business->id,
            ]);

        // Verify role assignment is removed
        $this->assertDatabaseMissing('model_has_roles', [
            'model_id' => $this->otherUser->id,
            'business_id' => $this->business->id,
        ]);
    }

    public function test_requires_business_context()
    {
        $response = $this->actingAs($this->owner)
            ->getJson('/api/business-users');

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Business context is required',
            ]);
    }

    public function test_validates_user_id_when_adding()
    {
        $response = $this->actingAs($this->owner)
            ->postJson('/api/business-users', [
                // missing email and name
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'name']);
    }

    public function test_validates_is_active_when_updating()
    {
        $this->business->users()->attach($this->otherUser->id, [
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->owner)
            ->putJson("/api/business-users/{$this->otherUser->id}", [
                'is_active' => 'invalid', // Should be boolean
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);
    }

    public function test_owner_can_set_password_for_business_user(): void
    {
        $this->business->users()->attach($this->otherUser->id, ['is_active' => true]);

        $response = $this->actingAs($this->owner)
            ->putJson("/api/business-users/{$this->otherUser->id}/set-password", [
                'password' => 'newsecret123',
                'password_confirmation' => 'newsecret123',
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Password updated successfully',
                'data' => [
                    'user' => [
                        'id' => $this->otherUser->id,
                        'name' => $this->otherUser->name,
                        'email' => $this->otherUser->email,
                    ],
                ],
            ]);

        $this->otherUser->refresh();
        $this->assertTrue(Hash::check('newsecret123', $this->otherUser->password));
    }

    public function test_user_with_set_user_password_permission_can_set_password(): void
    {
        $this->business->users()->attach($this->otherUser->id, ['is_active' => true]);
        $this->business->users()->attach($this->thirdUser->id, ['is_active' => true]);

        setPermissionsTeamId($this->business->id);
        $permission = Permission::firstOrCreate(['name' => 'set user password', 'guard_name' => 'api']);
        $role = Role::create([
            'name' => 'Password Manager',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $role->givePermissionTo($permission);
        $this->thirdUser->assignRole($role);

        $response = $this->actingAs($this->thirdUser)
            ->putJson("/api/business-users/{$this->otherUser->id}/set-password", [
                'password' => 'updatedpass456',
                'password_confirmation' => 'updatedpass456',
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Password updated successfully']);

        $this->otherUser->refresh();
        $this->assertTrue(Hash::check('updatedpass456', $this->otherUser->password));
    }

    public function test_user_without_permission_cannot_set_password(): void
    {
        $this->business->users()->attach($this->otherUser->id, ['is_active' => true]);
        $this->business->users()->attach($this->thirdUser->id, ['is_active' => true]);
        setPermissionsTeamId($this->business->id);

        $response = $this->actingAs($this->thirdUser)
            ->putJson("/api/business-users/{$this->otherUser->id}/set-password", [
                'password' => 'newsecret123',
                'password_confirmation' => 'newsecret123',
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Unauthorized']);
    }

    public function test_set_password_validates_password_confirmation(): void
    {
        $this->business->users()->attach($this->otherUser->id, ['is_active' => true]);

        $response = $this->actingAs($this->owner)
            ->putJson("/api/business-users/{$this->otherUser->id}/set-password", [
                'password' => 'newsecret123',
                'password_confirmation' => 'different',
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_set_password_rejects_user_not_in_business(): void
    {
        $response = $this->actingAs($this->owner)
            ->putJson("/api/business-users/{$this->otherUser->id}/set-password", [
                'password' => 'newsecret123',
                'password_confirmation' => 'newsecret123',
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(404)
            ->assertJson(['message' => 'User is not a member of this business']);
    }

    public function test_set_password_without_body_generates_and_returns_password(): void
    {
        $this->business->users()->attach($this->otherUser->id, ['is_active' => true]);

        $response = $this->actingAs($this->owner)
            ->putJson("/api/business-users/{$this->otherUser->id}/set-password", [], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Password updated successfully',
                'data' => [
                    'user' => [
                        'id' => $this->otherUser->id,
                        'name' => $this->otherUser->name,
                        'email' => $this->otherUser->email,
                    ],
                ],
            ])
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'password',
                ],
            ]);

        $generatedPassword = $response->json('data.password');
        $this->assertNotEmpty($generatedPassword);
        $this->assertSame(16, strlen($generatedPassword));

        $this->otherUser->refresh();
        $this->assertTrue(Hash::check($generatedPassword, $this->otherUser->password));
    }
}
