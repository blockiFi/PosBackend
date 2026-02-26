<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockWriteoff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockWriteoffTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected $business;

    protected $branch;

    protected $product;

    protected $branchProduct;

    protected $writeoffRole;

    protected function setUp(): void
    {
        parent::setUp();

        // Create owner user for business
        $owner = User::factory()->create();

        // Create business
        $this->business = Business::factory()->create(['owner_id' => $owner->id]);

        // Create branch
        $this->branch = Branch::factory()->create([
            'business_id' => $this->business->id,
            'is_main' => true,
        ]);

        // Create user with write-off permission
        $this->user = User::factory()->create();
        $this->user->businesses()->attach($this->business->id, ['is_active' => true]);

        // Create permission and role
        Permission::firstOrCreate(['name' => 'write off stock', 'guard_name' => 'api']);

        $this->writeoffRole = Role::create([
            'name' => 'Stock Manager',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $this->writeoffRole->givePermissionTo('write off stock');

        // Assign role to user (business-wide) using DB insert for team-based permissions
        \Illuminate\Support\Facades\DB::table('model_has_roles')->insert([
            'role_id' => $this->writeoffRole->id,
            'model_type' => 'App\Models\User',
            'model_id' => $this->user->id,
            'business_id' => $this->business->id,
        ]);

        // Create product
        $this->product = Product::factory()->create([
            'business_id' => $this->business->id,
            'sku' => 'TEST-SKU-001',
            'barcode' => '1234567890123',
        ]);

        // Create branch product with stock
        $this->branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 100,
            'store_quantity' => 50,
            'stock_quantity' => 150,
        ]);
    }

    public function test_user_with_permission_can_write_off_stock_by_product_id(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs', [
                'current_business_id' => $this->business->id,
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'quantity' => 10,
                'source' => 'shelf',
                'reason' => 'Damaged during handling',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Stock written off successfully')
            ->assertJsonPath('data.quantity', 10)
            ->assertJsonPath('data.source', 'shelf')
            ->assertJsonPath('data.reason', 'Damaged during handling')
            ->assertJsonPath('data.sku', 'TEST-SKU-001');

        // Verify stock was reduced
        $this->branchProduct->refresh();
        $this->assertEquals(90, $this->branchProduct->shelf_quantity);
        $this->assertEquals(50, $this->branchProduct->store_quantity); // Store unchanged

        // Verify write-off record created
        $this->assertDatabaseHas('stock_writeoffs', [
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'reason' => 'Damaged during handling',
            'written_off_by' => $this->user->id,
        ]);

        // Verify inventory transaction was created
        $this->assertDatabaseHas('inventory_transactions', [
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'user_id' => $this->user->id,
            'type' => 'damage',
            'quantity' => -10,
            'shelf_quantity' => -10,
            'quantity_before' => 150,
            'shelf_quantity_before' => 100,
            'quantity_after' => 140,
            'shelf_quantity_after' => 90,
        ]);
    }

    public function test_user_can_write_off_stock_by_branch_product_id(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs', [
                'current_business_id' => $this->business->id,
                'branch_product_id' => $this->branchProduct->id,
                'quantity' => 5,
                'source' => 'shelf',
                'reason' => 'Expired product',
            ]);

        $response->assertStatus(201);

        $this->branchProduct->refresh();
        $this->assertEquals(95, $this->branchProduct->shelf_quantity);
    }

    public function test_user_can_write_off_stock_from_store(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs', [
                'current_business_id' => $this->business->id,
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'quantity' => 20,
                'source' => 'store',
                'reason' => 'Expired in warehouse',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.source', 'store')
            ->assertJsonPath('data.quantity', 20);

        $this->branchProduct->refresh();
        $this->assertEquals(100, $this->branchProduct->shelf_quantity);
        $this->assertEquals(30, $this->branchProduct->store_quantity);
    }

    public function test_user_without_permission_cannot_write_off_stock(): void
    {
        $unauthorizedUser = User::factory()->create();
        $unauthorizedUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $response = $this->actingAs($unauthorizedUser, 'sanctum')
            ->postJson('/api/stock-writeoffs', [
                'current_business_id' => $this->business->id,
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'quantity' => 10,
                'source' => 'shelf',
                'reason' => 'Damaged',
            ]);

        $response->assertStatus(403);

        // Verify stock was not reduced
        $this->branchProduct->refresh();
        $this->assertEquals(100, $this->branchProduct->shelf_quantity);
    }

    public function test_cannot_write_off_more_than_shelf_quantity(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs', [
                'current_business_id' => $this->business->id,
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'quantity' => 150, // More than available shelf_quantity (100)
                'source' => 'shelf',
                'reason' => 'Damaged',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);

        // Verify stock was not reduced
        $this->branchProduct->refresh();
        $this->assertEquals(100, $this->branchProduct->shelf_quantity);
    }

    public function test_cannot_write_off_more_than_store_quantity(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs', [
                'current_business_id' => $this->business->id,
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'quantity' => 60, // More than available store_quantity (50)
                'source' => 'store',
                'reason' => 'Damaged',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);

        $this->branchProduct->refresh();
        $this->assertEquals(50, $this->branchProduct->store_quantity);
    }

    public function test_writeoff_fails_with_invalid_product_id(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs', [
                'current_business_id' => $this->business->id,
                'branch_id' => $this->branch->id,
                'product_id' => 99999,
                'quantity' => 10,
                'source' => 'shelf',
                'reason' => 'Damaged',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product_id']);
    }

    public function test_writeoff_fails_when_product_not_in_branch(): void
    {
        $otherProduct = Product::factory()->create([
            'business_id' => $this->business->id,
            'sku' => 'OTHER-SKU',
        ]);
        // Not adding to branch_products

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs', [
                'current_business_id' => $this->business->id,
                'branch_id' => $this->branch->id,
                'product_id' => $otherProduct->id,
                'quantity' => 10,
                'source' => 'shelf',
                'reason' => 'Damaged',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product_id']);
    }

    public function test_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs', [
                'current_business_id' => $this->business->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quantity', 'source', 'reason']);
        $this->assertTrue(
            $response->json('errors.product_id') !== null || $response->json('errors.branch_product_id') !== null,
            'Expected validation error for product_id or branch_product_id'
        );
    }

    public function test_validates_quantity_is_positive(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs', [
                'current_business_id' => $this->business->id,
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'quantity' => -5,
                'source' => 'shelf',
                'reason' => 'Damaged',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);
    }

    public function test_can_list_writeoffs(): void
    {
        // Create some write-offs
        StockWriteoff::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_product_id' => $this->branchProduct->id,
            'product_id' => $this->product->id,
            'sku' => $this->product->sku,
            'quantity' => 5,
            'source' => 'shelf',
            'reason' => 'Expired',
            'written_off_by' => $this->user->id,
            'written_off_at' => now(),
        ]);

        StockWriteoff::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_product_id' => $this->branchProduct->id,
            'product_id' => $this->product->id,
            'sku' => $this->product->sku,
            'quantity' => 3,
            'source' => 'shelf',
            'reason' => 'Damaged',
            'written_off_by' => $this->user->id,
            'written_off_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/stock-writeoffs?current_business_id='.$this->business->id);

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_can_filter_writeoffs_by_branch(): void
    {
        $branch2 = Branch::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $branchProduct2 = BranchProduct::create([
            'branch_id' => $branch2->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 20,
            'store_quantity' => 10,
            'stock_quantity' => 30,
        ]);

        // Write-off in branch 1
        StockWriteoff::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_product_id' => $this->branchProduct->id,
            'product_id' => $this->product->id,
            'sku' => $this->product->sku,
            'quantity' => 5,
            'source' => 'shelf',
            'reason' => 'Branch 1',
            'written_off_by' => $this->user->id,
            'written_off_at' => now(),
        ]);

        // Write-off in branch 2
        StockWriteoff::create([
            'business_id' => $this->business->id,
            'branch_id' => $branch2->id,
            'branch_product_id' => $branchProduct2->id,
            'product_id' => $this->product->id,
            'sku' => $this->product->sku,
            'quantity' => 3,
            'source' => 'shelf',
            'reason' => 'Branch 2',
            'written_off_by' => $this->user->id,
            'written_off_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/stock-writeoffs?current_business_id='.$this->business->id.'&branch_id='.$this->branch->id);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'Branch 1');
    }

    public function test_can_filter_writeoffs_by_date_range(): void
    {
        // Old write-off
        StockWriteoff::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_product_id' => $this->branchProduct->id,
            'product_id' => $this->product->id,
            'sku' => $this->product->sku,
            'quantity' => 5,
            'source' => 'shelf',
            'reason' => 'Old',
            'written_off_by' => $this->user->id,
            'written_off_at' => now()->subDays(10),
        ]);

        // Recent write-off
        StockWriteoff::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_product_id' => $this->branchProduct->id,
            'product_id' => $this->product->id,
            'sku' => $this->product->sku,
            'quantity' => 3,
            'source' => 'shelf',
            'reason' => 'Recent',
            'written_off_by' => $this->user->id,
            'written_off_at' => now(),
        ]);

        $startDate = now()->subDays(3)->format('Y-m-d');
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/stock-writeoffs?current_business_id='.$this->business->id.'&start_date='.$startDate);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'Recent');
    }

    public function test_can_view_specific_writeoff(): void
    {
        $writeoff = StockWriteoff::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'branch_product_id' => $this->branchProduct->id,
            'product_id' => $this->product->id,
            'sku' => $this->product->sku,
            'quantity' => 5,
            'source' => 'shelf',
            'reason' => 'Test reason',
            'written_off_by' => $this->user->id,
            'written_off_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/stock-writeoffs/'.$writeoff->id.'?current_business_id='.$this->business->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $writeoff->id)
            ->assertJsonPath('data.quantity', 5)
            ->assertJsonPath('data.source', 'shelf')
            ->assertJsonPath('data.reason', 'Test reason');
    }

    public function test_user_cannot_access_writeoffs_from_other_business(): void
    {
        $owner2 = User::factory()->create();
        $otherBusiness = Business::factory()->create(['owner_id' => $owner2->id]);
        $otherBranch = Branch::factory()->create(['business_id' => $otherBusiness->id]);
        $otherProduct = Product::factory()->create(['business_id' => $otherBusiness->id]);
        $otherBranchProduct = BranchProduct::create([
            'branch_id' => $otherBranch->id,
            'product_id' => $otherProduct->id,
            'shelf_quantity' => 10,
            'store_quantity' => 10,
            'stock_quantity' => 20,
        ]);

        $otherWriteoff = StockWriteoff::create([
            'business_id' => $otherBusiness->id,
            'branch_id' => $otherBranch->id,
            'branch_product_id' => $otherBranchProduct->id,
            'product_id' => $otherProduct->id,
            'sku' => $otherProduct->sku,
            'quantity' => 5,
            'source' => 'shelf',
            'reason' => 'Other business',
            'written_off_by' => $this->user->id,
            'written_off_at' => now(),
        ]);

        // Try to list - should not see other business's write-offs
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/stock-writeoffs?current_business_id='.$this->business->id);

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_user_without_branch_access_cannot_write_off_stock(): void
    {
        // Create user with role scoped to a different branch
        $branch2 = Branch::factory()->create(['business_id' => $this->business->id]);
        $restrictedUser = User::factory()->create();
        $restrictedUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $branchRole = Role::create([
            'name' => 'Branch 2 Manager',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $branchRole->givePermissionTo('write off stock');

        // Assign role scoped to branch2
        \Illuminate\Support\Facades\DB::table('model_has_roles')->insert([
            'role_id' => $branchRole->id,
            'model_type' => 'App\Models\User',
            'model_id' => $restrictedUser->id,
            'business_id' => $this->business->id,
            'branch_id' => $branch2->id,
        ]);

        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $restrictedUser->load('roles');

        // Try to write off stock in branch 1
        $response = $this->actingAs($restrictedUser, 'sanctum')
            ->postJson('/api/stock-writeoffs', [
                'current_business_id' => $this->business->id,
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'quantity' => 10,
                'source' => 'shelf',
                'reason' => 'Damaged',
            ]);

        $response->assertStatus(403);
        // Message could be either "Unauthorized" or "You do not have access to this branch"
        // depending on which check fails first
    }

    public function test_requires_business_context(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs', [
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'quantity' => 10,
                'source' => 'shelf',
                'reason' => 'Damaged',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_business_id']);
    }

    public function test_unauthenticated_user_cannot_access(): void
    {
        $response = $this->postJson('/api/stock-writeoffs', [
            'current_business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'source' => 'shelf',
            'reason' => 'Damaged',
        ]);

        $response->assertStatus(401);
    }

    public function test_audit_trail_includes_written_off_by_and_timestamp(): void
    {
        $beforeWriteoff = now();
        sleep(1); // 1 second delay to ensure timestamp is after

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs', [
                'current_business_id' => $this->business->id,
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'quantity' => 10,
                'source' => 'shelf',
                'reason' => 'Damaged',
            ]);

        $response->assertStatus(201);

        $writeoff = StockWriteoff::latest()->first();
        $this->assertEquals($this->user->id, $writeoff->written_off_by);
        $this->assertNotNull($writeoff->written_off_at);
        $this->assertTrue($writeoff->written_off_at->greaterThanOrEqualTo($beforeWriteoff));
    }

    public function test_write_off_batch_success_depletes_batch_and_reduces_stock(): void
    {
        $this->branchProduct->update([
            'shelf_quantity' => 30,
            'store_quantity' => 120,
            'stock_quantity' => 150,
        ]);

        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-WO-001',
            'received_quantity' => 50,
            'current_quantity' => 50,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs/writeoff-batch', [
                'current_business_id' => $this->business->id,
                'batch_id' => $batch->id,
                'reason' => 'Expired batch write-off',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Batch written off successfully')
            ->assertJsonPath('data.quantity', 50)
            ->assertJsonPath('data.source', 'batch')
            ->assertJsonPath('data.reason', 'Expired batch write-off')
            ->assertJsonPath('data.batch_id', $batch->id);

        $batch->refresh();
        $this->assertEquals(0, $batch->current_quantity);
        $this->assertEquals('depleted', $batch->status);

        $this->branchProduct->refresh();
        $this->assertEquals(100, $this->branchProduct->stock_quantity);
        $this->assertEquals(30, $this->branchProduct->shelf_quantity);
        $this->assertEquals(70, $this->branchProduct->store_quantity);
        $this->assertEquals($this->branchProduct->shelf_quantity + $this->branchProduct->store_quantity, $this->branchProduct->stock_quantity);

        $this->assertDatabaseHas('stock_writeoffs', [
            'batch_id' => $batch->id,
            'product_id' => $this->product->id,
            'quantity' => 50,
            'source' => 'batch',
            'reason' => 'Expired batch write-off',
        ]);

        $this->assertDatabaseHas('inventory_transactions', [
            'batch_id' => $batch->id,
            'product_id' => $this->product->id,
            'type' => 'damage',
            'quantity' => -50,
        ]);
    }

    public function test_write_off_batch_validates_batch_id_and_reason(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs/writeoff-batch', [
                'current_business_id' => $this->business->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['batch_id', 'reason']);
    }

    public function test_write_off_batch_rejects_zero_remaining_quantity(): void
    {
        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-EMPTY',
            'received_quantity' => 20,
            'current_quantity' => 0,
            'unit_cost' => 5.00,
            'status' => 'depleted',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs/writeoff-batch', [
                'current_business_id' => $this->business->id,
                'batch_id' => $batch->id,
                'reason' => 'Some reason',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['batch_id'])
            ->assertJsonPath('errors.batch_id.0', 'Batch has no remaining quantity to write off.');
    }

    public function test_write_off_batch_rejects_insufficient_branch_stock(): void
    {
        $this->branchProduct->update([
            'shelf_quantity' => 5,
            'store_quantity' => 5,
            'stock_quantity' => 10,
        ]);

        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-BIG',
            'received_quantity' => 50,
            'current_quantity' => 50,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs/writeoff-batch', [
                'current_business_id' => $this->business->id,
                'batch_id' => $batch->id,
                'reason' => 'Reason',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['batch_id']);
        $this->assertStringContainsString('Insufficient branch stock', $response->json('errors.batch_id.0'));
    }

    public function test_write_off_batch_rejects_batch_from_other_business(): void
    {
        $otherBusiness = Business::factory()->create();
        $otherBranch = Branch::factory()->create(['business_id' => $otherBusiness->id]);
        $otherProduct = Product::factory()->create(['business_id' => $otherBusiness->id]);

        $batch = ProductBatch::create([
            'business_id' => $otherBusiness->id,
            'branch_id' => $otherBranch->id,
            'product_id' => $otherProduct->id,
            'batch_number' => 'BATCH-OTHER',
            'received_quantity' => 20,
            'current_quantity' => 20,
            'unit_cost' => 5.00,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs/writeoff-batch', [
                'current_business_id' => $this->business->id,
                'batch_id' => $batch->id,
                'reason' => 'Reason',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['batch_id']);
    }

    public function test_write_off_batch_requires_write_off_stock_permission(): void
    {
        $userNoPermission = User::factory()->create();
        $userNoPermission->businesses()->attach($this->business->id, ['is_active' => true]);

        $this->branchProduct->update(['shelf_quantity' => 50, 'store_quantity' => 50, 'stock_quantity' => 100]);
        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-PERM',
            'received_quantity' => 20,
            'current_quantity' => 20,
            'unit_cost' => 5.00,
            'status' => 'active',
        ]);

        $response = $this->actingAs($userNoPermission, 'sanctum')
            ->postJson('/api/stock-writeoffs/writeoff-batch', [
                'current_business_id' => $this->business->id,
                'batch_id' => $batch->id,
                'reason' => 'Reason',
            ]);

        $response->assertStatus(403);
    }

    public function test_can_write_off_with_batch_id_quantity_and_source(): void
    {
        $this->branchProduct->update([
            'shelf_quantity' => 30,
            'store_quantity' => 70,
            'stock_quantity' => 100,
        ]);

        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-PARTIAL',
            'received_quantity' => 20,
            'current_quantity' => 20,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs', [
                'current_business_id' => $this->business->id,
                'batch_id' => $batch->id,
                'quantity' => 5,
                'source' => 'shelf',
                'reason' => 'Partial batch write-off from shelf',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Stock written off successfully')
            ->assertJsonPath('data.batch_id', $batch->id)
            ->assertJsonPath('data.quantity', 5)
            ->assertJsonPath('data.source', 'shelf')
            ->assertJsonPath('data.reason', 'Partial batch write-off from shelf');

        $batch->refresh();
        $this->assertEquals(15, $batch->current_quantity);

        $this->branchProduct->refresh();
        $this->assertEquals(25, $this->branchProduct->shelf_quantity);
        $this->assertEquals(70, $this->branchProduct->store_quantity);
        $this->assertEquals(95, $this->branchProduct->stock_quantity);
        $this->assertEquals($this->branchProduct->shelf_quantity + $this->branchProduct->store_quantity, $this->branchProduct->stock_quantity);

        $this->assertDatabaseHas('stock_writeoffs', [
            'batch_id' => $batch->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'source' => 'shelf',
            'reason' => 'Partial batch write-off from shelf',
        ]);
    }

    public function test_write_off_with_batch_id_rejects_quantity_exceeding_batch(): void
    {
        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-SMALL',
            'received_quantity' => 5,
            'current_quantity' => 5,
            'unit_cost' => 5.00,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs', [
                'current_business_id' => $this->business->id,
                'batch_id' => $batch->id,
                'quantity' => 10,
                'source' => 'shelf',
                'reason' => 'Too much',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);
        $this->assertStringContainsString('exceed batch remaining quantity', $response->json('errors.quantity.0'));
    }

    public function test_write_off_with_batch_id_rejects_quantity_exceeding_source(): void
    {
        $this->branchProduct->update([
            'shelf_quantity' => 3,
            'store_quantity' => 50,
            'stock_quantity' => 53,
        ]);

        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-STORE',
            'received_quantity' => 20,
            'current_quantity' => 20,
            'unit_cost' => 5.00,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs', [
                'current_business_id' => $this->business->id,
                'batch_id' => $batch->id,
                'quantity' => 10,
                'source' => 'shelf',
                'reason' => 'Shelf has only 3',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);
        $this->assertStringContainsString('Insufficient stock on shelf', $response->json('errors.quantity.0'));
    }

    public function test_write_off_with_batch_id_rejects_invalid_batch(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stock-writeoffs', [
                'current_business_id' => $this->business->id,
                'batch_id' => 99999,
                'quantity' => 1,
                'source' => 'shelf',
                'reason' => 'Reason',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['batch_id']);
    }
}
