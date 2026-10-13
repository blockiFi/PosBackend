<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Services\BranchProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SeedsPermissions;

class ProductCreateWithBranchTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPermissions;

    private User $owner;

    private Business $business;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAllPermissions();
        $this->owner = User::factory()->create();
        $this->business = Business::factory()->create(['owner_id' => $this->owner->id]);
        $this->owner->businesses()->attach($this->business->id, ['is_active' => true]);
        $this->branch = Branch::factory()->create(['business_id' => $this->business->id]);
    }

    public function test_creates_product_and_branch_product_together(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/products', [
            'name' => 'Peak Milk 400g',
            'sku' => 'PEAK-400',
            'base_selling_price' => 2500,
            'low_stock_threshold' => 5,
            'branch' => [
                'branch_id' => $this->branch->id,
                'cost_price' => 2000,
                'shelf_quantity' => 12,
                'store_quantity' => 24,
                'low_stock_threshold' => 6,
                'shelf_location' => 'A3',
            ],
        ], ['X-Business-Id' => $this->business->id]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Product created successfully')
            ->assertJsonPath('data.sku', 'PEAK-400')
            ->assertJsonPath('branch_product.branch_id', $this->branch->id)
            ->assertJsonPath('branch_product.product_id', $response->json('data.id'))
            ->assertJsonPath('branch_product.inventory.shelf_location', 'A3')
            ->assertJsonPath('branch_product.inventory.low_stock_threshold', 6);

        $this->assertEquals(36, (float) $response->json('branch_product.inventory.stock_quantity'));
        $this->assertEquals(2500, (float) $response->json('branch_product.pricing.selling_price'));

        $branchProduct = BranchProduct::where('branch_id', $this->branch->id)
            ->where('product_id', $response->json('data.id'))
            ->first();
        $this->assertNotNull($branchProduct);
        $this->assertEquals(12, (float) $branchProduct->shelf_quantity);
        $this->assertEquals(24, (float) $branchProduct->store_quantity);
    }

    public function test_create_without_branch_responds_as_before(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/products', [
            'name' => 'Plain Product',
            'sku' => 'PLAIN-1',
            'base_selling_price' => 100,
        ], ['X-Business-Id' => $this->business->id]);

        $response->assertStatus(201)->assertJsonMissingPath('branch_product');
        $this->assertSame(0, BranchProduct::count());
    }

    public function test_rolls_back_product_when_branch_product_creation_fails(): void
    {
        $this->mock(BranchProductService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createForBranch')->once()->andThrow(new \RuntimeException('boom'));
        });
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/products', [
            'name' => 'Orphan Candidate',
            'sku' => 'ORPHAN-1',
            'base_selling_price' => 100,
            'branch' => ['branch_id' => $this->branch->id, 'stock_quantity' => 3],
        ], ['X-Business-Id' => $this->business->id])->assertStatus(500);

        $this->assertDatabaseMissing('products', ['sku' => 'ORPHAN-1']);
    }

    public function test_requires_access_to_the_branch(): void
    {
        $otherBranch = Branch::factory()->create(['business_id' => $this->business->id]);
        $storekeeper = User::factory()->create();
        $storekeeper->businesses()->attach($this->business->id, ['is_active' => true]);
        $role = Role::create(['name' => 'Storekeeper', 'guard_name' => 'api', 'business_id' => $this->business->id]);
        $role->givePermissionTo(['create products', 'manage inventory']);
        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $storekeeper->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($storekeeper);

        $this->postJson('/api/products', [
            'name' => 'Wrong Branch',
            'sku' => 'WRONG-1',
            'base_selling_price' => 100,
            'branch' => ['branch_id' => $otherBranch->id],
        ], ['X-Business-Id' => $this->business->id])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You do not have access to this branch');

        $this->postJson('/api/products', [
            'name' => 'Right Branch',
            'sku' => 'RIGHT-1',
            'base_selling_price' => 100,
            'branch' => ['branch_id' => $this->branch->id, 'stock_quantity' => 4],
        ], ['X-Business-Id' => $this->business->id])->assertStatus(201);

        $this->assertDatabaseMissing('products', ['sku' => 'WRONG-1']);
        $this->assertDatabaseHas('products', ['sku' => 'RIGHT-1']);
    }

    public function test_rejects_branch_of_another_business_and_invalid_branch_fields(): void
    {
        $foreignBranch = Branch::factory()->create(['business_id' => Business::factory()->create()->id]);
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/products', [
            'name' => 'Foreign',
            'sku' => 'FOREIGN-1',
            'base_selling_price' => 100,
            'branch' => ['branch_id' => $foreignBranch->id],
        ], ['X-Business-Id' => $this->business->id])->assertStatus(404);

        $this->postJson('/api/products', [
            'name' => 'Bad Fields',
            'sku' => 'BAD-1',
            'base_selling_price' => 100,
            'branch' => ['shelf_quantity' => -1],
        ], ['X-Business-Id' => $this->business->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch.branch_id', 'branch.shelf_quantity']);

        $this->assertSame(0, Product::count());
    }

    public function test_sku_is_unique_per_business_only(): void
    {
        $otherBusiness = Business::factory()->create();
        Product::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $otherBusiness->id,
            'name' => 'Elsewhere',
            'sku' => 'SHARED-SKU',
            'base_selling_price' => 100,
        ]);
        $existing = Product::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => $this->business->id,
            'name' => 'Mine',
            'sku' => 'MINE-SKU',
            'base_selling_price' => 100,
        ]);
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/products', [
            'name' => 'Same SKU, other business',
            'sku' => 'SHARED-SKU',
            'base_selling_price' => 100,
        ], ['X-Business-Id' => $this->business->id])->assertStatus(201);

        $this->postJson('/api/products', [
            'name' => 'Duplicate in my business',
            'sku' => 'MINE-SKU',
            'base_selling_price' => 100,
        ], ['X-Business-Id' => $this->business->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sku']);

        $this->putJson("/api/products/{$existing->id}", [
            'sku' => 'SHARED-SKU',
            'base_selling_price' => 100,
        ], ['X-Business-Id' => $this->business->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sku']);

        $this->putJson("/api/products/{$existing->id}", [
            'sku' => 'MINE-SKU',
            'base_selling_price' => 100,
            'name' => 'Mine renamed',
        ], ['X-Business-Id' => $this->business->id])->assertOk();
    }
}
