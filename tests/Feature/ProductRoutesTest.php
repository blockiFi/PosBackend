<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    private Branch $branch;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        // Create user and business
        $this->user = User::factory()->create();
        $this->business = Business::create([
            'name' => 'Test Business',
            'email' => 'business@test.com',
            'owner_id' => $this->user->id,
        ]);

        // Create a branch
        $this->branch = Branch::create([
            'business_id' => $this->business->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'address' => '123 Main St',
        ]);

        // Attach user to business
        $this->user->businesses()->attach($this->business->id, [
            'is_active' => true,
        ]);

        // Create role with permissions
        $this->role = Role::create([
            'name' => 'Product Manager',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);

        // Create permissions
        $permissions = [
            'view products',
            'create products',
            'edit products',
            'delete products',
            'manage branch products',
        ];

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate(
                ['name' => $permissionName, 'guard_name' => 'api']
            );
        }

        // Assign role to user with business_id
        DB::table('model_has_roles')->insert([
            'role_id' => $this->role->id,
            'model_type' => User::class,
            'model_id' => $this->user->id,
            'business_id' => $this->business->id,
        ]);
    }

    public function test_can_list_products_with_permission(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/products?current_business_id='.$this->business->id);

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_cannot_list_products_without_permission(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/products?current_business_id='.$this->business->id);

        $response->assertStatus(403);
    }

    public function test_can_create_product_with_permission(): void
    {
        $this->role->givePermissionTo('create products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $data = [
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'base_selling_price' => 99.99,
            'base_cost_price' => 50.00,
            'stock_tracking' => 'simple',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/products?current_business_id='.$this->business->id, $data);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'uuid',
                    'name',
                    'sku',
                ],
            ]);

        $this->assertDatabaseHas('products', [
            'business_id' => $this->business->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
        ]);
    }

    public function test_cannot_create_product_without_permission(): void
    {
        $unprivilegedUser = User::factory()->create();
        $unprivilegedUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $data = [
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'base_selling_price' => 99.99,
        ];

        $response = $this->actingAs($unprivilegedUser, 'sanctum')
            ->postJson('/api/products?current_business_id='.$this->business->id, $data);

        $response->assertStatus(403);
    }

    public function test_can_view_product_with_permission(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $product = Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'base_selling_price' => 99.99,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/products/'.$product->id.'?current_business_id='.$this->business->id);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $product->id,
                    'name' => 'Test Product',
                ],
            ]);
    }

    public function test_can_update_product_with_permission(): void
    {
        $this->role->givePermissionTo('edit products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $product = Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'base_selling_price' => 99.99,
        ]);

        $data = [
            'name' => 'Updated Product',
            'base_selling_price' => 149.99,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/products/'.$product->id.'?current_business_id='.$this->business->id, $data);

        $response->assertStatus(200);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Updated Product',
            'base_selling_price' => 149.99,
        ]);
    }

    public function test_cannot_update_product_without_permission(): void
    {
        $unprivilegedUser = User::factory()->create();
        $unprivilegedUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $product = Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'base_selling_price' => 99.99,
        ]);

        $data = ['name' => 'Updated Name'];

        $response = $this->actingAs($unprivilegedUser, 'sanctum')
            ->putJson('/api/products/'.$product->id.'?current_business_id='.$this->business->id, $data);

        $response->assertStatus(403);
    }

    public function test_can_delete_product_with_permission(): void
    {
        $this->role->givePermissionTo('delete products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $product = Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'base_selling_price' => 99.99,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/products/'.$product->id.'?current_business_id='.$this->business->id);

        $response->assertStatus(200);

        $this->assertSoftDeleted('products', [
            'id' => $product->id,
        ]);
    }

    public function test_cannot_delete_product_without_permission(): void
    {
        $unprivilegedUser = User::factory()->create();
        $unprivilegedUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $product = Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'base_selling_price' => 99.99,
        ]);

        $response = $this->actingAs($unprivilegedUser, 'sanctum')
            ->deleteJson('/api/products/'.$product->id.'?current_business_id='.$this->business->id);

        $response->assertStatus(403);
    }

    public function test_can_add_product_to_branch(): void
    {
        $this->role->givePermissionTo('manage branch products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $product = Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'base_selling_price' => 99.99,
        ]);

        $data = [
            'branch_id' => $this->branch->id,
            'selling_price' => 109.99,
            'stock_quantity' => 50,
            'is_available' => true,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/products/'.$product->id.'/branches?current_business_id='.$this->business->id, $data);

        $response->assertStatus(200);

        $this->assertDatabaseHas('branch_products', [
            'product_id' => $product->id,
            'branch_id' => $this->branch->id,
            'selling_price' => 109.99,
            'stock_quantity' => 50,
        ]);
    }

    public function test_can_remove_product_from_branch(): void
    {
        $this->role->givePermissionTo('manage branch products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $product = Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'base_selling_price' => 99.99,
        ]);

        BranchProduct::create([
            'product_id' => $product->id,
            'branch_id' => $this->branch->id,
            'stock_quantity' => 50,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/products/'.$product->id.'/branches?branch_id='.$this->branch->id.'&current_business_id='.$this->business->id);

        $response->assertStatus(200);

        // Check that the branch product is soft deleted
        $this->assertSoftDeleted('branch_products', [
            'product_id' => $product->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_can_filter_products_by_category(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Product 1',
            'sku' => 'TEST-001',
            'base_selling_price' => 99.99,
        ]);

        Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Product 2',
            'sku' => 'TEST-002',
            'base_selling_price' => 99.99,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/products?category_id='.$category->id.'&current_business_id='.$this->business->id);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Product 1', $data[0]['name']);
    }

    public function test_can_search_products(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Laptop Computer',
            'sku' => 'LAPTOP-001',
            'base_selling_price' => 999.99,
        ]);

        Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Desktop Mouse',
            'sku' => 'MOUSE-001',
            'base_selling_price' => 29.99,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/products?search=Laptop&current_business_id='.$this->business->id);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Laptop Computer', $data[0]['name']);
    }

    public function test_product_includes_branch_data_when_branch_id_provided(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $product = Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'base_selling_price' => 99.99,
        ]);

        BranchProduct::create([
            'product_id' => $product->id,
            'branch_id' => $this->branch->id,
            'selling_price' => 109.99,
            'stock_quantity' => 50,
            'is_available' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/products/'.$product->id.'?branch_id='.$this->branch->id.'&current_business_id='.$this->business->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'branch_data' => [
                        'branch_id',
                        'selling_price',
                        'stock_quantity',
                        'is_available',
                        'is_in_stock',
                    ],
                ],
            ]);
    }

    public function test_requires_business_context(): void
    {
        $this->role->givePermissionTo('view products');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/products');

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Business context is required',
            ]);
    }

    public function test_cannot_access_other_business_products(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        // Create another business
        $otherBusiness = Business::create([
            'name' => 'Other Business',
            'email' => 'other@test.com',
            'owner_id' => User::factory()->create()->id,
        ]);

        $product = Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $otherBusiness->id,
            'name' => 'Other Product',
            'sku' => 'OTHER-001',
            'base_selling_price' => 99.99,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/products/'.$product->id.'?current_business_id='.$this->business->id);

        $response->assertStatus(404);
    }

    public function test_validates_required_fields_on_create(): void
    {
        $this->role->givePermissionTo('create products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $data = [
            // Missing required fields
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/products?current_business_id='.$this->business->id, $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'sku']);
    }

    public function test_validates_unique_sku(): void
    {
        $this->role->givePermissionTo('create products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Existing Product',
            'sku' => 'DUPLICATE-SKU',
            'base_selling_price' => 99.99,
        ]);

        $data = [
            'name' => 'New Product',
            'sku' => 'DUPLICATE-SKU',
            'base_selling_price' => 149.99,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/products?current_business_id='.$this->business->id, $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sku']);
    }

    public function test_user_cannot_add_product_to_branch_without_access(): void
    {
        $branchUser = User::factory()->create();
        $branchUser->businesses()->attach($this->business->id, ['is_active' => true]);

        // Create another branch
        $otherBranch = Branch::create([
            'business_id' => $this->business->id,
            'name' => 'Other Branch',
            'code' => 'OTHER',
            'address' => '456 Other St',
        ]);

        $product = Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'base_selling_price' => 99.99,
        ]);

        // Create a branch-specific role for the user (only for main branch)
        $branchRole = Role::create([
            'name' => 'Branch Manager',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $branchRole->givePermissionTo('manage branch products');

        DB::table('model_has_roles')->insert([
            'role_id' => $branchRole->id,
            'model_type' => User::class,
            'model_id' => $branchUser->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        // Try to add product to other branch (should fail)
        $data = [
            'branch_id' => $otherBranch->id,
            'selling_price' => 99.99,
            'stock_quantity' => 100,
        ];

        $response = $this->actingAs($branchUser, 'sanctum')
            ->postJson('/api/products/'.$product->id.'/branches?current_business_id='.$this->business->id, $data);

        $response->assertStatus(403)
            ->assertJson(['message' => 'You do not have access to this branch']);
    }

    public function test_user_can_add_product_to_accessible_branch(): void
    {
        $this->role->givePermissionTo('manage branch products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $product = Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'base_selling_price' => 99.99,
        ]);

        // Create a branch-specific role for the user
        $branchRole = Role::create([
            'name' => 'Branch Manager',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $branchRole->givePermissionTo('edit products');

        // Assign user to role for main branch
        \DB::table('model_has_roles')->insert([
            'role_id' => $branchRole->id,
            'model_type' => get_class($this->user),
            'model_id' => $this->user->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        // Try to add product to main branch (should succeed)
        $data = [
            'branch_id' => $this->branch->id,
            'selling_price' => 99.99,
            'stock_quantity' => 100,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/products/'.$product->id.'/branches?current_business_id='.$this->business->id, $data);

        $response->assertStatus(200);
    }

    public function test_user_cannot_remove_product_from_branch_without_access(): void
    {
        $branchUser = User::factory()->create();
        $branchUser->businesses()->attach($this->business->id, ['is_active' => true]);

        // Create another branch
        $otherBranch = Branch::create([
            'business_id' => $this->business->id,
            'name' => 'Other Branch',
            'code' => 'OTHER',
            'address' => '456 Other St',
        ]);

        $product = Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'base_selling_price' => 99.99,
        ]);

        BranchProduct::create([
            'product_id' => $product->id,
            'branch_id' => $otherBranch->id,
            'stock_quantity' => 50,
        ]);

        // Create a branch-specific role for the user (only for main branch)
        $branchRole = Role::create([
            'name' => 'Branch Manager',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $branchRole->givePermissionTo('manage branch products');

        DB::table('model_has_roles')->insert([
            'role_id' => $branchRole->id,
            'model_type' => User::class,
            'model_id' => $branchUser->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        // Try to remove product from other branch (should fail)
        $response = $this->actingAs($branchUser, 'sanctum')
            ->deleteJson('/api/products/'.$product->id.'/branches?branch_id='.$otherBranch->id.'&current_business_id='.$this->business->id);

        $response->assertStatus(403)
            ->assertJson(['message' => 'You do not have access to this branch']);
    }

    public function test_user_cannot_filter_products_by_inaccessible_branch(): void
    {
        $branchUser = User::factory()->create();
        $branchUser->businesses()->attach($this->business->id, ['is_active' => true]);

        // Create another branch
        $otherBranch = Branch::create([
            'business_id' => $this->business->id,
            'name' => 'Other Branch',
            'code' => 'OTHER',
            'address' => '456 Other St',
        ]);

        // Create a branch-specific role for the user (only for main branch)
        $branchRole = Role::create([
            'name' => 'Branch Manager',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $branchRole->givePermissionTo('view products');

        DB::table('model_has_roles')->insert([
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
            ->getJson('/api/products?branch_id='.$otherBranch->id.'&current_business_id='.$this->business->id);

        $response->assertStatus(403)
            ->assertJson(['message' => 'You do not have access to this branch']);
    }

    public function test_business_wide_role_can_access_all_branches(): void
    {
        $this->role->givePermissionTo('manage branch products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        // Create another branch
        $otherBranch = Branch::create([
            'business_id' => $this->business->id,
            'name' => 'Other Branch',
            'code' => 'OTHER',
            'address' => '456 Other St',
        ]);

        $product = Product::create([
            'uuid' => \Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'base_selling_price' => 99.99,
        ]);

        // User has business-wide role (no branch_id), so should access all branches
        $data = [
            'branch_id' => $otherBranch->id,
            'selling_price' => 99.99,
            'stock_quantity' => 100,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/products/'.$product->id.'/branches?current_business_id='.$this->business->id, $data);

        $response->assertStatus(200);
    }
}
