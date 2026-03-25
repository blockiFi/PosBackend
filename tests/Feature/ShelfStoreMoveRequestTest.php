<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Product;
use App\Models\ShelfStoreMoveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\SeedsPermissions;

class ShelfStoreMoveRequestTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPermissions;

    protected User $requester;

    protected User $approver;

    protected Business $business;

    protected Branch $branch;

    protected BranchProduct $branchProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAllPermissions();

        $owner = User::factory()->create();
        $this->business = Business::factory()->create(['owner_id' => $owner->id]);
        $this->branch = Branch::factory()->create(['business_id' => $this->business->id]);

        $product = Product::factory()->create(['business_id' => $this->business->id]);
        $this->branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'shelf_quantity' => 30,
            'store_quantity' => 70,
            'stock_quantity' => 100,
            'selling_price' => 10,
        ]);

        $this->requester = User::factory()->create();
        $this->requester->businesses()->attach($this->business->id, ['is_active' => true]);
        $requesterRole = Role::create([
            'name' => 'Requester',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $requesterRole->givePermissionTo('request shelf store move');
        \DB::table('model_has_roles')->insert([
            'role_id' => $requesterRole->id,
            'model_type' => User::class,
            'model_id' => $this->requester->id,
            'business_id' => $this->business->id,
            'branch_id' => null,
        ]);

        $this->approver = User::factory()->create();
        $this->approver->businesses()->attach($this->business->id, ['is_active' => true]);
        $approverRole = Role::create([
            'name' => 'Approver',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $approverRole->givePermissionTo('approve shelf store move');
        \DB::table('model_has_roles')->insert([
            'role_id' => $approverRole->id,
            'model_type' => User::class,
            'model_id' => $this->approver->id,
            'business_id' => $this->business->id,
            'branch_id' => null,
        ]);
    }

    public function test_requester_can_create_move_to_shelf_request(): void
    {
        $response = $this->actingAs($this->requester, 'sanctum')
            ->postJson('/api/shelf-store-move-requests', [
                'branch_product_id' => $this->branchProduct->id,
                'direction' => 'to_shelf',
                'quantity' => 10,
                'reason' => 'Restock shelf',
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.direction', 'to_shelf')
            ->assertJsonPath('data.quantity', 10)
            ->assertJsonPath('data.reason', 'Restock shelf')
            ->assertJsonStructure(['data' => ['request_number', 'branch_product_id', 'requested_by', 'requested_at']]);

        $this->assertDatabaseHas('shelf_store_move_requests', [
            'branch_product_id' => $this->branchProduct->id,
            'direction' => 'to_shelf',
            'quantity' => 10,
            'status' => 'pending',
            'requested_by' => $this->requester->id,
        ]);
    }

    public function test_requester_can_create_move_to_store_request(): void
    {
        $response = $this->actingAs($this->requester, 'sanctum')
            ->postJson('/api/shelf-store-move-requests', [
                'branch_product_id' => $this->branchProduct->id,
                'direction' => 'to_store',
                'quantity' => 5,
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.direction', 'to_store')
            ->assertJsonPath('data.quantity', 5);
    }

    public function test_create_request_validates_quantity_available(): void
    {
        $response = $this->actingAs($this->requester, 'sanctum')
            ->postJson('/api/shelf-store-move-requests', [
                'branch_product_id' => $this->branchProduct->id,
                'direction' => 'to_shelf',
                'quantity' => 100,
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Insufficient quantity in store']);
    }

    public function test_approver_can_approve_request_and_stock_moves(): void
    {
        $req = ShelfStoreMoveRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_product_id' => $this->branchProduct->id,
            'direction' => ShelfStoreMoveRequest::DIRECTION_TO_SHELF,
            'quantity' => 15,
            'status' => ShelfStoreMoveRequest::STATUS_PENDING,
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
        ]);

        $response = $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/shelf-store-move-requests/{$req->id}/approve", [], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $this->branchProduct->refresh();
        $this->assertSame(45, $this->branchProduct->shelf_quantity);
        $this->assertSame(55, $this->branchProduct->store_quantity);
    }

    public function test_approver_can_approve_request_with_nullable_quantity(): void
    {
        $req = ShelfStoreMoveRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_product_id' => $this->branchProduct->id,
            'direction' => ShelfStoreMoveRequest::DIRECTION_TO_SHELF,
            'quantity' => 10,
            'status' => ShelfStoreMoveRequest::STATUS_PENDING,
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
        ]);

        $response = $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/shelf-store-move-requests/{$req->id}/approve", [
                'quantity' => null,
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $this->branchProduct->refresh();
        $this->assertSame(40, $this->branchProduct->shelf_quantity);
        $this->assertSame(60, $this->branchProduct->store_quantity);
    }

    public function test_approver_can_reject_request(): void
    {
        $req = ShelfStoreMoveRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_product_id' => $this->branchProduct->id,
            'direction' => 'to_shelf',
            'quantity' => 10,
            'status' => 'pending',
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
        ]);

        $response = $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/shelf-store-move-requests/{$req->id}/reject", [
                'reason' => 'Not needed',
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.review_notes', 'Not needed');

        $this->branchProduct->refresh();
        $this->assertSame(30, $this->branchProduct->shelf_quantity);
        $this->assertSame(70, $this->branchProduct->store_quantity);
    }

    public function test_requester_cannot_approve(): void
    {
        $req = ShelfStoreMoveRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_product_id' => $this->branchProduct->id,
            'direction' => 'to_shelf',
            'quantity' => 10,
            'status' => 'pending',
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
        ]);

        $response = $this->actingAs($this->requester, 'sanctum')
            ->postJson("/api/shelf-store-move-requests/{$req->id}/approve", [], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_can_list_and_show_requests(): void
    {
        ShelfStoreMoveRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_product_id' => $this->branchProduct->id,
            'direction' => 'to_shelf',
            'quantity' => 5,
            'status' => 'pending',
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
        ]);

        $response = $this->actingAs($this->requester, 'sanctum')
            ->getJson('/api/shelf-store-move-requests?current_business_id='.$this->business->id, [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $id = $response->json('data.0.id');
        $show = $this->actingAs($this->requester, 'sanctum')
            ->getJson("/api/shelf-store-move-requests/{$id}?current_business_id=".$this->business->id, [
                'X-Business-Id' => $this->business->id,
            ]);
        $show->assertStatus(200)
            ->assertJsonPath('data.id', $id);
    }
}
