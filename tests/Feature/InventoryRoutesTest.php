<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected $business;

    protected $branch;

    protected $product;

    protected $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->business = Business::create([
            'uuid' => \Str::uuid(),
            'owner_id' => $this->user->id,
            'name' => 'Test Business',
            'email' => 'test@business.com',
        ]);

        $this->user->businesses()->attach($this->business->id, ['is_active' => true]);

        $this->branch = Branch::create([
            'business_id' => $this->business->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'address' => '123 Main St',
        ]);

        $this->product = Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'base_selling_price' => 99.99,
        ]);

        // Create permissions
        $permissions = [
            'view inventory',
            'manage inventory',
            'adjust inventory',
        ];

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate(
                ['name' => $permissionName, 'guard_name' => 'api']
            );
        }

        $this->role = Role::create([
            'name' => 'Test Role',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);

        setPermissionsTeamId($this->business->id);
        $this->user->assignRole($this->role);
    }

    public function test_can_list_inventory_transactions_with_permission(): void
    {
        $this->role->givePermissionTo('view inventory');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        InventoryTransaction::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'user_id' => $this->user->id,
            'type' => 'purchase',
            'quantity' => 100,
            'quantity_before' => 0,
            'quantity_after' => 100,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/inventory/transactions?current_business_id='.$this->business->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'type',
                        'quantity',
                        'product_name',
                        'branch_name',
                    ],
                ],
                'meta',
            ]);
    }

    public function test_cannot_list_inventory_transactions_without_permission(): void
    {
        $unprivilegedUser = User::factory()->create();
        $unprivilegedUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $response = $this->actingAs($unprivilegedUser, 'sanctum')
            ->getJson('/api/inventory/transactions?current_business_id='.$this->business->id);

        $response->assertStatus(403);
    }

    public function test_can_create_inventory_transaction_with_permission(): void
    {
        $this->role->givePermissionTo('manage inventory');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $data = [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'purchase',
            'quantity' => 50,
            'unit_cost' => 10.50,
            'batch_number' => 'BATCH-TEST-INV-001',
            'manufacturing_date' => '2024-01-01',
            'expiry_date' => '2025-01-01',
            'reference_number' => 'PO-001',
            'notes' => 'Initial stock',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/inventory/transactions?current_business_id='.$this->business->id, $data);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Inventory transaction created successfully',
            ]);

        $this->assertEquals('purchase', $response->json('data.transaction.type'));
        $this->assertEquals(50, $response->json('data.transaction.quantity'));

        $this->assertDatabaseHas('inventory_transactions', [
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'purchase',
            'quantity' => 50,
        ]);

        // Verify stock updated
        $this->assertDatabaseHas('branch_products', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'stock_quantity' => 50,
        ]);
    }

    public function test_can_create_fractional_purchase_transaction(): void
    {
        $this->enableDecimalQuantities($this->business);
        $this->role->givePermissionTo('manage inventory');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        BranchProduct::where('branch_id', $this->branch->id)
            ->where('product_id', $this->product->id)
            ->update(['stock_quantity' => 0, 'shelf_quantity' => 0, 'store_quantity' => 0]);

        $data = [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'purchase',
            'quantity' => 10.5,
            'unit_cost' => 10.50,
            'batch_number' => 'BATCH-FRAC-001',
            'manufacturing_date' => '2024-01-01',
            'expiry_date' => '2025-01-01',
            'reference_number' => 'PO-FRAC',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/inventory/transactions?current_business_id='.$this->business->id, $data);

        $response->assertStatus(201);
        $this->assertEqualsWithDelta(10.5, (float) $response->json('data.transaction.quantity'), 0.001);

        $this->assertDatabaseHas('branch_products', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'stock_quantity' => 10.5,
        ]);

        $batch = ProductBatch::where('product_id', $this->product->id)->first();
        $this->assertNotNull($batch);
        $this->assertEqualsWithDelta(10.5, (float) $batch->current_quantity, 0.001);
    }

    public function test_rejects_fractional_purchase_when_decimal_quantities_disabled(): void
    {
        $this->role->givePermissionTo('manage inventory');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $data = [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'purchase',
            'quantity' => 10.5,
            'unit_cost' => 10.50,
            'batch_number' => 'BATCH-INT-001',
            'manufacturing_date' => '2024-01-01',
            'expiry_date' => '2025-01-01',
            'reference_number' => 'PO-INT',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/inventory/transactions?current_business_id='.$this->business->id, $data);

        $response->assertStatus(422);

        $data['quantity'] = 10;
        $data['batch_number'] = 'BATCH-INT-002';

        $integerResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/inventory/transactions?current_business_id='.$this->business->id, $data);

        $integerResponse->assertStatus(201);
    }

    public function test_cannot_create_purchase_without_batch_tracking_fields(): void
    {
        $this->role->givePermissionTo('manage inventory');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $data = [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'purchase',
            'quantity' => 50,
            'unit_cost' => 10.50,
            'reference_number' => 'PO-002',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/inventory/transactions?current_business_id='.$this->business->id, $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['batch_number', 'manufacturing_date', 'expiry_date']);
    }

    public function test_cannot_create_inventory_transaction_without_permission(): void
    {
        $unprivilegedUser = User::factory()->create();
        $unprivilegedUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $data = [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'purchase',
            'quantity' => 50,
        ];

        $response = $this->actingAs($unprivilegedUser, 'sanctum')
            ->postJson('/api/inventory/transactions?current_business_id='.$this->business->id, $data);

        $response->assertStatus(403);
    }

    public function test_stock_out_transaction_decreases_stock(): void
    {
        $this->role->givePermissionTo('manage inventory');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        // Set initial stock
        BranchProduct::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'shelf_quantity' => 100,
            'store_quantity' => 0,
            'stock_quantity' => 100,
        ]);

        $data = [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'sale',
            'quantity' => 25,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/inventory/transactions?current_business_id='.$this->business->id, $data);

        $response->assertStatus(201);

        // Verify stock decreased
        $this->assertDatabaseHas('branch_products', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'stock_quantity' => 75,
        ]);
    }

    public function test_cannot_sell_more_than_available_stock(): void
    {
        $this->role->givePermissionTo('manage inventory');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        // Set initial stock
        BranchProduct::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'stock_quantity' => 10,
        ]);

        $data = [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'sale',
            'quantity' => 25,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/inventory/transactions?current_business_id='.$this->business->id, $data);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Insufficient stock. Current stock: 10.000']);
    }

    public function test_can_create_transfer_between_branches(): void
    {
        $this->role->givePermissionTo('manage inventory');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        // Create second branch
        $secondBranch = Branch::create([
            'business_id' => $this->business->id,
            'name' => 'Second Branch',
            'code' => 'SECOND',
            'address' => '456 Second St',
        ]);

        // Set initial stock in main branch
        BranchProduct::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'shelf_quantity' => 100,
            'store_quantity' => 0,
            'stock_quantity' => 100,
        ]);

        $data = [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'transfer_out',
            'quantity' => 30,
            'related_branch_id' => $secondBranch->id,
            'reference_number' => 'TR-001',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/inventory/transactions?current_business_id='.$this->business->id, $data);

        $response->assertStatus(201);

        // Verify stock decreased in main branch
        $this->assertDatabaseHas('branch_products', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'stock_quantity' => 70,
        ]);

        // Verify stock increased in second branch
        $this->assertDatabaseHas('branch_products', [
            'branch_id' => $secondBranch->id,
            'product_id' => $this->product->id,
            'stock_quantity' => 30,
        ]);

        // Verify both transactions created
        $this->assertEquals(2, InventoryTransaction::count());
    }

    public function test_can_view_transaction_with_permission(): void
    {
        $this->role->givePermissionTo('view inventory');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $transaction = InventoryTransaction::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'user_id' => $this->user->id,
            'type' => 'purchase',
            'quantity' => 100,
            'quantity_before' => 0,
            'quantity_after' => 100,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/inventory/transactions/'.$transaction->id.'?current_business_id='.$this->business->id);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $transaction->id,
                    'type' => 'purchase',
                ],
            ]);
    }

    public function test_can_get_stock_summary(): void
    {
        $this->role->givePermissionTo('view inventory');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        BranchProduct::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'stock_quantity' => 150,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/inventory/stock-summary?current_business_id='.$this->business->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'product_id',
                        'product_name',
                        'branch_id',
                        'branch_name',
                        'stock_quantity',
                    ],
                ],
            ]);
    }

    public function test_can_filter_transactions_by_branch(): void
    {
        $this->role->givePermissionTo('view inventory');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        InventoryTransaction::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'user_id' => $this->user->id,
            'type' => 'purchase',
            'quantity' => 100,
            'quantity_before' => 0,
            'quantity_after' => 100,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/inventory/transactions?branch_id='.$this->branch->id.'&current_business_id='.$this->business->id);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_cannot_access_other_business_inventory(): void
    {
        $this->role->givePermissionTo('view inventory');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        // Create second business
        $otherBusiness = Business::create([
            'uuid' => \Str::uuid(),
            'owner_id' => $this->user->id,
            'name' => 'Other Business',
            'email' => 'other@business.com',
        ]);

        $otherBranch = Branch::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Branch',
            'code' => 'OTHER',
            'address' => '789 Other St',
        ]);

        $otherProduct = Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $otherBusiness->id,
            'name' => 'Other Product',
            'sku' => 'OTHER-001',
            'base_selling_price' => 49.99,
        ]);

        $transaction = InventoryTransaction::create([
            'uuid' => \Str::uuid(),
            'business_id' => $otherBusiness->id,
            'branch_id' => $otherBranch->id,
            'product_id' => $otherProduct->id,
            'user_id' => $this->user->id,
            'type' => 'purchase',
            'quantity' => 100,
            'quantity_before' => 0,
            'quantity_after' => 100,
        ]);

        // Try to access with wrong business context
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/inventory/transactions/'.$transaction->id.'?current_business_id='.$this->business->id);

        $response->assertStatus(404);
    }

    public function test_branch_manager_cannot_access_other_branch_inventory(): void
    {
        $branchUser = User::factory()->create();
        $branchUser->businesses()->attach($this->business->id, ['is_active' => true]);

        // Create second branch
        $otherBranch = Branch::create([
            'business_id' => $this->business->id,
            'name' => 'Other Branch',
            'code' => 'OTHER',
            'address' => '456 Other St',
        ]);

        // Create branch-specific role
        $branchRole = Role::create([
            'name' => 'Branch Manager',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $branchRole->givePermissionTo('view inventory');

        \DB::table('model_has_roles')->insert([
            'role_id' => $branchRole->id,
            'model_type' => User::class,
            'model_id' => $branchUser->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        // Try to filter by other branch (should fail)
        $response = $this->actingAs($branchUser, 'sanctum')
            ->getJson('/api/inventory/transactions?branch_id='.$otherBranch->id.'&current_business_id='.$this->business->id);

        $response->assertStatus(403)
            ->assertJson(['message' => 'You do not have access to this branch']);
    }

    public function test_validates_required_fields(): void
    {
        $this->role->givePermissionTo('manage inventory');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/inventory/transactions?current_business_id='.$this->business->id, []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id', 'product_id', 'type', 'quantity']);
    }

    public function test_requires_business_context(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/inventory/transactions');

        $response->assertStatus(400)
            ->assertJson(['message' => 'Business context is required']);
    }

    public function test_user_with_adjust_inventory_can_create_adjustment(): void
    {
        $adjustUser = User::factory()->create();
        $adjustUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $adjustRole = Role::create([
            'name' => 'Adjuster',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $adjustRole->givePermissionTo('adjust inventory');

        setPermissionsTeamId($this->business->id);
        $adjustUser->assignRole($adjustRole);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        BranchProduct::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'stock_quantity' => 100,
            'shelf_quantity' => 100,
            'store_quantity' => 0,
        ]);

        $data = [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'adjustment',
            'quantity' => 5,
            'notes' => 'Stock count correction',
        ];

        $response = $this->actingAs($adjustUser, 'sanctum')
            ->postJson('/api/inventory/transactions?current_business_id='.$this->business->id, $data);

        $response->assertStatus(201)
            ->assertJson(['message' => 'Inventory transaction created successfully']);

        $this->assertEquals('adjustment', $response->json('data.transaction.type'));
        $this->assertEquals(5, $response->json('data.transaction.quantity'));

        $this->assertDatabaseHas('inventory_transactions', [
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'adjustment',
            'quantity' => 5,
        ]);

        $this->assertDatabaseHas('branch_products', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'stock_quantity' => 105,
        ]);
    }

    public function test_user_with_adjust_inventory_cannot_create_purchase(): void
    {
        $adjustUser = User::factory()->create();
        $adjustUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $adjustRole = Role::create([
            'name' => 'Adjuster Only',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $adjustRole->givePermissionTo('adjust inventory');
        setPermissionsTeamId($this->business->id);
        $adjustUser->assignRole($adjustRole);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $data = [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'purchase',
            'quantity' => 50,
            'unit_cost' => 10.50,
        ];

        $response = $this->actingAs($adjustUser, 'sanctum')
            ->postJson('/api/inventory/transactions?current_business_id='.$this->business->id, $data);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Unauthorized']);

        $this->assertDatabaseMissing('inventory_transactions', [
            'business_id' => $this->business->id,
            'type' => 'purchase',
        ]);
    }

    public function test_user_with_adjust_inventory_cannot_create_sale(): void
    {
        $adjustUser = User::factory()->create();
        $adjustUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $adjustRole = Role::create([
            'name' => 'Sale Adj Role',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $adjustRole->givePermissionTo('adjust inventory');
        setPermissionsTeamId($this->business->id);
        $adjustUser->assignRole($adjustRole);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        BranchProduct::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'stock_quantity' => 100,
        ]);

        $data = [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'sale',
            'quantity' => 25,
        ];

        $response = $this->actingAs($adjustUser, 'sanctum')
            ->postJson('/api/inventory/transactions?current_business_id='.$this->business->id, $data);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Unauthorized']);
    }

    public function test_user_with_adjust_inventory_cannot_create_transfer(): void
    {
        $adjustUser = User::factory()->create();
        $adjustUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $adjustRole = Role::create([
            'name' => 'Transfer Adj Role',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $adjustRole->givePermissionTo('adjust inventory');
        setPermissionsTeamId($this->business->id);
        $adjustUser->assignRole($adjustRole);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $secondBranch = Branch::create([
            'business_id' => $this->business->id,
            'name' => 'Second Branch',
            'code' => 'SECOND',
            'address' => '456 Second St',
        ]);

        BranchProduct::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'stock_quantity' => 100,
        ]);

        $data = [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'transfer_out',
            'quantity' => 30,
            'related_branch_id' => $secondBranch->id,
        ];

        $response = $this->actingAs($adjustUser, 'sanctum')
            ->postJson('/api/inventory/transactions?current_business_id='.$this->business->id, $data);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Unauthorized']);
    }

    public function test_user_with_manage_inventory_can_create_adjustment(): void
    {
        $this->role->givePermissionTo('manage inventory');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        BranchProduct::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'stock_quantity' => 100,
            'shelf_quantity' => 100,
            'store_quantity' => 0,
        ]);

        $data = [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'adjustment',
            'quantity' => -5,
            'notes' => 'Damaged items removed',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/inventory/transactions?current_business_id='.$this->business->id, $data);

        $response->assertStatus(201);

        $this->assertEquals('adjustment', $response->json('data.transaction.type'));
        $this->assertEquals(-5, $response->json('data.transaction.quantity'));

        $this->assertDatabaseHas('branch_products', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'stock_quantity' => 95,
        ]);
    }

    public function test_adjustment_can_be_positive_or_negative(): void
    {
        $this->role->givePermissionTo('adjust inventory');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        // Use product without batch tracking so adjustments only affect branch stock
        $this->product->update(['stock_tracking' => 'none']);

        BranchProduct::create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'stock_quantity' => 100,
            'shelf_quantity' => 100,
            'store_quantity' => 0,
        ]);

        // Test positive adjustment
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/inventory/transactions?current_business_id='.$this->business->id, [
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'type' => 'adjustment',
                'quantity' => 10,
                'notes' => 'Found extra stock',
            ]);

        $response->assertStatus(201);

        $branchProduct = BranchProduct::where('branch_id', $this->branch->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertEquals(110, $branchProduct->stock_quantity);

        // Test negative adjustment
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/inventory/transactions?current_business_id='.$this->business->id, [
                'branch_id' => $this->branch->id,
                'product_id' => $this->product->id,
                'type' => 'adjustment',
                'quantity' => -15,
                'notes' => 'Stock count discrepancy',
            ]);

        $response->assertStatus(201);

        $branchProduct->refresh();
        $this->assertEquals(95, $branchProduct->stock_quantity);
    }
}
