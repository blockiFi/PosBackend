<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\GoodsReceivedNote;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GoodsReceivingGrnTest extends TestCase
{
    use RefreshDatabase;

    public function test_grn_approve_posts_stock_and_creates_ledger_rows(): void
    {
        $user = User::factory()->create();
        $approver = User::factory()->create();

        $business = Business::create([
            'uuid' => \Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'Test Business',
            'email' => 'test@business.com',
        ]);

        $user->businesses()->attach($business->id, ['is_active' => true]);
        $approver->businesses()->attach($business->id, ['is_active' => true]);

        $branch = Branch::create([
            'business_id' => $business->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'address' => '123 Main St',
        ]);

        $product = Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $business->id,
            'name' => 'Batched Product',
            'sku' => 'BAT-001',
            'base_selling_price' => 99.99,
            'stock_tracking' => 'simple',
        ]);

        $bp = BranchProduct::create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'cost_price' => 10.00,
            'selling_price' => 20.00,
            'stock_quantity' => 0,
            'shelf_quantity' => 0,
            'store_quantity' => 0,
            'is_available' => true,
        ]);

        // Permissions + roles
        foreach (['view grn', 'create grn', 'approve grn', 'manage suppliers', 'sync data'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']);
        }

        $creatorRole = Role::create([
            'name' => 'CreatorRole',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        $approverRole = Role::create([
            'name' => 'ApproverRole',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        setPermissionsTeamId($business->id);
        $creatorRole->givePermissionTo('create grn');
        $creatorRole->givePermissionTo('view grn');
        $approverRole->givePermissionTo('approve grn');
        $approverRole->givePermissionTo('view grn');

        $user->assignRole($creatorRole);
        $approver->assignRole($approverRole);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $supplier = Supplier::create([
            'uuid' => \Str::uuid(),
            'business_id' => $business->id,
            'code' => 'SUP-1',
            'name' => 'Test Supplier',
            'is_active' => true,
        ]);

        // Create draft GRN
        $resp = $this->actingAs($user, 'sanctum')->postJson('/api/goods-received-notes', [
            'current_business_id' => $business->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'supplier_invoice_number' => 'INV-1',
        ]);
        $resp->assertStatus(201);
        $grnId = (int) ($resp->json('grn.id'));

        // Add line
        $resp2 = $this->actingAs($user, 'sanctum')->postJson("/api/goods-received-notes/{$grnId}/lines", [
            'current_business_id' => $business->id,
            'product_id' => $product->id,
            'branch_product_id' => $bp->id,
            'quantity_received' => 10,
            'quantity_accepted' => 10,
            'unit_cost' => 12.50,
            'batch_number' => 'BATCH-001',
            'manufacturing_date' => '2026-01-01',
            'expiry_date' => '2027-01-01',
            'storage_location' => 'store',
        ]);
        $resp2->assertStatus(200);

        // Submit
        $resp3 = $this->actingAs($user, 'sanctum')->postJson("/api/goods-received-notes/{$grnId}/submit", [
            'current_business_id' => $business->id,
        ]);
        $resp3->assertStatus(200)->assertJsonPath('grn.status', 'pending_approval');

        // Approve (auto-post)
        $resp4 = $this->actingAs($approver, 'sanctum')->postJson("/api/goods-received-notes/{$grnId}/approve", [
            'current_business_id' => $business->id,
        ]);
        $resp4->assertStatus(200)->assertJsonPath('grn.status', 'posted');

        $bp->refresh();
        $this->assertEquals(10, $bp->store_quantity);
        $this->assertEquals(10, $bp->stock_quantity);

        $tx = InventoryTransaction::where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->where('type', 'purchase')
            ->first();
        $this->assertNotNull($tx);
        $this->assertNotNull($tx->goods_received_note_line_id);

        $batch = ProductBatch::where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->first();
        $this->assertNotNull($batch);
        $this->assertNotNull($batch->goods_received_note_line_id);

        $grn = GoodsReceivedNote::with('lines')->find($grnId);
        $this->assertNotNull($grn);
        $this->assertCount(1, $grn->lines);
        $this->assertNotNull($grn->lines[0]->inventory_transaction_id);
        $this->assertNotNull($grn->lines[0]->batch_id);
    }

    public function test_grn_approve_posts_fractional_quantity(): void
    {
        $user = User::factory()->create();
        $approver = User::factory()->create();

        $business = Business::create([
            'uuid' => \Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'Test Business',
            'email' => 'test@business.com',
            'settings' => ['allow_decimal_quantities' => true],
        ]);

        $user->businesses()->attach($business->id, ['is_active' => true]);
        $approver->businesses()->attach($business->id, ['is_active' => true]);

        $branch = Branch::create([
            'business_id' => $business->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'address' => '123 Main St',
        ]);

        $product = Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $business->id,
            'name' => 'Bulk Product',
            'sku' => 'BLK-001',
            'base_selling_price' => 99.99,
            'stock_tracking' => 'simple',
        ]);

        $bp = BranchProduct::create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'cost_price' => 10.00,
            'selling_price' => 20.00,
            'stock_quantity' => 0,
            'shelf_quantity' => 0,
            'store_quantity' => 0,
            'is_available' => true,
        ]);

        foreach (['view grn', 'create grn', 'approve grn', 'manage suppliers'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']);
        }

        $creatorRole = Role::create([
            'name' => 'CreatorRoleFrac',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);
        $approverRole = Role::create([
            'name' => 'ApproverRoleFrac',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);

        setPermissionsTeamId($business->id);
        $creatorRole->givePermissionTo('create grn', 'view grn');
        $approverRole->givePermissionTo('approve grn', 'view grn');
        $user->assignRole($creatorRole);
        $approver->assignRole($approverRole);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $supplier = Supplier::create([
            'uuid' => \Str::uuid(),
            'business_id' => $business->id,
            'code' => 'SUP-F',
            'name' => 'Fractional Supplier',
            'is_active' => true,
        ]);

        $resp = $this->actingAs($user, 'sanctum')->postJson('/api/goods-received-notes', [
            'current_business_id' => $business->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
        ]);
        $resp->assertStatus(201);
        $grnId = (int) ($resp->json('grn.id'));

        $this->actingAs($user, 'sanctum')->postJson("/api/goods-received-notes/{$grnId}/lines", [
            'current_business_id' => $business->id,
            'product_id' => $product->id,
            'branch_product_id' => $bp->id,
            'quantity_received' => 10.5,
            'quantity_accepted' => 10.5,
            'unit_cost' => 12.50,
            'batch_number' => 'BATCH-FRAC',
            'manufacturing_date' => '2026-01-01',
            'expiry_date' => '2027-01-01',
            'storage_location' => 'store',
        ])->assertStatus(200);

        $this->actingAs($user, 'sanctum')->postJson("/api/goods-received-notes/{$grnId}/submit", [
            'current_business_id' => $business->id,
        ])->assertStatus(200);

        $this->actingAs($approver, 'sanctum')->postJson("/api/goods-received-notes/{$grnId}/approve", [
            'current_business_id' => $business->id,
        ])->assertStatus(200);

        $bp->refresh();
        $this->assertEqualsWithDelta(10.5, (float) $bp->store_quantity, 0.001);
        $this->assertEqualsWithDelta(10.5, (float) $bp->stock_quantity, 0.001);

        $tx = InventoryTransaction::where('product_id', $product->id)->where('type', 'purchase')->first();
        $this->assertNotNull($tx);
        $this->assertEqualsWithDelta(10.5, (float) $tx->quantity, 0.001);

        $batch = ProductBatch::where('product_id', $product->id)->first();
        $this->assertNotNull($batch);
        $this->assertEqualsWithDelta(10.5, (float) $batch->current_quantity, 0.001);
    }

    public function test_grn_create_is_idempotent_by_client_uuid(): void
    {
        $user = User::factory()->create();

        $business = Business::create([
            'uuid' => \Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'Test Business',
            'email' => 'test@business.com',
        ]);
        $user->businesses()->attach($business->id, ['is_active' => true]);

        $branch = Branch::create([
            'business_id' => $business->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'address' => '123 Main St',
        ]);

        foreach (['view grn', 'create grn', 'manage suppliers'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']);
        }

        $role = Role::create([
            'name' => 'CreatorRole',
            'guard_name' => 'api',
            'business_id' => $business->id,
        ]);
        setPermissionsTeamId($business->id);
        $role->givePermissionTo('create grn');
        $role->givePermissionTo('view grn');
        $user->assignRole($role);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $supplier = Supplier::create([
            'uuid' => \Str::uuid(),
            'business_id' => $business->id,
            'code' => 'SUP-1',
            'name' => 'Test Supplier',
            'is_active' => true,
        ]);

        $clientUuid = (string) \Str::uuid();

        $r1 = $this->actingAs($user, 'sanctum')->postJson('/api/goods-received-notes', [
            'current_business_id' => $business->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'client_uuid' => $clientUuid,
        ]);
        $r1->assertStatus(201);
        $id1 = (int) ($r1->json('grn.id'));

        $r2 = $this->actingAs($user, 'sanctum')->postJson('/api/goods-received-notes', [
            'current_business_id' => $business->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'client_uuid' => $clientUuid,
        ]);
        $r2->assertStatus(201);
        $id2 = (int) ($r2->json('grn.id'));

        $this->assertSame($id1, $id2);
    }
}
