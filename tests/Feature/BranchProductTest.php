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
use Tests\TestCase;

class BranchProductTest extends TestCase
{
    use RefreshDatabase;

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
                    ]
                ]
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
                ]
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
                ]
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

        $response = $this->getJson('/api/branch-products?' . http_build_query([
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
                        ]
                    ]
                ]
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

        $response = $this->getJson("/api/branch-products/{$branchProduct->id}?" . http_build_query([
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
                    ]
                ]
            ])
            ->assertJson([
                'data' => [
                    'inventory' => [
                        'stock_quantity' => 150,
                        'shelf_quantity' => 50,
                        'store_quantity' => 100,
                    ]
                ]
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

        $response = $this->getJson("/api/branch-products/{$branchProduct->id}?" . http_build_query([
            'current_business_id' => $this->business->id,
        ]));

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'inventory' => [
                        'shelf_needs_restocking' => true,
                    ]
                ]
            ]);
    }
}
