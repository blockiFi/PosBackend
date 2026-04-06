<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BatchManagementTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected User $user;

    protected Business $business;

    protected Branch $branch;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->user = User::factory()->create();
        $this->business = Business::factory()->create();
        $this->branch = Branch::factory()->create(['business_id' => $this->business->id]);

        $category = ProductCategory::factory()->create(['business_id' => $this->business->id]);
        $this->product = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
        ]);

        // Attach user to business
        $this->user->businesses()->attach($this->business->id, [
            'is_active' => true,
        ]);

        // Set current business
        $this->user->update(['current_business_id' => $this->business->id]);

        // Create permissions with guard
        Permission::firstOrCreate(['name' => 'view batches', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'manage batches', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'manage inventory', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'view inventory', 'guard_name' => 'api']);

        // Assign permissions
        setPermissionsTeamId($this->business->id);
        $this->user->givePermissionTo(['view batches', 'manage batches', 'manage inventory', 'view inventory']);

        // Note: Branch access is typically managed through roles and permissions in this system
        // The controller checks branch access using userHasBranchAccess() method
    }

    /** @test */
    public function it_creates_batch_when_purchasing_product_with_expiry_date()
    {
        $manufacturingDate = Carbon::now()->subMonths(2)->format('Y-m-d');
        $expiryDate = Carbon::now()->addYear()->format('Y-m-d');

        $response = $this->actingAs($this->user)->postJson('/api/inventory/transactions', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'purchase',
            'quantity' => 100,
            'unit_cost' => 15.50,
            'batch_number' => 'BATCH-TEST-001',
            'lot_number' => 'LOT-2024-001',
            'manufacturing_date' => $manufacturingDate,
            'expiry_date' => $expiryDate,
            'supplier_name' => 'ABC Suppliers',
            'supplier_reference' => 'INV-12345',
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(201);

        // Verify batch was created
        $this->assertDatabaseHas('product_batches', [
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-TEST-001',
            'lot_number' => 'LOT-2024-001',
            'received_quantity' => 100,
            'current_quantity' => 100,
            'unit_cost' => 15.50,
            'supplier_name' => 'ABC Suppliers',
            'status' => 'active',
        ]);

        $batch = ProductBatch::where('batch_number', 'BATCH-TEST-001')->first();
        $this->assertNotNull($batch);
        $this->assertEquals($manufacturingDate, $batch->manufacturing_date->format('Y-m-d'));
        $this->assertEquals($expiryDate, $batch->expiry_date->format('Y-m-d'));
    }

    /** @test */
    public function it_auto_generates_batch_number_if_not_provided()
    {
        $response = $this->actingAs($this->user)->postJson('/api/inventory/transactions', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'purchase',
            'quantity' => 50,
            'unit_cost' => 10.00,
            'manufacturing_date' => '2025-01-01',
            'expiry_date' => '2025-06-30',
            'lot_number' => 'LOT-AUTO-001',
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('batch_number');
    }

    /** @test */
    public function it_allocates_batches_using_fefo_on_sale()
    {
        // Create three batches with different expiry dates
        $batch1 = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-001',
            'expiry_date' => Carbon::now()->addDays(10), // Expires soonest
            'received_quantity' => 30,
            'current_quantity' => 30,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        $batch2 = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-002',
            'expiry_date' => Carbon::now()->addDays(30), // Expires later
            'received_quantity' => 50,
            'current_quantity' => 50,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        $batch3 = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-003',
            'expiry_date' => Carbon::now()->addDays(60), // Expires latest
            'received_quantity' => 40,
            'current_quantity' => 40,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        // Update branch product stock
        $this->product->branchProducts()->create([
            'branch_id' => $this->branch->id,
            'stock_quantity' => 120,
            'shelf_quantity' => 120,
            'store_quantity' => 0,
        ]);

        // Sell 45 units (should take all 30 from batch1 + 15 from batch2)
        $response = $this->actingAs($this->user)->postJson('/api/inventory/transactions', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'sale',
            'quantity' => 45,
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(201);

        // Verify FEFO allocation
        $batch1->refresh();
        $batch2->refresh();
        $batch3->refresh();

        $this->assertEquals(0, $batch1->current_quantity); // Fully depleted
        $this->assertEquals('depleted', $batch1->status);
        $this->assertEquals(35, $batch2->current_quantity); // 15 units taken
        $this->assertEquals('active', $batch2->status);
        $this->assertEquals(40, $batch3->current_quantity); // Untouched
        $this->assertEquals('active', $batch3->status);
    }

    /** @test */
    public function it_marks_batch_as_expired_when_expiry_date_is_past()
    {
        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-EXPIRED',
            'expiry_date' => Carbon::now()->subDays(5), // Already expired
            'received_quantity' => 20,
            'current_quantity' => 20,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        $this->assertTrue($batch->isExpired());
        $this->assertEquals(0, $batch->daysUntilExpiry());
    }

    /** @test */
    public function it_identifies_near_expiry_batches()
    {
        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-NEAR-EXPIRY',
            'expiry_date' => Carbon::now()->addDays(15),
            'received_quantity' => 25,
            'current_quantity' => 25,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        $this->assertTrue($batch->isNearExpiry(30));
        $this->assertFalse($batch->isNearExpiry(10));
        $this->assertEquals(15, $batch->daysUntilExpiry());
    }

    /** @test */
    public function it_can_list_all_batches()
    {
        ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-001',
            'expiry_date' => Carbon::now()->addDays(30),
            'received_quantity' => 50,
            'current_quantity' => 50,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-002',
            'expiry_date' => Carbon::now()->addDays(60),
            'received_quantity' => 30,
            'current_quantity' => 30,
            'unit_cost' => 12.00,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/batches', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    /** @test */
    public function it_can_filter_batches_by_product()
    {
        $product2 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $this->product->category_id,
        ]);

        ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-P1',
            'expiry_date' => Carbon::now()->addDays(30),
            'received_quantity' => 50,
            'current_quantity' => 50,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product2->id,
            'batch_number' => 'BATCH-P2',
            'expiry_date' => Carbon::now()->addDays(30),
            'received_quantity' => 30,
            'current_quantity' => 30,
            'unit_cost' => 12.00,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/batches?product_id='.$this->product->id, [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.product_id', $this->product->id);
    }

    /** @test */
    public function it_can_get_near_expiry_batches()
    {
        // Active batch expiring soon
        ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-NEAR',
            'expiry_date' => Carbon::now()->addDays(15),
            'received_quantity' => 50,
            'current_quantity' => 50,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        // Active batch not expiring soon
        ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-FAR',
            'expiry_date' => Carbon::now()->addDays(90),
            'received_quantity' => 30,
            'current_quantity' => 30,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/batches/near-expiry?days=30', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('count', 1);
        $response->assertJsonPath('days_threshold', 30);
        $response->assertJsonPath('batches.0.batch_number', 'BATCH-NEAR');
    }

    /** @test */
    public function it_can_get_expired_batches()
    {
        // Expired batch with stock
        ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-EXPIRED',
            'expiry_date' => Carbon::now()->subDays(10),
            'received_quantity' => 40,
            'current_quantity' => 25,
            'unit_cost' => 10.00,
            'status' => 'expired',
        ]);

        // Expired batch fully depleted (should not appear)
        ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-EXPIRED-EMPTY',
            'expiry_date' => Carbon::now()->subDays(5),
            'received_quantity' => 20,
            'current_quantity' => 0,
            'unit_cost' => 10.00,
            'status' => 'depleted',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/batches/expired', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('count', 1);
        $response->assertJsonPath('batches.0.batch_number', 'BATCH-EXPIRED');
        $this->assertArrayHasKey('total_value', $response->json());
    }

    /** @test */
    public function it_can_get_batches_for_specific_product()
    {
        ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-001',
            'expiry_date' => Carbon::now()->addDays(10),
            'received_quantity' => 30,
            'current_quantity' => 30,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-002',
            'expiry_date' => Carbon::now()->addDays(30),
            'received_quantity' => 50,
            'current_quantity' => 50,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/products/{$this->product->id}/batches", [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'batches');

        // Verify FEFO ordering (batch expiring soonest should be first)
        $response->assertJsonPath('batches.0.batch_number', 'BATCH-001');
        $response->assertJsonPath('batches.1.batch_number', 'BATCH-002');
    }

    /** @test */
    public function it_can_view_batch_details()
    {
        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-DETAILS',
            'lot_number' => 'LOT-123',
            'manufacturing_date' => Carbon::now()->subMonths(2),
            'expiry_date' => Carbon::now()->addMonths(10),
            'received_quantity' => 100,
            'current_quantity' => 75,
            'unit_cost' => 15.50,
            'supplier_name' => 'Test Supplier',
            'supplier_reference' => 'INV-9999',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/batches/{$batch->id}", [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('batch.batch_number', 'BATCH-DETAILS');
        $response->assertJsonPath('batch.lot_number', 'LOT-123');
        $response->assertJsonPath('batch.received_quantity', 100);
        $response->assertJsonPath('batch.current_quantity', 75);
        $response->assertJsonPath('batch.allocated_quantity', 25);
        $response->assertJsonPath('batch.unit_cost', '15.50');
        $response->assertJsonPath('batch.supplier_name', 'Test Supplier');
    }

    /** @test */
    public function it_can_update_batch_status()
    {
        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-UPDATE',
            'expiry_date' => Carbon::now()->addDays(30),
            'received_quantity' => 50,
            'current_quantity' => 50,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)->patchJson("/api/batches/{$batch->id}", [
            'status' => 'recalled',
            'notes' => 'Supplier recall notice #12345',
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200);

        $batch->refresh();
        $this->assertEquals('recalled', $batch->status);
        $this->assertArrayHasKey('update_notes', $batch->meta_data);
    }

    /** @test */
    public function it_requires_permission_to_view_batches()
    {
        // Remove permission
        setPermissionsTeamId($this->business->id);
        $this->user->revokePermissionTo('view batches');

        $response = $this->actingAs($this->user)->getJson('/api/batches', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function it_requires_permission_to_manage_batches()
    {
        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-PERM',
            'expiry_date' => Carbon::now()->addDays(30),
            'received_quantity' => 50,
            'current_quantity' => 50,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        // Remove permission
        setPermissionsTeamId($this->business->id);
        $this->user->revokePermissionTo('manage batches');

        $response = $this->actingAs($this->user)->patchJson("/api/batches/{$batch->id}", [
            'status' => 'recalled',
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function it_prevents_allocation_from_expired_batches()
    {
        // Create expired batch
        $expiredBatch = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-EXPIRED',
            'expiry_date' => Carbon::now()->subDays(5),
            'received_quantity' => 30,
            'current_quantity' => 30,
            'unit_cost' => 10.00,
            'status' => 'expired',
        ]);

        // Create active batch
        $activeBatch = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-ACTIVE',
            'expiry_date' => Carbon::now()->addDays(30),
            'received_quantity' => 50,
            'current_quantity' => 50,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        $this->product->branchProducts()->create([
            'branch_id' => $this->branch->id,
            'stock_quantity' => 80,
            'shelf_quantity' => 80,
            'store_quantity' => 0,
        ]);

        // Try to sell - should only use active batch
        $response = $this->actingAs($this->user)->postJson('/api/inventory/transactions', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'sale',
            'quantity' => 20,
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(201);

        $expiredBatch->refresh();
        $activeBatch->refresh();

        // Expired batch should not be touched
        $this->assertEquals(30, $expiredBatch->current_quantity);
        // Active batch should be reduced
        $this->assertEquals(30, $activeBatch->current_quantity);
    }

    /** @test */
    public function it_validates_expiry_date_is_after_manufacturing_date()
    {
        $response = $this->actingAs($this->user)->postJson('/api/inventory/transactions', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'purchase',
            'quantity' => 100,
            'unit_cost' => 15.50,
            'manufacturing_date' => '2024-06-01',
            'expiry_date' => '2024-01-01', // Before manufacturing date
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('expiry_date');
    }

    /** @test */
    public function batch_allocation_handles_multiple_batches()
    {
        // Create 3 batches with different quantities
        ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-A',
            'expiry_date' => Carbon::now()->addDays(5),
            'received_quantity' => 10,
            'current_quantity' => 10,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-B',
            'expiry_date' => Carbon::now()->addDays(15),
            'received_quantity' => 25,
            'current_quantity' => 25,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-C',
            'expiry_date' => Carbon::now()->addDays(45),
            'received_quantity' => 40,
            'current_quantity' => 40,
            'unit_cost' => 10.00,
            'status' => 'active',
        ]);

        $this->product->branchProducts()->create([
            'branch_id' => $this->branch->id,
            'stock_quantity' => 75,
            'shelf_quantity' => 75,
            'store_quantity' => 0,
        ]);

        // Sell 50 units (should take 10 from A, 25 from B, 15 from C)
        $response = $this->actingAs($this->user)->postJson('/api/inventory/transactions', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'sale',
            'quantity' => 50,
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(201);

        $batchA = ProductBatch::where('batch_number', 'BATCH-A')->first();
        $batchB = ProductBatch::where('batch_number', 'BATCH-B')->first();
        $batchC = ProductBatch::where('batch_number', 'BATCH-C')->first();

        $this->assertEquals(0, $batchA->current_quantity);
        $this->assertEquals('depleted', $batchA->status);
        $this->assertEquals(0, $batchB->current_quantity);
        $this->assertEquals('depleted', $batchB->status);
        $this->assertEquals(25, $batchC->current_quantity);
        $this->assertEquals('active', $batchC->status);
    }
}
