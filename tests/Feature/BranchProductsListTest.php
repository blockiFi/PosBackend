<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\User_Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BranchProductsListTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    private Branch $branch;

    private Branch $otherBranch;

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

        // Create branches
        $this->branch = Branch::create([
            'business_id' => $this->business->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'address' => '123 Main St',
        ]);

        $this->otherBranch = Branch::create([
            'business_id' => $this->business->id,
            'name' => 'Secondary Branch',
            'code' => 'SEC',
            'address' => '456 Second St',
        ]);

        // Attach user to business
        User_Business::create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'is_active' => true,
        ]);

        // Create role
        $this->role = Role::create([
            'name' => 'Product Manager',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);

        // Create permissions
        Permission::firstOrCreate(['name' => 'view products', 'guard_name' => 'api']);

        // Assign role to user with business_id
        DB::table('model_has_roles')->insert([
            'role_id' => $this->role->id,
            'model_type' => User::class,
            'model_id' => $this->user->id,
            'business_id' => $this->business->id,
        ]);
    }

    public function test_user_with_permission_can_get_products_for_accessible_branch(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
        ]);

        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Test Product',
        ]);

        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'selling_price' => 100.00,
            'stock_quantity' => 50,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'sku',
                        'branch_data',
                    ],
                ],
                'branch' => ['id', 'name', 'code'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'paginated'],
            ])
            ->assertJsonPath('data.0.name', 'Test Product')
            ->assertJsonPath('branch.id', $this->branch->id);
    }

    public function test_user_without_permission_cannot_get_products(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create a non-owner user
        $nonOwner = User::factory()->create();
        User_Business::create([
            'user_id' => $nonOwner->id,
            'business_id' => $this->business->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($nonOwner, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}");

        $response->assertStatus(403)
            ->assertJson(['message' => 'You do not have permission to view products']);
    }

    public function test_user_without_branch_access_cannot_get_products(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create another user with access to different branch
        $otherUser = User::factory()->create();
        User_Business::create([
            'user_id' => $otherUser->id,
            'business_id' => $this->business->id,
            'is_active' => true,
        ]);

        $otherRole = Role::create([
            'name' => 'Branch Manager',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
            'branch_id' => $this->otherBranch->id, // Only access to otherBranch
        ]);

        $otherRole->givePermissionTo('view products');

        DB::table('model_has_roles')->insert([
            'role_id' => $otherRole->id,
            'model_type' => User::class,
            'model_id' => $otherUser->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->otherBranch->id,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $response = $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}");

        $response->assertStatus(403)
            ->assertJson(['message' => 'You do not have access to this branch']);
    }

    public function test_returns_unpaginated_results_when_requested(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
        ]);

        // Create 25 products
        for ($i = 1; $i <= 25; $i++) {
            $product = Product::factory()->create([
                'business_id' => $this->business->id,
                'category_id' => $category->id,
                'name' => "Product $i",
            ]);

            BranchProduct::create([
                'branch_id' => $this->branch->id,
                'product_id' => $product->id,
                'selling_price' => 100.00,
                'stock_quantity' => 50,
            ]);
        }

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}&paginated=false");

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.paginated', false)
            ->assertJsonCount(25, 'data');
    }

    public function test_returns_paginated_results_by_default(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
        ]);

        // Create 25 products
        for ($i = 1; $i <= 25; $i++) {
            $product = Product::factory()->create([
                'business_id' => $this->business->id,
                'category_id' => $category->id,
                'name' => "Product $i",
            ]);

            BranchProduct::create([
                'branch_id' => $this->branch->id,
                'product_id' => $product->id,
                'selling_price' => 100.00,
                'stock_quantity' => 50,
            ]);
        }

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}");

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.paginated', true)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonCount(15, 'data'); // Default per_page is 15
    }

    public function test_supports_custom_per_page(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
        ]);

        // Create 25 products
        for ($i = 1; $i <= 25; $i++) {
            $product = Product::factory()->create([
                'business_id' => $this->business->id,
                'category_id' => $category->id,
                'name' => "Product $i",
            ]);

            BranchProduct::create([
                'branch_id' => $this->branch->id,
                'product_id' => $product->id,
                'selling_price' => 100.00,
                'stock_quantity' => 50,
            ]);
        }

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}&per_page=10");

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonCount(10, 'data');
    }

    public function test_supports_start_id_parameter(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
        ]);

        $productIds = [];
        // Create 10 products
        for ($i = 1; $i <= 10; $i++) {
            $product = Product::factory()->create([
                'business_id' => $this->business->id,
                'category_id' => $category->id,
                'name' => "Product $i",
            ]);

            BranchProduct::create([
                'branch_id' => $this->branch->id,
                'product_id' => $product->id,
                'selling_price' => 100.00,
                'stock_quantity' => 50,
            ]);

            $productIds[] = $product->id;
        }

        // Get products starting from the 5th product
        $startId = $productIds[4]; // 0-indexed, so 4 is the 5th product

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}&start_id={$startId}&paginated=false");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(6, $data); // Should return 6 products (5th to 10th)
        $this->assertGreaterThanOrEqual($startId, $data[0]['id']);
    }

    public function test_filters_by_category(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $category1 = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
        ]);

        $category2 = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Clothing',
        ]);

        $product1 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category1->id,
            'name' => 'Laptop',
        ]);

        $product2 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category2->id,
            'name' => 'T-Shirt',
        ]);

        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product1->id,
            'selling_price' => 100.00,
            'stock_quantity' => 50,
        ]);

        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product2->id,
            'selling_price' => 20.00,
            'stock_quantity' => 100,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}&category_id={$category1->id}&paginated=false");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Laptop');
    }

    public function test_filters_active_only(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
        ]);

        $activeProduct = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Active Product',
            'is_active' => true,
        ]);

        $inactiveProduct = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Inactive Product',
            'is_active' => false,
        ]);

        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $activeProduct->id,
            'selling_price' => 100.00,
            'stock_quantity' => 50,
        ]);

        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $inactiveProduct->id,
            'selling_price' => 100.00,
            'stock_quantity' => 50,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}&active_only=true&paginated=false");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active Product');
    }

    public function test_filters_available_only(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
        ]);

        $product1 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Available Product',
        ]);

        $product2 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Unavailable Product',
        ]);

        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product1->id,
            'selling_price' => 100.00,
            'stock_quantity' => 50,
            'is_available' => true,
        ]);

        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product2->id,
            'selling_price' => 100.00,
            'stock_quantity' => 50,
            'is_available' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}&available_only=true&paginated=false");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Available Product');
    }

    public function test_filters_in_stock_only(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
        ]);

        $product1 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'In Stock Product',
        ]);

        $product2 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Out of Stock Product',
        ]);

        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product1->id,
            'selling_price' => 100.00,
            'stock_quantity' => 50,
        ]);

        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product2->id,
            'selling_price' => 100.00,
            'stock_quantity' => 0,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}&in_stock_only=true&paginated=false");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'In Stock Product');
    }

    public function test_filters_by_search_term(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
        ]);

        $product1 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Samsung Galaxy S21',
            'sku' => 'SAMS21',
        ]);

        $product2 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'iPhone 13',
            'sku' => 'IPH13',
        ]);

        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product1->id,
            'selling_price' => 100.00,
            'stock_quantity' => 50,
        ]);

        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product2->id,
            'selling_price' => 100.00,
            'stock_quantity' => 50,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}&search=Samsung&paginated=false");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Samsung Galaxy S21');
    }

    public function test_requires_business_context(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products");

        $response->assertStatus(400)
            ->assertJson(['message' => 'Business context is required']);
    }

    public function test_validates_branch_belongs_to_business(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create another business and branch
        $otherBusiness = Business::create([
            'name' => 'Other Business',
            'email' => 'other@test.com',
            'owner_id' => $this->user->id,
        ]);

        $otherBranch = Branch::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Branch',
            'code' => 'OTHER',
            'address' => '789 Other St',
        ]);

        // Try to access otherBranch with this business context
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$otherBranch->id}/products?current_business_id={$this->business->id}");

        $response->assertStatus(404)
            ->assertJson(['message' => 'Branch not found or does not belong to this business']);
    }

    public function test_only_returns_products_in_specified_branch(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
        ]);

        $product1 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Product in Main Branch',
        ]);

        $product2 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Product in Other Branch',
        ]);

        // Add product1 to main branch
        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product1->id,
            'selling_price' => 100.00,
            'stock_quantity' => 50,
        ]);

        // Add product2 to other branch only
        BranchProduct::create([
            'branch_id' => $this->otherBranch->id,
            'product_id' => $product2->id,
            'selling_price' => 100.00,
            'stock_quantity' => 50,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}&paginated=false");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Product in Main Branch');
    }

    public function test_includes_branch_specific_data_in_response(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
        ]);

        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Test Product',
        ]);

        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'selling_price' => 150.00,
            'cost_price' => 100.00,
            'stock_quantity' => 75,
            'is_available' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'branch_data' => [
                            'branch_id',
                            'selling_price',
                            'cost_price',
                            'stock_quantity',
                            'is_available',
                        ],
                    ],
                ],
            ])
            ->assertJsonPath('data.0.branch_data.selling_price', '150.00')
            ->assertJsonPath('data.0.branch_data.stock_quantity', 75);
    }

    public function test_products_can_be_sorted_by_branch_selling_price(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
        ]);

        $pHigh = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'High Price Item',
        ]);
        $pMid = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Mid Price Item',
        ]);
        $pLow = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Low Price Item',
        ]);

        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $pMid->id,
            'selling_price' => 50.00,
            'stock_quantity' => 10,
        ]);
        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $pHigh->id,
            'selling_price' => 300.00,
            'stock_quantity' => 10,
        ]);
        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $pLow->id,
            'selling_price' => 10.00,
            'stock_quantity' => 10,
        ]);

        $asc = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}&sort=selling_price_asc&paginated=false");

        $asc->assertStatus(200);
        $namesAsc = collect($asc->json('data'))->pluck('name')->all();
        $this->assertSame(['Low Price Item', 'Mid Price Item', 'High Price Item'], $namesAsc);

        $desc = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}&sort=selling_price_desc&paginated=false");

        $desc->assertStatus(200);
        $namesDesc = collect($desc->json('data'))->pluck('name')->all();
        $this->assertSame(['High Price Item', 'Mid Price Item', 'Low Price Item'], $namesDesc);

        $aliasAsc = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}&sort=lowest_price&paginated=false");

        $aliasAsc->assertStatus(200);
        $this->assertSame(
            ['Low Price Item', 'Mid Price Item', 'High Price Item'],
            collect($aliasAsc->json('data'))->pluck('name')->all(),
        );

        $aliasDesc = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}&sort=highest_price&paginated=false");

        $aliasDesc->assertStatus(200);
        $this->assertSame(
            ['High Price Item', 'Mid Price Item', 'Low Price Item'],
            collect($aliasDesc->json('data'))->pluck('name')->all(),
        );
    }

    public function test_branch_products_endpoint_can_be_sorted_by_branch_selling_price(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
        ]);

        $pHigh = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'High Price Item',
        ]);
        $pMid = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Mid Price Item',
        ]);
        $pLow = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Low Price Item',
        ]);

        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $pMid->id,
            'selling_price' => 50.00,
            'stock_quantity' => 10,
        ]);
        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $pHigh->id,
            'selling_price' => 300.00,
            'stock_quantity' => 10,
        ]);
        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $pLow->id,
            'selling_price' => 10.00,
            'stock_quantity' => 10,
        ]);

        $asc = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/branch-products?'.http_build_query([
                'current_business_id' => $this->business->id,
                'branch_id' => $this->branch->id,
                'sort' => 'lowest_price',
                'per_page' => 50,
            ]));

        $asc->assertStatus(200);
        $namesAsc = collect($asc->json('data'))->pluck('product.name')->all();
        $this->assertSame(['Low Price Item', 'Mid Price Item', 'High Price Item'], $namesAsc);

        $desc = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/branch-products?'.http_build_query([
                'current_business_id' => $this->business->id,
                'branch_id' => $this->branch->id,
                'sort' => 'selling_price_desc',
                'per_page' => 50,
            ]));

        $desc->assertStatus(200);
        $namesDesc = collect($desc->json('data'))->pluck('product.name')->all();
        $this->assertSame(['High Price Item', 'Mid Price Item', 'Low Price Item'], $namesDesc);
    }

    public function test_branch_products_endpoint_price_sort_can_use_cost_price_basis(): void
    {
        $this->role->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
        ]);

        $pHighCost = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'High Cost Item',
        ]);
        $pMidCost = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Mid Cost Item',
        ]);
        $pLowCost = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Low Cost Item',
        ]);

        // Keep selling_price same to ensure we're sorting by cost_price.
        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $pMidCost->id,
            'selling_price' => 100.00,
            'cost_price' => 50.00,
            'stock_quantity' => 10,
        ]);
        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $pHighCost->id,
            'selling_price' => 100.00,
            'cost_price' => 300.00,
            'stock_quantity' => 10,
        ]);
        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $pLowCost->id,
            'selling_price' => 100.00,
            'cost_price' => 10.00,
            'stock_quantity' => 10,
        ]);

        $asc = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/branch-products?'.http_build_query([
                'current_business_id' => $this->business->id,
                'branch_id' => $this->branch->id,
                'sort' => 'price_asc',
                'price_sort_basis' => 'cost',
                'per_page' => 50,
            ]));

        $asc->assertStatus(200);
        $namesAsc = collect($asc->json('data'))->pluck('product.name')->all();
        $this->assertSame(['Low Cost Item', 'Mid Cost Item', 'High Cost Item'], $namesAsc);

        $desc = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/branch-products?'.http_build_query([
                'current_business_id' => $this->business->id,
                'branch_id' => $this->branch->id,
                'sort' => 'price_desc',
                'price_sort_basis' => 'cost',
                'per_page' => 50,
            ]));

        $desc->assertStatus(200);
        $namesDesc = collect($desc->json('data'))->pluck('product.name')->all();
        $this->assertSame(['High Cost Item', 'Mid Cost Item', 'Low Cost Item'], $namesDesc);
    }

    public function test_unauthenticated_user_cannot_access(): void
    {
        $response = $this->getJson("/api/branches/{$this->branch->id}/products?current_business_id={$this->business->id}");

        $response->assertStatus(401);
    }
}
