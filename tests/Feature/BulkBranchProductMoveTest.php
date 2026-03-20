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

class BulkBranchProductMoveTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPermissions;

    private User $owner;

    private Business $business;

    private Branch $branch;

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
            'store_quantity' => 20,
            'stock_quantity' => 25,
            'selling_price' => 10,
            'cost_price' => 5,
            'is_available' => true,
        ]);

        $this->bpB = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->productB->id,
            'shelf_quantity' => 10,
            'store_quantity' => 0,
            'stock_quantity' => 10,
            'selling_price' => 12,
            'cost_price' => 6,
            'is_available' => true,
        ]);
    }

    public function test_bulk_move_all_moves_full_store_to_shelf(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/branch-products/bulk-move', [
            'branch_id' => $this->branch->id,
            'direction' => 'to_shelf',
            'mode' => 'all',
        ], ['X-Business-Id' => $this->business->id]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Bulk move completed.')
            ->assertJsonPath('summary.processed', 2);

        $this->bpA->refresh();
        $this->bpB->refresh();

        $this->assertSame(25, (int) $this->bpA->shelf_quantity);
        $this->assertSame(0, (int) $this->bpA->store_quantity);
        $this->assertSame(10, (int) $this->bpB->shelf_quantity);
        $this->assertSame(0, (int) $this->bpB->store_quantity);
    }

    public function test_bulk_move_fixed_quantity_partial_when_insufficient_source(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/branch-products/bulk-move', [
            'branch_id' => $this->branch->id,
            'direction' => 'to_store',
            'mode' => 'fixed_quantity',
            'branch_product_ids' => [$this->bpA->id],
            'quantity' => 999,
        ], ['X-Business-Id' => $this->business->id]);

        $response->assertStatus(200);
        $results = $response->json('results');
        $this->assertCount(1, $results);
        $this->assertSame(5, $results[0]['quantity_moved']);
        $this->assertSame(999, $results[0]['quantity_requested']);
        $this->assertFalse($results[0]['skipped']);

        $this->bpA->refresh();
        $this->assertSame(0, (int) $this->bpA->shelf_quantity);
        $this->assertSame(25, (int) $this->bpA->store_quantity);
    }

    public function test_bulk_move_per_item_with_different_quantities(): void
    {
        Sanctum::actingAs($this->owner);

        $this->bpB->update([
            'shelf_quantity' => 10,
            'store_quantity' => 8,
            'stock_quantity' => 18,
        ]);

        $response = $this->postJson('/api/branch-products/bulk-move', [
            'branch_id' => $this->branch->id,
            'direction' => 'to_shelf',
            'mode' => 'per_item',
            'items' => [
                ['branch_product_id' => $this->bpA->id, 'quantity' => 10],
                ['branch_product_id' => $this->bpB->id, 'quantity' => 5],
            ],
        ], ['X-Business-Id' => $this->business->id]);

        $response->assertStatus(200);

        $this->bpA->refresh();
        $this->bpB->refresh();

        $this->assertSame(15, (int) $this->bpA->shelf_quantity);
        $this->assertSame(10, (int) $this->bpA->store_quantity);
        $this->assertSame(15, (int) $this->bpB->shelf_quantity);
        $this->assertSame(3, (int) $this->bpB->store_quantity);
    }

    public function test_bulk_move_returns_forbidden_without_direct_move_permission(): void
    {
        $other = User::factory()->create();
        $other->businesses()->attach($this->business->id, ['is_active' => true]);

        setPermissionsTeamId($this->business->id);
        $role = Role::create([
            'name' => 'Viewer',
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

        $response = $this->postJson('/api/branch-products/bulk-move', [
            'branch_id' => $this->branch->id,
            'direction' => 'to_shelf',
            'mode' => 'all',
        ], ['X-Business-Id' => $this->business->id]);

        $response->assertStatus(403);
    }

    public function test_bulk_move_skips_products_with_stock_tracking_none(): void
    {
        $this->productB->update(['stock_tracking' => 'none']);

        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/branch-products/bulk-move', [
            'branch_id' => $this->branch->id,
            'direction' => 'to_store',
            'mode' => 'all',
        ], ['X-Business-Id' => $this->business->id]);

        $response->assertStatus(200);

        $results = collect($response->json('results'))->keyBy('branch_product_id');
        $this->assertTrue($results[$this->bpB->id]['skipped']);
        $this->assertSame('stock_tracking_none', $results[$this->bpB->id]['reason']);
    }
}
