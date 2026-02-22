<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockTransferRequest;
use App\Models\User;
use App\Models\User_Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockTransferWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    private User $approver;

    private Business $business;

    private Branch $branch;

    private Branch $branchTo;

    private BranchProduct $branchProduct;

    protected function setUp(): void
    {
        parent::setUp();

        // Create users first
        $owner = User::factory()->create();
        $this->requester = User::factory()->create(['name' => 'Requester User']);
        $this->approver = User::factory()->create(['name' => 'Approver User']);

        // Create business with valid owner_id
        $this->business = Business::create([
            'name' => 'Test Business',
            'email' => 'business@test.com',
            'owner_id' => $owner->id,
        ]);

        $this->branch = Branch::create([
            'business_id' => $this->business->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
        ]);
        $this->branchTo = Branch::create([
            'business_id' => $this->business->id,
            'name' => 'Second Branch',
            'code' => 'SEC',
        ]);

        // Create users
        $this->requester = User::factory()->create(['name' => 'Requester User']);
        $this->approver = User::factory()->create(['name' => 'Approver User']);

        // Attach users to business
        foreach ([$this->requester, $this->approver] as $user) {
            User_Business::create([
                'user_id' => $user->id,
                'business_id' => $this->business->id,
                'is_active' => true,
            ]);
        }

        // Create permissions
        Permission::firstOrCreate(['name' => 'request stock transfer', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'approve stock transfer', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'accept stock transfer', 'guard_name' => 'api']);

        // Create roles
        $requesterRole = Role::create([
            'name' => 'Stock Requester',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $requesterRole->givePermissionTo('request stock transfer');

        $approverRole = Role::create([
            'name' => 'Stock Approver',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $approverRole->givePermissionTo('approve stock transfer');

        // Assign roles
        DB::table('model_has_roles')->insert([
            [
                'role_id' => $requesterRole->id,
                'model_type' => User::class,
                'model_id' => $this->requester->id,
                'business_id' => $this->business->id,
            ],
            [
                'role_id' => $approverRole->id,
                'model_type' => User::class,
                'model_id' => $this->approver->id,
                'business_id' => $this->business->id,
            ],
        ]);

        // Create product with stock
        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
        ]);

        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Test Product',
        ]);

        $this->branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'selling_price' => 100.00,
            'shelf_quantity' => 10,
            'store_quantity' => 100,
            'stock_quantity' => 110,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function test_user_with_permission_can_create_stock_transfer_request(): void
    {
        $response = $this->actingAs($this->requester, 'sanctum')
            ->postJson('/api/stock-transfer-requests', [
                'current_business_id' => $this->business->id,
                'branch_from_id' => $this->branch->id,
                'branch_to_id' => $this->branchTo->id,
                'branch_product_id' => $this->branchProduct->id,
                'quantity_requested' => 20,
                'reason' => 'Need to restock shelf',
                'priority' => 'normal',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'request_number',
                    'status',
                    'quantity_requested',
                    'requested_by',
                ],
            ])
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.quantity_requested', 20);

        $this->assertDatabaseHas('stock_transfer_requests', [
            'business_id' => $this->business->id,
            'branch_from_id' => $this->branch->id,
            'branch_to_id' => $this->branchTo->id,
            'quantity_requested' => 20,
            'status' => 'pending',
            'requested_by' => $this->requester->id,
        ]);
    }

    public function test_user_without_permission_cannot_create_request(): void
    {
        $unauthorizedUser = User::factory()->create();
        User_Business::create([
            'user_id' => $unauthorizedUser->id,
            'business_id' => $this->business->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($unauthorizedUser, 'sanctum')
            ->postJson('/api/stock-transfer-requests', [
                'current_business_id' => $this->business->id,
                'branch_from_id' => $this->branch->id,
                'branch_to_id' => $this->branchTo->id,
                'branch_product_id' => $this->branchProduct->id,
                'quantity_requested' => 20,
            ]);

        $response->assertStatus(403);
    }

    public function test_cannot_request_more_than_available_stock(): void
    {
        $response = $this->actingAs($this->requester, 'sanctum')
            ->postJson('/api/stock-transfer-requests', [
                'current_business_id' => $this->business->id,
                'branch_from_id' => $this->branch->id,
                'branch_to_id' => $this->branchTo->id,
                'branch_product_id' => $this->branchProduct->id,
                'quantity_requested' => 150, // More than shelf+store (110)
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient stock');
    }

    public function test_approver_can_approve_pending_request(): void
    {
        $request = StockTransferRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_from_id' => $this->branch->id,
            'branch_to_id' => $this->branchTo->id,
            'direction' => 'out',
            'branch_product_id' => $this->branchProduct->id,
            'quantity_requested' => 20,
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/stock-transfer-requests/{$request->id}/approve", [
                'current_business_id' => $this->business->id,
                'notes' => 'Approved for restocking',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewed_by.id', $this->approver->id)
            ->assertJsonStructure(['data' => ['transfer_in_request' => ['id', 'request_number', 'status']]]);

        $this->assertDatabaseHas('stock_transfer_requests', [
            'id' => $request->id,
            'status' => 'approved',
            'reviewed_by' => $this->approver->id,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'branch_id' => $this->branch->id,
            'type' => 'transfer_out',
            'stock_transfer_request_id' => $request->id,
        ]);
    }

    public function test_approver_can_reject_pending_request(): void
    {
        $request = StockTransferRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_from_id' => $this->branch->id,
            'branch_to_id' => $this->branchTo->id,
            'direction' => 'out',
            'branch_product_id' => $this->branchProduct->id,
            'quantity_requested' => 20,
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/stock-transfer-requests/{$request->id}/reject", [
                'current_business_id' => $this->business->id,
                'reason' => 'Stock reserved for another branch',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('stock_transfer_requests', [
            'id' => $request->id,
            'status' => 'rejected',
            'review_notes' => 'Stock reserved for another branch',
        ]);
    }

    public function test_requester_with_approve_permission_can_approve_own_request(): void
    {
        $bothRole = Role::create([
            'name' => 'Both Permissions',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $bothRole->givePermissionTo(['request stock transfer', 'approve stock transfer']);

        DB::table('model_has_roles')->insert([
            'role_id' => $bothRole->id,
            'model_type' => User::class,
            'model_id' => $this->requester->id,
            'business_id' => $this->business->id,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $request = StockTransferRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_from_id' => $this->branch->id,
            'branch_to_id' => $this->branchTo->id,
            'direction' => 'out',
            'branch_product_id' => $this->branchProduct->id,
            'quantity_requested' => 20,
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->requester, 'sanctum')
            ->postJson("/api/stock-transfer-requests/{$request->id}/approve", [
                'current_business_id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_requester_can_confirm_approved_request_legacy_single_branch(): void
    {
        $request = StockTransferRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_from_id' => $this->branch->id,
            'branch_to_id' => null,
            'direction' => 'out',
            'branch_product_id' => $this->branchProduct->id,
            'quantity_requested' => 20,
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
            'status' => 'approved',
            'reviewed_by' => $this->approver->id,
            'reviewed_at' => now(),
        ]);

        $initialShelfQty = $this->branchProduct->shelf_quantity;
        $initialStoreQty = $this->branchProduct->store_quantity;

        $response = $this->actingAs($this->requester, 'sanctum')
            ->postJson("/api/stock-transfer-requests/{$request->id}/confirm", [
                'current_business_id' => $this->business->id,
                'notes' => 'Transfer completed',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.quantity_transferred', 20);

        $this->branchProduct->refresh();
        $this->assertEquals($initialShelfQty + 20, $this->branchProduct->shelf_quantity);
        $this->assertEquals($initialStoreQty - 20, $this->branchProduct->store_quantity);
    }

    public function test_requester_can_confirm_with_different_quantity(): void
    {
        $request = StockTransferRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_from_id' => $this->branch->id,
            'branch_to_id' => null,
            'direction' => 'out',
            'branch_product_id' => $this->branchProduct->id,
            'quantity_requested' => 20,
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
            'status' => 'approved',
            'reviewed_by' => $this->approver->id,
            'reviewed_at' => now(),
        ]);

        $response = $this->actingAs($this->requester, 'sanctum')
            ->postJson("/api/stock-transfer-requests/{$request->id}/confirm", [
                'current_business_id' => $this->business->id,
                'actual_quantity' => 15, // Different from requested
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.quantity_transferred', 15);
    }

    public function test_requester_can_cancel_pending_request(): void
    {
        $request = StockTransferRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_product_id' => $this->branchProduct->id,
            'quantity_requested' => 20,
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->requester, 'sanctum')
            ->postJson("/api/stock-transfer-requests/{$request->id}/cancel", [
                'current_business_id' => $this->business->id,
                'reason' => 'No longer needed',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cannot_transition_to_invalid_state(): void
    {
        $request = StockTransferRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_from_id' => $this->branch->id,
            'branch_to_id' => $this->branchTo->id,
            'direction' => 'out',
            'branch_product_id' => $this->branchProduct->id,
            'quantity_requested' => 20,
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
            'status' => 'confirmed',
        ]);

        $response = $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/stock-transfer-requests/{$request->id}/approve", [
                'current_business_id' => $this->business->id,
            ]);

        $response->assertStatus(422);
    }

    public function test_can_list_requests_with_filters(): void
    {
        StockTransferRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_from_id' => $this->branch->id,
            'branch_to_id' => $this->branchTo->id,
            'direction' => 'out',
            'branch_product_id' => $this->branchProduct->id,
            'quantity_requested' => 20,
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
            'status' => 'pending',
        ]);

        StockTransferRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_from_id' => $this->branch->id,
            'branch_to_id' => $this->branchTo->id,
            'direction' => 'out',
            'branch_product_id' => $this->branchProduct->id,
            'quantity_requested' => 30,
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
            'status' => 'approved',
            'reviewed_by' => $this->approver->id,
            'reviewed_at' => now(),
        ]);

        $response = $this->actingAs($this->requester, 'sanctum')
            ->getJson('/api/stock-transfer-requests?current_business_id='.$this->business->id.'&status=pending');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_request_number_is_auto_generated(): void
    {
        $request = StockTransferRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_product_id' => $this->branchProduct->id,
            'quantity_requested' => 20,
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
            'status' => 'pending',
        ]);

        $this->assertNotNull($request->request_number);
        $this->assertStringStartsWith('STR-', $request->request_number);
    }

    public function test_version_control_prevents_concurrent_modifications(): void
    {
        $request = StockTransferRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_from_id' => $this->branch->id,
            'branch_to_id' => null,
            'direction' => 'out',
            'branch_product_id' => $this->branchProduct->id,
            'quantity_requested' => 20,
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
            'status' => 'pending',
            'version' => 1,
        ]);

        // Simulate concurrent modification
        DB::table('stock_transfer_requests')
            ->where('id', $request->id)
            ->update(['version' => 2]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Request was modified by another user');

        $request->approve($this->approver, 'Approved');
    }

    public function test_audit_trail_is_maintained(): void
    {
        $request = StockTransferRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_from_id' => $this->branch->id,
            'branch_to_id' => null,
            'direction' => 'out',
            'branch_product_id' => $this->branchProduct->id,
            'quantity_requested' => 20,
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
            'status' => 'pending',
        ]);

        $request->approve($this->approver, 'Looks good');

        $request->refresh();
        $this->assertEquals($this->approver->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);
        $this->assertEquals('Looks good', $request->review_notes);
        $this->assertEquals('approved', $request->status);

        $request2 = StockTransferRequest::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_from_id' => $this->branch->id,
            'branch_to_id' => null,
            'direction' => 'out',
            'branch_product_id' => $this->branchProduct->id,
            'quantity_requested' => 10,
            'requested_by' => $this->requester->id,
            'requested_at' => now(),
            'status' => 'approved',
            'reviewed_by' => $this->approver->id,
            'reviewed_at' => now(),
        ]);

        $request2->confirm($this->requester, null, 'Completed');

        $request2->refresh();
        $this->assertEquals($this->requester->id, $request2->confirmed_by);
        $this->assertNotNull($request2->confirmed_at);
        $this->assertEquals('Completed', $request2->confirmation_notes);
        $this->assertEquals('confirmed', $request2->status);
    }

    public function test_unauthenticated_user_cannot_access(): void
    {
        $response = $this->getJson('/api/stock-transfer-requests?current_business_id='.$this->business->id);
        $response->assertStatus(401);
    }

    public function test_requires_business_context(): void
    {
        $response = $this->actingAs($this->requester, 'sanctum')
            ->getJson('/api/stock-transfer-requests');

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Business context is required');
    }
}
