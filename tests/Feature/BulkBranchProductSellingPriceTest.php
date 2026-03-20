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
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\SeedsPermissions;

class BulkBranchProductSellingPriceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPermissions;

    private User $owner;

    private Business $business;

    private Branch $branch;

    private Branch $otherBranch;

    private Product $productA;

    private Product $productB;

    private BranchProduct $bpA;

    private BranchProduct $bpB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAllPermissions();

        $this->owner = User::factory()->create();
        $this->business = Business::factory()->create(['owner_id' => $this->owner->id]);
        $this->owner->businesses()->attach($this->business->id, ['is_active' => true]);

        $this->branch = Branch::factory()->create(['business_id' => $this->business->id]);
        $this->otherBranch = Branch::factory()->create(['business_id' => $this->business->id]);

        $category = ProductCategory::factory()->create(['business_id' => $this->business->id]);

        $this->productA = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'stock_tracking' => 'simple',
        ]);
        $this->productB = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'stock_tracking' => 'simple',
        ]);

        $this->bpA = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->productA->id,
            'shelf_quantity' => 5,
            'store_quantity' => 5,
            'stock_quantity' => 10,
            'selling_price' => 10,
            'cost_price' => 5,
            'is_available' => true,
        ]);

        $this->bpB = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->productB->id,
            'shelf_quantity' => 2,
            'store_quantity' => 0,
            'stock_quantity' => 2,
            'selling_price' => 12,
            'cost_price' => 6,
            'is_available' => true,
        ]);
    }

    public function test_owner_can_bulk_update_selling_prices_per_item(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/branch-products/bulk-selling-price', [
            'branch_id' => $this->branch->id,
            'items' => [
                ['branch_product_id' => $this->bpA->id, 'selling_price' => 19.99],
                ['branch_product_id' => $this->bpB->id, 'selling_price' => 24.5],
            ],
        ], ['X-Business-Id' => $this->business->id]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Bulk selling price update completed.')
            ->assertJsonPath('summary.processed', 2)
            ->assertJsonPath('summary.updated', 2)
            ->assertJsonPath('summary.skipped', 0);

        $this->bpA->refresh();
        $this->bpB->refresh();
        $this->assertEquals(19.99, (float) $this->bpA->selling_price);
        $this->assertEquals(24.5, (float) $this->bpB->selling_price);
    }

    public function test_user_with_permission_can_bulk_update_selling_prices(): void
    {
        $pricer = User::factory()->create();
        $pricer->businesses()->attach($this->business->id, ['is_active' => true]);

        setPermissionsTeamId($this->business->id);
        $role = Role::create([
            'name' => 'Pricer',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $role->givePermissionTo('set branch product selling price');
        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $pricer->id,
            'business_id' => $this->business->id,
        ]);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Sanctum::actingAs($pricer);

        $response = $this->postJson('/api/branch-products/bulk-selling-price', [
            'branch_id' => $this->branch->id,
            'items' => [
                ['branch_product_id' => $this->bpA->id, 'selling_price' => 15],
            ],
        ], ['X-Business-Id' => $this->business->id]);

        $response->assertStatus(200);
        $this->bpA->refresh();
        $this->assertEquals(15.0, (float) $this->bpA->selling_price);
    }

    public function test_bulk_selling_price_returns_forbidden_without_permission(): void
    {
        $other = User::factory()->create();
        $other->businesses()->attach($this->business->id, ['is_active' => true]);

        setPermissionsTeamId($this->business->id);
        $role = Role::create([
            'name' => 'ViewerOnly',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $role->givePermissionTo('view products');
        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $other->id,
            'business_id' => $this->business->id,
        ]);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Sanctum::actingAs($other);

        $response = $this->postJson('/api/branch-products/bulk-selling-price', [
            'branch_id' => $this->branch->id,
            'items' => [
                ['branch_product_id' => $this->bpA->id, 'selling_price' => 99],
            ],
        ], ['X-Business-Id' => $this->business->id]);

        $response->assertStatus(403);
        $this->bpA->refresh();
        $this->assertEquals(10.0, (float) $this->bpA->selling_price);
    }

    public function test_validation_fails_when_branch_product_not_on_given_branch(): void
    {
        $bpOther = BranchProduct::create([
            'branch_id' => $this->otherBranch->id,
            'product_id' => $this->productA->id,
            'shelf_quantity' => 1,
            'store_quantity' => 0,
            'stock_quantity' => 1,
            'selling_price' => 8,
            'cost_price' => 4,
            'is_available' => true,
        ]);

        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/branch-products/bulk-selling-price', [
            'branch_id' => $this->branch->id,
            'items' => [
                ['branch_product_id' => $bpOther->id, 'selling_price' => 20],
            ],
        ], ['X-Business-Id' => $this->business->id]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        $bpOther->refresh();
        $this->assertEquals(8.0, (float) $bpOther->selling_price);
    }
}
