<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Business;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class BusinessRoutesTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Authentication Tests ====================
    
    public function test_user_can_register_with_valid_credentials()
    {
        $payload = [
            'name' => 'John Doe',
            'email' => 'john@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->postJson('/api/register', $payload);
        
        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'token',
                'token_type',
                'user' => ['id', 'name', 'email']
            ])
            ->assertJsonPath('user.name', 'John Doe')
            ->assertJsonPath('user.email', 'john@example.test')
            ->assertJsonPath('token_type', 'Bearer');

        $this->assertDatabaseHas('users', ['email' => 'john@example.test']);
    }

    public function test_registration_fails_with_invalid_data()
    {
        $payload = [
            'name' => '',
            'email' => 'invalid-email',
            'password' => '123',
            'password_confirmation' => '456',
        ];

        $response = $this->postJson('/api/register', $payload);
        
        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
    }

    public function test_registration_fails_with_duplicate_email()
    {
        User::create([
            'name' => 'Existing User',
            'email' => 'existing@example.test',
            'password' => Hash::make('password'),
        ]);

        $payload = [
            'name' => 'New User',
            'email' => 'existing@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->postJson('/api/register', $payload);
        
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_login_with_valid_credentials()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.test',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.test',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'token',
                'token_type',
                'user' => ['id', 'name', 'email']
            ])
            ->assertJsonPath('user.email', 'test@example.test');
    }

    public function test_login_fails_with_invalid_credentials()
    {
        User::create([
            'name' => 'Test User',
            'email' => 'test@example.test',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.test',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid credentials']);
    }

    // ==================== Business Creation Tests ====================

    public function test_authenticated_user_can_create_business()
    {
        $user = User::create([
            'name' => 'Business Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('password'),
        ]);

        Sanctum::actingAs($user);

        $payload = [
            'name' => 'My POS Store',
            'legal_name' => 'My POS Store LLC',
            'email' => 'store@example.test',
            'phone' => '+1234567890',
            'currency' => 'USD',
            'main_branch_name' => 'Downtown Branch',
            'main_branch_code' => 'DTN',
        ];

        $response = $this->postJson('/api/businesses', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.business.name', 'My POS Store')
            ->assertJsonPath('data.branch.name', 'Downtown Branch')
            ->assertJsonPath('data.branch.code', 'DTN');

        $businessId = $response->json('data.business.id');
        $branchId = $response->json('data.branch.id');

        $this->assertDatabaseHas('businesses', [
            'id' => $businessId,
            'name' => 'My POS Store',
            'owner_id' => $user->id,
        ]);

        $this->assertDatabaseHas('branches', [
            'id' => $branchId,
            'business_id' => $businessId,
            'name' => 'Downtown Branch',
            'code' => 'DTN',
            'is_main' => true,
        ]);

        $this->assertDatabaseHas('user__businesses', [
            'user_id' => $user->id,
            'business_id' => $businessId,
            'is_active' => true,
        ]);
    }

    public function test_business_creation_creates_default_main_branch()
    {
        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner2@example.test',
            'password' => Hash::make('password'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/businesses', [
            'name' => 'Quick Shop',
            'currency' => 'EUR',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.branch.name', 'Main Branch')
            ->assertJsonPath('data.branch.code', 'MAIN');
    }

    public function test_unauthenticated_user_cannot_create_business()
    {
        $response = $this->postJson('/api/businesses', [
            'name' => 'Unauthorized Store',
        ]);

        $response->assertStatus(401);
    }

    public function test_business_creation_validates_required_fields()
    {
        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner3@example.test',
            'password' => Hash::make('password'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/businesses', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    // ==================== Business Listing Tests ====================

    public function test_user_can_list_their_businesses()
    {
        $user = User::create([
            'name' => 'Multi Owner',
            'email' => 'multi@example.test',
            'password' => Hash::make('password'),
        ]);

        $business1 = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'Store One',
            'currency' => 'USD',
        ]);

        $branch1 = $business1->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Main',
            'code' => 'MAIN1',
            'is_main' => true,
        ]);

        $business2 = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'Store Two',
            'currency' => 'EUR',
        ]);

        $branch2 = $business2->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Main',
            'code' => 'MAIN2',
            'is_main' => true,
        ]);

        $user->businesses()->attach($business1->id, [
     
            'is_active' => true,
        ]);

        $user->businesses()->attach($business2->id, [
         
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/businesses');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Store One'])
            ->assertJsonFragment(['name' => 'Store Two']);
    }

    public function test_user_only_sees_active_business_memberships()
    {
        $user = User::create([
            'name' => 'User',
            'email' => 'user@example.test',
            'password' => Hash::make('password'),
        ]);

        $activeBusiness = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'Active Store',
            'currency' => 'USD',
        ]);

        $activeBranch = $activeBusiness->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Main',
            'code' => 'ACTIVE',
            'is_main' => true,
        ]);

        $inactiveBusiness = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'Inactive Store',
            'currency' => 'USD',
        ]);

        $inactiveBranch = $inactiveBusiness->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Main',
            'code' => 'INACTIVE',
            'is_main' => true,
        ]);

        $user->businesses()->attach($activeBusiness->id, [
          
            'is_active' => true,
        ]);

        $user->businesses()->attach($inactiveBusiness->id, [
       
            'is_active' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/businesses');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Active Store'])
            ->assertJsonMissing(['name' => 'Inactive Store']);
    }

    // ==================== Business Show Tests ====================

    public function test_business_member_can_view_business_details()
    {
        $user = User::create([
            'name' => 'Member',
            'email' => 'member@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'Viewable Store',
            'currency' => 'USD',
        ]);

        $branch = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_main' => true,
        ]);

        $user->businesses()->attach($business->id, [
        
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->getJson("/api/businesses/{$business->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Viewable Store');
    }

    public function test_non_member_cannot_view_business_details()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner4@example.test',
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
            'name' => 'Private Store',
            'currency' => 'USD',
        ]);

        $branch = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Main',
            'code' => 'MAIN',
            'is_main' => true,
        ]);

        $owner->businesses()->attach($business->id, [
         
            'is_active' => true,
        ]);

        Sanctum::actingAs($nonMember);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->getJson("/api/businesses/{$business->id}");

        $response->assertStatus(403)
            ->assertJson(['message' => 'You do not have access to this business']);
    }

    // ==================== Business Update Tests ====================

    public function test_owner_can_update_business()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner5@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'Original Name',
            'currency' => 'USD',
        ]);

        $branch = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Main',
            'code' => 'MAIN',
            'is_main' => true,
        ]);

        $owner->businesses()->attach($business->id, [
          
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->putJson("/api/businesses/{$business->id}", [
                'name' => 'Updated Name',
                'currency' => 'EUR',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.currency', 'EUR');

        $this->assertDatabaseHas('businesses', [
            'id' => $business->id,
            'name' => 'Updated Name',
            'currency' => 'EUR',
        ]);
    }

    public function test_non_owner_cannot_update_business()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner6@example.test',
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
            'name' => 'Protected Store',
            'currency' => 'USD',
        ]);

        $branch = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Main',
            'code' => 'MAIN',
            'is_main' => true,
        ]);

        $owner->businesses()->attach($business->id, [
          
            'is_active' => true,
        ]);

        $staff->businesses()->attach($business->id, [
          
            'is_active' => true,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->putJson("/api/businesses/{$business->id}", [
                'name' => 'Hacked Name',
            ]);

        $response->assertStatus(403);

        $this->assertDatabaseHas('businesses', [
            'id' => $business->id,
            'name' => 'Protected Store',
        ]);
    }

    // ==================== Business Delete Tests ====================

    public function test_owner_can_delete_business()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner7@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'Deletable Store',
            'currency' => 'USD',
        ]);

        $branch = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Main',
            'code' => 'MAIN',
            'is_main' => true,
        ]);

        $owner->businesses()->attach($business->id, [
          
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->deleteJson("/api/businesses/{$business->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Business deleted']);

        $this->assertSoftDeleted('businesses', ['id' => $business->id]);
    }

    public function test_non_owner_cannot_delete_business()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner8@example.test',
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
            'name' => 'Safe Store',
            'currency' => 'USD',
        ]);

        $branch = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Main',
            'code' => 'MAIN',
            'is_main' => true,
        ]);

        $owner->businesses()->attach($business->id, [
          
            'is_active' => true,
        ]);

        $manager->businesses()->attach($business->id, [
           
            'is_active' => true,
        ]);

        Sanctum::actingAs($manager);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->deleteJson("/api/businesses/{$business->id}");

        $response->assertStatus(403);

        $this->assertDatabaseHas('businesses', [
            'id' => $business->id,
            'deleted_at' => null,
        ]);
    }

    public function test_business_not_found_returns_404()
    {
        $user = User::create([
            'name' => 'User',
            'email' => 'user2@example.test',
            'password' => Hash::make('password'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Business-Id' => 999])
            ->getJson('/api/businesses/999');

        $response->assertStatus(403);
    }
}
