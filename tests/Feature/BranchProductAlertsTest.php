<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SeedsPermissions;

class BranchProductAlertsTest extends TestCase
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

    public function test_low_stock_scope_falls_back_to_product_threshold_and_skips_untracked(): void
    {
        $productThreshold = $this->stock('Product threshold', ['stock_quantity' => 4], ['low_stock_threshold' => 5]);
        $branchThreshold = $this->stock('Branch threshold', ['stock_quantity' => 8, 'low_stock_threshold' => 10], ['low_stock_threshold' => 2]);
        $this->stock('Branch threshold wins', ['stock_quantity' => 4, 'low_stock_threshold' => 3], ['low_stock_threshold' => 50]);
        $this->stock('Healthy', ['stock_quantity' => 40], ['low_stock_threshold' => 5]);
        $this->stock('Empty', ['stock_quantity' => 0], ['low_stock_threshold' => 5]);
        $this->stock('Untracked', ['stock_quantity' => 1], ['low_stock_threshold' => 5, 'stock_tracking' => 'none']);

        $ids = BranchProduct::where('branch_id', $this->branch->id)->lowStock()->pluck('id')->sort()->values()->all();

        $this->assertSame([$productThreshold->id, $branchThreshold->id], $ids);
        foreach (BranchProduct::with('product')->get() as $branchProduct) {
            $this->assertSame(in_array($branchProduct->id, $ids, true), $branchProduct->isLowStock(), $branchProduct->product->name);
        }

        Sanctum::actingAs($this->owner);
        $this->getJson("/api/branch-products?branch_id={$this->branch->id}&stock_status=low_stock", ['X-Business-Id' => $this->business->id])
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->getJson("/api/branch-products/summary/stock?branch_id={$this->branch->id}", ['X-Business-Id' => $this->business->id])
            ->assertOk()
            ->assertJsonPath('data.summary.low_stock', 2);
        setPermissionsTeamId($this->business->id);
        $this->owner->givePermissionTo('view inventory');
        $this->getJson('/api/dashboard/summary', ['X-Business-Id' => $this->business->id])
            ->assertOk()
            ->assertJsonPath('counts.low_stock', 2);
    }

    public function test_alerts_endpoint_groups_branch_stock_alerts(): void
    {
        $low = $this->stock('Milo', ['stock_quantity' => 3, 'shelf_quantity' => 3, 'store_quantity' => 0], ['low_stock_threshold' => 5, 'sku' => 'MILO', 'barcode' => '111']);
        $out = $this->stock('Indomie', ['stock_quantity' => 0, 'shelf_quantity' => 0, 'store_quantity' => 0], ['low_stock_threshold' => 5]);
        $restock = $this->stock('Peak', ['stock_quantity' => 30, 'shelf_quantity' => 2, 'store_quantity' => 28, 'low_stock_threshold' => 4], []);
        $this->stock('Healthy', ['stock_quantity' => 50, 'shelf_quantity' => 25, 'store_quantity' => 25], ['low_stock_threshold' => 5]);
        $this->stock('Untracked', ['stock_quantity' => 0], ['stock_tracking' => 'none']);
        $this->stock('Inactive product', ['stock_quantity' => 0], ['is_active' => false]);
        $this->stock('Unavailable at branch', ['stock_quantity' => 1, 'is_available' => false], ['low_stock_threshold' => 5]);

        Sanctum::actingAs($this->owner);
        $response = $this->getJson("/api/branch-products/alerts?branch_id={$this->branch->id}", ['X-Business-Id' => $this->business->id]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['low_stock', 'out_of_stock', 'shelf_restock'],
                'counts' => ['low_stock', 'out_of_stock', 'shelf_restock'],
                'generated_at',
            ])
            ->assertJsonPath('counts.low_stock', 1)
            ->assertJsonPath('counts.out_of_stock', 1)
            ->assertJsonPath('counts.shelf_restock', 1)
            ->assertJsonPath('data.out_of_stock.0.branch_product_id', $out->id)
            ->assertJsonPath('data.shelf_restock.0.branch_product_id', $restock->id);

        $this->assertSame([
            'branch_product_id' => $low->id,
            'product_id' => $low->product_id,
            'name' => 'Milo',
            'sku' => 'MILO',
            'barcode' => '111',
            'stock_quantity' => 3,
            'shelf_quantity' => 3,
            'store_quantity' => 0,
            'low_stock_threshold' => 5,
        ], $response->json('data.low_stock.0'));
        $this->assertSame(4, $response->json('data.shelf_restock.0.low_stock_threshold'));
    }

    public function test_alerts_require_permission_and_branch_access(): void
    {
        $otherBranch = Branch::factory()->create(['business_id' => $this->business->id]);
        $clerk = User::factory()->create();
        $clerk->businesses()->attach($this->business->id, ['is_active' => true]);
        $role = Role::create(['name' => 'Clerk', 'guard_name' => 'api', 'business_id' => $this->business->id]);
        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $clerk->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
        ]);
        Sanctum::actingAs($clerk);
        $headers = ['X-Business-Id' => $this->business->id];

        $this->getJson("/api/branch-products/alerts?branch_id={$this->branch->id}", $headers)->assertStatus(403);

        $role->givePermissionTo('view inventory');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->getJson("/api/branch-products/alerts?branch_id={$this->branch->id}", $headers)->assertOk();
        $this->getJson("/api/branch-products/alerts?branch_id={$otherBranch->id}", $headers)
            ->assertStatus(403)
            ->assertJsonPath('message', 'You do not have access to this branch');
        $this->getJson('/api/branch-products/alerts', $headers)->assertStatus(400);
    }

    /**
     * @param  array<string, mixed>  $branchAttributes
     * @param  array<string, mixed>  $productAttributes
     */
    private function stock(string $name, array $branchAttributes, array $productAttributes): BranchProduct
    {
        $product = Product::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'business_id' => $this->business->id,
            'name' => $name,
            'sku' => Str::upper(Str::random(10)),
            'base_selling_price' => 100,
            'stock_tracking' => 'simple',
            'low_stock_threshold' => 0,
            'is_active' => true,
        ], $productAttributes));

        return BranchProduct::create(array_merge([
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'selling_price' => 100,
            'shelf_quantity' => $branchAttributes['stock_quantity'] ?? 0,
            'store_quantity' => 0,
        ], $branchAttributes));
    }
}
