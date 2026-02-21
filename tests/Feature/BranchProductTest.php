<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\SeedsPermissions;

class BranchProductTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPermissions;

    protected $user;

    protected $business;

    protected $branch;

    protected $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->business = Business::factory()->create([
            'owner_id' => $this->user->id,
        ]);

        $this->user->businesses()->attach($this->business->id, [
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->branch = Branch::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $category = ProductCategory::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $this->product = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_create_branch_product_with_shelf_and_store_quantities()
    {
        $response = $this->postJson('/api/branch-products', [
            'current_business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 50,
            'store_quantity' => 100,
            'selling_price' => 29.99,
            'cost_price' => 15.00,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'inventory' => [
                        'stock_quantity',
                        'shelf_quantity',
                        'store_quantity',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('branch_products', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 50,
            'store_quantity' => 100,
            'stock_quantity' => 150,
        ]);
    }

    public function test_create_branch_product_with_stock_quantity_defaults_to_shelf()
    {
        $response = $this->postJson('/api/branch-products', [
            'current_business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'stock_quantity' => 100,
            'selling_price' => 29.99,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('branch_products', [
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 100,
            'store_quantity' => 0,
            'stock_quantity' => 100,
        ]);
    }

    public function test_update_branch_product_shelf_and_store_quantities()
    {
        $branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 50,
            'store_quantity' => 100,
            'stock_quantity' => 150,
            'selling_price' => 29.99,
        ]);

        $response = $this->putJson("/api/branch-products/{$branchProduct->id}", [
            'current_business_id' => $this->business->id,
            'shelf_quantity' => 75,
            'store_quantity' => 125,
        ]);

        $response->assertStatus(200);

        $branchProduct->refresh();
        $this->assertEquals(75, $branchProduct->shelf_quantity);
        $this->assertEquals(125, $branchProduct->store_quantity);
        $this->assertEquals(200, $branchProduct->stock_quantity);
    }

    public function test_update_only_shelf_quantity_recalculates_total()
    {
        $branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 50,
            'store_quantity' => 100,
            'stock_quantity' => 150,
            'selling_price' => 29.99,
        ]);

        $response = $this->putJson("/api/branch-products/{$branchProduct->id}", [
            'current_business_id' => $this->business->id,
            'shelf_quantity' => 60,
        ]);

        $response->assertStatus(200);

        $branchProduct->refresh();
        $this->assertEquals(60, $branchProduct->shelf_quantity);
        $this->assertEquals(100, $branchProduct->store_quantity);
        $this->assertEquals(160, $branchProduct->stock_quantity);
    }

    public function test_can_update_selling_price_with_permission()
    {
        $branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 10,
            'store_quantity' => 0,
            'stock_quantity' => 10,
            'selling_price' => 29.99,
        ]);

        $response = $this->patchJson("/api/branch-products/{$branchProduct->id}/selling-price", [
            'current_business_id' => $this->business->id,
            'selling_price' => 39.99,
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Selling price updated successfully'])
            ->assertJsonPath('data.pricing.selling_price', '39.99');

        $branchProduct->refresh();
        $this->assertEquals(39.99, (float) $branchProduct->selling_price);
    }

    public function test_update_selling_price_requires_permission()
    {
        $this->seedAllPermissions();

        $branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 10,
            'store_quantity' => 0,
            'stock_quantity' => 10,
            'selling_price' => 29.99,
        ]);

        $staff = User::factory()->create();
        $staff->businesses()->attach($this->business->id, ['is_active' => true]);
        $role = Role::create(['name' => 'staff', 'guard_name' => 'api', 'business_id' => $this->business->id]);
        $role->givePermissionTo('manage branch products');
        \DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $staff->id,
            'business_id' => $this->business->id,
        ]);

        $response = $this->actingAs($staff)->patchJson("/api/branch-products/{$branchProduct->id}/selling-price", [
            'selling_price' => 39.99,
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(403)->assertJson(['message' => 'Unauthorized']);
    }

    public function test_move_to_shelf_success()
    {
        $branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 50,
            'store_quantity' => 100,
            'stock_quantity' => 150,
            'selling_price' => 29.99,
        ]);

        $response = $this->postJson("/api/branch-products/{$branchProduct->id}/move-to-shelf", [
            'current_business_id' => $this->business->id,
            'quantity' => 25,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Stock moved to shelf successfully',
                'data' => [
                    'quantity_moved' => 25,
                    'previous_shelf_quantity' => 50,
                    'new_shelf_quantity' => 75,
                    'previous_store_quantity' => 100,
                    'new_store_quantity' => 75,
                ],
            ]);

        $branchProduct->refresh();
        $this->assertEquals(75, $branchProduct->shelf_quantity);
        $this->assertEquals(75, $branchProduct->store_quantity);
        $this->assertEquals(150, $branchProduct->stock_quantity);
    }

    public function test_move_to_shelf_insufficient_store_quantity()
    {
        $branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 50,
            'store_quantity' => 20,
            'stock_quantity' => 70,
            'selling_price' => 29.99,
        ]);

        $response = $this->postJson("/api/branch-products/{$branchProduct->id}/move-to-shelf", [
            'current_business_id' => $this->business->id,
            'quantity' => 50,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Insufficient quantity in store',
                'available_in_store' => 20,
            ]);
    }

    public function test_move_to_store_success()
    {
        $branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 50,
            'store_quantity' => 100,
            'stock_quantity' => 150,
            'selling_price' => 29.99,
        ]);

        $response = $this->postJson("/api/branch-products/{$branchProduct->id}/move-to-store", [
            'current_business_id' => $this->business->id,
            'quantity' => 20,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Stock moved to store successfully',
                'data' => [
                    'quantity_moved' => 20,
                    'previous_shelf_quantity' => 50,
                    'new_shelf_quantity' => 30,
                    'previous_store_quantity' => 100,
                    'new_store_quantity' => 120,
                ],
            ]);

        $branchProduct->refresh();
        $this->assertEquals(30, $branchProduct->shelf_quantity);
        $this->assertEquals(120, $branchProduct->store_quantity);
        $this->assertEquals(150, $branchProduct->stock_quantity);
    }

    public function test_move_to_store_insufficient_shelf_quantity()
    {
        $branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 10,
            'store_quantity' => 100,
            'stock_quantity' => 110,
            'selling_price' => 29.99,
        ]);

        $response = $this->postJson("/api/branch-products/{$branchProduct->id}/move-to-store", [
            'current_business_id' => $this->business->id,
            'quantity' => 20,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Insufficient quantity on shelf',
                'available_on_shelf' => 10,
            ]);
    }

    public function test_list_branch_products_includes_shelf_and_store_info()
    {
        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 50,
            'store_quantity' => 100,
            'stock_quantity' => 150,
            'low_stock_threshold' => 20,
            'selling_price' => 29.99,
        ]);

        $response = $this->getJson('/api/branch-products?'.http_build_query([
            'current_business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
        ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'inventory' => [
                            'stock_quantity',
                            'shelf_quantity',
                            'store_quantity',
                            'shelf_needs_restocking',
                        ],
                    ],
                ],
            ]);
    }

    public function test_show_branch_product_includes_shelf_and_store_info()
    {
        $branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 50,
            'store_quantity' => 100,
            'stock_quantity' => 150,
            'selling_price' => 29.99,
        ]);

        $response = $this->getJson("/api/branch-products/{$branchProduct->id}?".http_build_query([
            'current_business_id' => $this->business->id,
        ]));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'inventory' => [
                        'stock_quantity',
                        'shelf_quantity',
                        'store_quantity',
                        'shelf_needs_restocking',
                    ],
                ],
            ])
            ->assertJson([
                'data' => [
                    'inventory' => [
                        'stock_quantity' => 150,
                        'shelf_quantity' => 50,
                        'store_quantity' => 100,
                    ],
                ],
            ]);
    }

    public function test_validates_quantity_is_required_for_move_to_shelf()
    {
        $branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 50,
            'store_quantity' => 100,
            'stock_quantity' => 150,
        ]);

        $response = $this->postJson("/api/branch-products/{$branchProduct->id}/move-to-shelf", [
            'current_business_id' => $this->business->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);
    }

    public function test_validates_quantity_is_positive_for_move_to_shelf()
    {
        $branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 50,
            'store_quantity' => 100,
            'stock_quantity' => 150,
        ]);

        $response = $this->postJson("/api/branch-products/{$branchProduct->id}/move-to-shelf", [
            'current_business_id' => $this->business->id,
            'quantity' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);
    }

    public function test_shelf_needs_restocking_flag_works()
    {
        $branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 5,
            'store_quantity' => 50,
            'stock_quantity' => 55,
            'low_stock_threshold' => 10,
            'selling_price' => 29.99,
        ]);

        $response = $this->getJson("/api/branch-products/{$branchProduct->id}?".http_build_query([
            'current_business_id' => $this->business->id,
        ]));

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'inventory' => [
                        'shelf_needs_restocking' => true,
                    ],
                ],
            ]);
    }

    // ==================== Assign Multiple Products Tests ====================

    public function test_can_assign_multiple_products_to_branch_with_default_data()
    {
        $category = ProductCategory::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $product1 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'base_cost_price' => 10.00,
            'base_selling_price' => 25.00,
            'default_tax_rate' => 8.5,
            'low_stock_threshold' => 20,
        ]);

        $product2 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'base_cost_price' => 15.00,
            'base_selling_price' => 30.00,
            'default_tax_rate' => 10.0,
            'low_stock_threshold' => 15,
        ]);

        $product3 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'base_cost_price' => 20.00,
            'base_selling_price' => 40.00,
            'default_tax_rate' => 12.0,
            'low_stock_threshold' => 25,
        ]);

        $response = $this->withHeaders(['X-Business-Id' => $this->business->id])
            ->postJson('/api/branch-products/assign-multiple', [
                'branch_id' => $this->branch->id,
                'product_ids' => [$product1->id, $product2->id, $product3->id],
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'branch_id',
                    'branch_name',
                    'total_requested',
                    'created',
                    'skipped',
                    'created_products' => [
                        '*' => ['product_id', 'product_name', 'branch_product_id'],
                    ],
                    'skipped_products',
                ],
            ])
            ->assertJsonPath('data.total_requested', 3)
            ->assertJsonPath('data.created', 3)
            ->assertJsonPath('data.skipped', 0);

        // Verify all products were created with default data
        $this->assertDatabaseHas('branch_products', [
            'branch_id' => $this->branch->id,
            'product_id' => $product1->id,
            'cost_price' => 10.00,
            'selling_price' => 25.00,
            'compare_price' => 25.00,
            'tax_rate' => 8.5,
            'low_stock_threshold' => 20,
            'stock_quantity' => 0,
            'shelf_quantity' => 0,
            'store_quantity' => 0,
            'is_available' => true,
            'is_featured' => false,
        ]);

        $this->assertDatabaseHas('branch_products', [
            'branch_id' => $this->branch->id,
            'product_id' => $product2->id,
            'cost_price' => 15.00,
            'selling_price' => 30.00,
            'tax_rate' => 10.0,
        ]);

        $this->assertDatabaseHas('branch_products', [
            'branch_id' => $this->branch->id,
            'product_id' => $product3->id,
            'cost_price' => 20.00,
            'selling_price' => 40.00,
            'tax_rate' => 12.0,
        ]);
    }

    public function test_assign_multiple_skips_already_assigned_products()
    {
        $category = ProductCategory::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $product1 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
        ]);

        $product2 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
        ]);

        $product3 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
        ]);

        // Pre-assign product2 to the branch
        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product2->id,
            'selling_price' => 50.00,
        ]);

        $response = $this->withHeaders(['X-Business-Id' => $this->business->id])
            ->postJson('/api/branch-products/assign-multiple', [
                'branch_id' => $this->branch->id,
                'product_ids' => [$product1->id, $product2->id, $product3->id],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.total_requested', 3)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonCount(2, 'data.created_products')
            ->assertJsonCount(1, 'data.skipped_products')
            ->assertJsonPath('data.skipped_products.0.product_id', $product2->id)
            ->assertJsonPath('data.skipped_products.0.reason', 'Product already assigned to this branch');

        // Verify product2's existing data wasn't changed
        $existingBranchProduct = BranchProduct::where('branch_id', $this->branch->id)
            ->where('product_id', $product2->id)
            ->first();
        $this->assertEquals(50.00, $existingBranchProduct->selling_price);
    }

    public function test_assign_multiple_validates_required_fields()
    {
        $response = $this->withHeaders(['X-Business-Id' => $this->business->id])
            ->postJson('/api/branch-products/assign-multiple', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id', 'product_ids']);
    }

    public function test_assign_multiple_validates_product_ids_is_array()
    {
        $response = $this->withHeaders(['X-Business-Id' => $this->business->id])
            ->postJson('/api/branch-products/assign-multiple', [
                'branch_id' => $this->branch->id,
                'product_ids' => 'not-an-array',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product_ids']);
    }

    public function test_assign_multiple_validates_product_ids_not_empty()
    {
        $response = $this->withHeaders(['X-Business-Id' => $this->business->id])
            ->postJson('/api/branch-products/assign-multiple', [
                'branch_id' => $this->branch->id,
                'product_ids' => [],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product_ids']);
    }

    public function test_assign_multiple_validates_product_ids_exist()
    {
        $response = $this->withHeaders(['X-Business-Id' => $this->business->id])
            ->postJson('/api/branch-products/assign-multiple', [
                'branch_id' => $this->branch->id,
                'product_ids' => [999, 1000],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product_ids.0', 'product_ids.1']);
    }

    public function test_assign_multiple_requires_business_context()
    {
        $category = ProductCategory::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
        ]);

        $response = $this->postJson('/api/branch-products/assign-multiple', [
            'branch_id' => $this->branch->id,
            'product_ids' => [$product->id],
        ]);

        $response->assertStatus(400)
            ->assertJson(['message' => 'Business context is required']);
    }

    public function test_assign_multiple_validates_branch_belongs_to_business()
    {
        $otherBusiness = Business::factory()->create();
        $otherBranch = Branch::factory()->create([
            'business_id' => $otherBusiness->id,
        ]);

        $category = ProductCategory::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
        ]);

        $response = $this->withHeaders(['X-Business-Id' => $this->business->id])
            ->postJson('/api/branch-products/assign-multiple', [
                'branch_id' => $otherBranch->id,
                'product_ids' => [$product->id],
            ]);

        $response->assertStatus(404)
            ->assertJson(['message' => 'Branch not found']);
    }

    public function test_assign_multiple_validates_products_belong_to_business()
    {
        $otherBusiness = Business::factory()->create();
        $category = ProductCategory::factory()->create([
            'business_id' => $otherBusiness->id,
        ]);

        $otherProduct = Product::factory()->create([
            'business_id' => $otherBusiness->id,
            'category_id' => $category->id,
        ]);

        $response = $this->withHeaders(['X-Business-Id' => $this->business->id])
            ->postJson('/api/branch-products/assign-multiple', [
                'branch_id' => $this->branch->id,
                'product_ids' => [$otherProduct->id],
            ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Some products not found or do not belong to this business']);
    }

    public function test_assign_multiple_handles_mixed_valid_and_invalid_products()
    {
        $category = ProductCategory::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $validProduct = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
        ]);

        $otherBusiness = Business::factory()->create();
        $otherCategory = ProductCategory::factory()->create([
            'business_id' => $otherBusiness->id,
        ]);

        $invalidProduct = Product::factory()->create([
            'business_id' => $otherBusiness->id,
            'category_id' => $otherCategory->id,
        ]);

        $response = $this->withHeaders(['X-Business-Id' => $this->business->id])
            ->postJson('/api/branch-products/assign-multiple', [
                'branch_id' => $this->branch->id,
                'product_ids' => [$validProduct->id, $invalidProduct->id],
            ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Some products not found or do not belong to this business']);
    }

    public function test_assign_multiple_uses_default_product_values()
    {
        $category = ProductCategory::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'base_cost_price' => 12.50,
            'base_selling_price' => 29.99,
            'default_tax_rate' => 7.5,
            'low_stock_threshold' => 10,
        ]);

        $response = $this->withHeaders(['X-Business-Id' => $this->business->id])
            ->postJson('/api/branch-products/assign-multiple', [
                'branch_id' => $this->branch->id,
                'product_ids' => [$product->id],
            ]);

        $response->assertStatus(201);

        $branchProduct = BranchProduct::where('branch_id', $this->branch->id)
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($branchProduct);
        $this->assertEquals(12.50, $branchProduct->cost_price);
        $this->assertEquals(29.99, $branchProduct->selling_price);
        $this->assertEquals(29.99, $branchProduct->compare_price);
        $this->assertEquals(7.5, $branchProduct->tax_rate);
        $this->assertEquals(10, $branchProduct->low_stock_threshold);
        $this->assertEquals(0, $branchProduct->stock_quantity);
        $this->assertEquals(0, $branchProduct->shelf_quantity);
        $this->assertEquals(0, $branchProduct->store_quantity);
        $this->assertTrue($branchProduct->is_available);
        $this->assertFalse($branchProduct->is_featured);
        $this->assertEquals(0, $branchProduct->display_order);
        $this->assertFalse($branchProduct->allow_backorder);
    }

    public function test_assign_multiple_handles_null_product_defaults()
    {
        $category = ProductCategory::factory()->create([
            'business_id' => $this->business->id,
        ]);

        // Product with some null defaults (but base_cost_price, base_selling_price, and low_stock_threshold are required)
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'base_cost_price' => 10.00,
            'base_selling_price' => 20.00,
            'default_tax_rate' => null,
            'low_stock_threshold' => 0,
        ]);

        $response = $this->withHeaders(['X-Business-Id' => $this->business->id])
            ->postJson('/api/branch-products/assign-multiple', [
                'branch_id' => $this->branch->id,
                'product_ids' => [$product->id],
            ]);

        $response->assertStatus(201);

        $branchProduct = BranchProduct::where('branch_id', $this->branch->id)
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($branchProduct);
        $this->assertEquals(10.00, $branchProduct->cost_price);
        $this->assertEquals(20.00, $branchProduct->selling_price);
        $this->assertNull($branchProduct->tax_rate);
        $this->assertEquals(0, $branchProduct->low_stock_threshold);
    }

    public function test_assign_multiple_returns_correct_summary()
    {
        $category = ProductCategory::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $product1 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Product One',
        ]);

        $product2 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Product Two',
        ]);

        // Pre-assign product2
        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product2->id,
            'selling_price' => 50.00,
        ]);

        $response = $this->withHeaders(['X-Business-Id' => $this->business->id])
            ->postJson('/api/branch-products/assign-multiple', [
                'branch_id' => $this->branch->id,
                'product_ids' => [$product1->id, $product2->id],
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Products assigned to branch',
                'data' => [
                    'branch_id' => $this->branch->id,
                    'branch_name' => $this->branch->name,
                    'total_requested' => 2,
                    'created' => 1,
                    'skipped' => 1,
                ],
            ])
            ->assertJsonCount(1, 'data.created_products')
            ->assertJsonCount(1, 'data.skipped_products')
            ->assertJsonPath('data.created_products.0.product_id', $product1->id)
            ->assertJsonPath('data.created_products.0.product_name', 'Product One')
            ->assertJsonPath('data.skipped_products.0.product_id', $product2->id)
            ->assertJsonPath('data.skipped_products.0.product_name', 'Product Two');
    }
}
