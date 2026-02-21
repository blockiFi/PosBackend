<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchRoutesTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Branch Listing Tests ====================

    public function test_authenticated_user_can_list_branches_with_business_context()
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

        $branch1 = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_main' => true,
            'is_active' => true,
        ]);

        $branch2 = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Downtown Branch',
            'code' => 'DTN',
            'is_main' => false,
            'is_active' => true,
        ]);

        $user->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->getJson('/api/branches');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Main Branch', 'code' => 'MAIN'])
            ->assertJsonFragment(['name' => 'Downtown Branch', 'code' => 'DTN']);
    }

    public function test_listing_branches_requires_business_context()
    {
        $user = User::create([
            'name' => 'User',
            'email' => 'user@example.test',
            'password' => Hash::make('password'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/branches');

        $response->assertStatus(400)
            ->assertJson(['message' => 'Business context is required']);
    }

    public function test_user_cannot_list_branches_for_unauthorized_business()
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
            ->getJson('/api/branches');

        $response->assertStatus(403)
            ->assertJson(['message' => 'You do not have access to this business']);
    }

    // ==================== Branch Creation Tests ====================

    public function test_owner_can_create_branch()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner2@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $mainBranch = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_main' => true,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $payload = [
            'name' => 'New Branch',
            'code' => 'NEW',
            'email' => 'newbranch@example.test',
            'phone' => '+1234567890',
            'address' => '123 Main St',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
            'time_zone' => 'America/New_York',
            'tax_rate' => 8.5,
            'is_active' => true,
        ];

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/branches', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'New Branch')
            ->assertJsonPath('data.code', 'NEW')
            ->assertJsonPath('data.email', 'newbranch@example.test')
            ->assertJsonPath('data.is_main', false);

        $this->assertDatabaseHas('branches', [
            'business_id' => $business->id,
            'name' => 'New Branch',
            'code' => 'NEW',
            'is_main' => false,
        ]);
    }

    public function test_non_owner_cannot_create_branch()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner3@example.test',
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
            ->postJson('/api/branches', [
                'name' => 'Unauthorized Branch',
                'code' => 'UNAUTH',
            ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'You do not have permission to create branches']);

        $this->assertDatabaseMissing('branches', [
            'code' => 'UNAUTH',
        ]);
    }

    public function test_branch_creation_validates_required_fields()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner4@example.test',
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
            ->postJson('/api/branches', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'code']);
    }

    public function test_branch_code_must_be_unique_per_business()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner5@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $existingBranch = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Existing Branch',
            'code' => 'EXIST',
            'is_main' => false,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/branches', [
                'name' => 'New Branch',
                'code' => 'EXIST',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_creating_main_branch_unsets_other_main_branches()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner6@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $oldMainBranch = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Old Main',
            'code' => 'OLD',
            'is_main' => true,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->postJson('/api/branches', [
                'name' => 'New Main',
                'code' => 'NEW',
                'is_main' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.is_main', true);

        $this->assertDatabaseHas('branches', [
            'id' => $oldMainBranch->id,
            'is_main' => false,
        ]);

        $this->assertDatabaseHas('branches', [
            'code' => 'NEW',
            'is_main' => true,
        ]);
    }

    // ==================== Branch Show Tests ====================

    public function test_user_can_view_branch_details()
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

        $branch = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Viewable Branch',
            'code' => 'VIEW',
            'email' => 'view@example.test',
            'phone' => '+1234567890',
            'is_main' => false,
        ]);

        $user->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->getJson("/api/branches/{$branch->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Viewable Branch')
            ->assertJsonPath('data.code', 'VIEW')
            ->assertJsonPath('data.email', 'view@example.test');
    }

    public function test_user_cannot_view_branch_from_unauthorized_business()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner7@example.test',
            'password' => Hash::make('password'),
        ]);

        $unauthorizedUser = User::create([
            'name' => 'Unauthorized',
            'email' => 'unauthorized2@example.test',
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
            'name' => 'Private Branch',
            'code' => 'PRIV',
            'is_main' => false,
        ]);

        Sanctum::actingAs($unauthorizedUser);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->getJson("/api/branches/{$branch->id}");

        $response->assertStatus(403)
            ->assertJson(['message' => 'You do not have access to this business']);
    }

    public function test_viewing_nonexistent_branch_returns_404()
    {
        $user = User::create([
            'name' => 'User',
            'email' => 'user2@example.test',
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
            ->getJson('/api/branches/999');

        $response->assertStatus(404)
            ->assertJson(['message' => 'Branch not found']);
    }

    // ==================== Branch Update Tests ====================

    public function test_owner_can_update_branch()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner8@example.test',
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
            'name' => 'Original Name',
            'code' => 'ORIG',
            'is_main' => false,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->putJson("/api/branches/{$branch->id}", [
                'name' => 'Updated Name',
                'email' => 'updated@example.test',
                'phone' => '+9876543210',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.email', 'updated@example.test')
            ->assertJsonPath('data.phone', '+9876543210');

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'name' => 'Updated Name',
            'email' => 'updated@example.test',
        ]);
    }

    public function test_non_owner_cannot_update_branch()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner9@example.test',
            'password' => Hash::make('password'),
        ]);

        $staff = User::create([
            'name' => 'Staff',
            'email' => 'staff2@example.test',
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
            'name' => 'Protected Branch',
            'code' => 'PROT',
            'is_main' => false,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $staff->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->putJson("/api/branches/{$branch->id}", [
                'name' => 'Hacked Name',
            ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'You do not have permission to update branches']);

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'name' => 'Protected Branch',
        ]);
    }

    public function test_updating_branch_to_main_unsets_other_main_branches()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner10@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $currentMain = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Current Main',
            'code' => 'CURR',
            'is_main' => true,
        ]);

        $regularBranch = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Regular Branch',
            'code' => 'REG',
            'is_main' => false,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->putJson("/api/branches/{$regularBranch->id}", [
                'is_main' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_main', true);

        $this->assertDatabaseHas('branches', [
            'id' => $currentMain->id,
            'is_main' => false,
        ]);

        $this->assertDatabaseHas('branches', [
            'id' => $regularBranch->id,
            'is_main' => true,
        ]);
    }

    public function test_branch_update_validates_code_uniqueness()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner11@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $branch1 = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Branch One',
            'code' => 'ONE',
            'is_main' => false,
        ]);

        $branch2 = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Branch Two',
            'code' => 'TWO',
            'is_main' => false,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->putJson("/api/branches/{$branch1->id}", [
                'code' => 'TWO',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    // ==================== Branch Delete Tests ====================

    public function test_owner_can_delete_branch()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner12@example.test',
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
            'name' => 'Deletable Branch',
            'code' => 'DEL',
            'is_main' => false,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->deleteJson("/api/branches/{$branch->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Branch deleted']);

        $this->assertSoftDeleted('branches', ['id' => $branch->id]);
    }

    public function test_non_owner_cannot_delete_branch()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner13@example.test',
            'password' => Hash::make('password'),
        ]);

        $manager = User::create([
            'name' => 'Manager',
            'email' => 'manager2@example.test',
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
            'name' => 'Safe Branch',
            'code' => 'SAFE',
            'is_main' => false,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        $manager->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($manager);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->deleteJson("/api/branches/{$branch->id}");

        $response->assertStatus(403)
            ->assertJson(['message' => 'You do not have permission to delete branches']);

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'deleted_at' => null,
        ]);
    }

    public function test_cannot_delete_main_branch()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner14@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'My Store',
            'currency' => 'USD',
        ]);

        $mainBranch = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_main' => true,
        ]);

        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->withHeaders(['X-Business-Id' => $business->id])
            ->deleteJson("/api/branches/{$mainBranch->id}");

        $response->assertStatus(422)
            ->assertJson(['message' => 'Cannot delete the main branch']);

        $this->assertDatabaseHas('branches', [
            'id' => $mainBranch->id,
            'deleted_at' => null,
        ]);
    }

    public function test_deleting_nonexistent_branch_returns_404()
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner15@example.test',
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
            ->deleteJson('/api/branches/999');

        $response->assertStatus(404)
            ->assertJson(['message' => 'Branch not found']);
    }

    // ==================== Authentication Tests ====================

    public function test_unauthenticated_user_cannot_access_branch_routes()
    {
        $response = $this->getJson('/api/branches');
        $response->assertStatus(401);

        $response = $this->postJson('/api/branches', []);
        $response->assertStatus(401);

        $response = $this->getJson('/api/branches/1');
        $response->assertStatus(401);

        $response = $this->putJson('/api/branches/1', []);
        $response->assertStatus(401);

        $response = $this->deleteJson('/api/branches/1');
        $response->assertStatus(401);
    }
}
