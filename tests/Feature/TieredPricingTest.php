<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchProductQuantityTier;
use App\Models\BranchProductUnitPrice;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Services\TieredPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TieredPricingTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $user;

    private BranchProduct $branchProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->business = Business::create([
            'name' => 'Test Business',
            'email' => 'b@test.com',
            'owner_id' => $this->user->id,
        ]);
        $this->branch = Branch::create([
            'business_id' => $this->business->id,
            'name' => 'Main',
            'code' => 'MAIN',
            'address' => '123 St',
        ]);
        $this->user->businesses()->attach($this->business->id, ['is_active' => true]);

        $product = Product::create([
            'business_id' => $this->business->id,
            'name' => 'Test Product',
            'sku' => 'TIER-001',
            'base_cost_price' => 50,
            'base_selling_price' => 100,
            'is_active' => true,
        ]);

        $this->branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'stock_quantity' => 200,
            'cost_price' => 50,
            'selling_price' => 100,
        ]);

        foreach (['view products', 'create sales', 'manage branch products', 'override sale price'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']);
        }

        $role = Role::create([
            'name' => 'Manager',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        setPermissionsTeamId($this->business->id);
        $this->user->assignRole($role);
    }

    public function test_tiered_pricing_service_returns_single_unit_price_when_no_tiers(): void
    {
        $service = new TieredPricingService;
        $result = $service->getUnitPrice($this->branchProduct, 5);

        $this->assertSame('single', $result['tier_type']);
        $this->assertEquals(100, (float) $result['unit_price']);
        $this->assertEquals(500, (float) $result['total']);
        $this->assertNull($result['product_unit_id']);
        $this->assertNull($result['quantity_tier_id']);
    }

    public function test_tiered_pricing_service_uses_quantity_tier_when_in_range(): void
    {
        BranchProductQuantityTier::create([
            'branch_product_id' => $this->branchProduct->id,
            'min_quantity' => 6,
            'max_quantity' => 19,
            'price_per_unit' => 90,
        ]);

        $service = new TieredPricingService;
        $result = $service->getUnitPrice($this->branchProduct, 10);

        $this->assertSame('quantity_range', $result['tier_type']);
        $this->assertEquals(90, (float) $result['unit_price']);
        $this->assertEquals(900, (float) $result['total']);
    }

    public function test_tiered_pricing_service_uses_pack_price_when_quantity_matches_multiplier(): void
    {
        $unit = ProductUnit::create([
            'product_id' => $this->branchProduct->product_id,
            'name' => 'Pack of 6',
            'quantity_multiplier' => 6,
            'display_order' => 0,
        ]);

        BranchProductUnitPrice::create([
            'branch_product_id' => $this->branchProduct->id,
            'product_unit_id' => $unit->id,
            'selling_price' => 500,
        ]);

        $service = new TieredPricingService;
        $result = $service->getUnitPrice($this->branchProduct, 6);

        $this->assertSame('pack', $result['tier_type']);
        $this->assertEqualsWithDelta(500 / 6, (float) $result['unit_price'], 0.01, 'unit_price per base unit');
        $this->assertEquals(500, (float) $result['total']);
        $this->assertEquals($unit->id, $result['product_unit_id']);
    }

    public function test_branch_product_price_endpoint_returns_tiered_price(): void
    {
        $this->role()->givePermissionTo('view products');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/branch-products/{$this->branchProduct->id}/price?quantity=1&current_business_id={$this->business->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.unit_price', 100)
            ->assertJsonPath('data.tier_type', 'single');
    }

    public function test_sale_without_unit_price_uses_computed_tier_price(): void
    {
        BranchProductQuantityTier::create([
            'branch_product_id' => $this->branchProduct->id,
            'min_quantity' => 1,
            'max_quantity' => 5,
            'price_per_unit' => 95,
        ]);

        $this->role()->givePermissionTo('create sales');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $pm = PaymentMethod::create([
            'business_id' => $this->business->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/sales?current_business_id='.$this->business->id, [
            'branch_id' => $this->branch->id,
            'items' => [
                [
                    'product_id' => $this->branchProduct->product_id,
                    'quantity' => 3,
                    'tax_rate' => 0,
                ],
            ],
            'payments' => [['payment_method_id' => $pm->id, 'amount' => 285]],
        ]);

        $response->assertStatus(201);
        $item = $response->json('sale.items.0');
        $this->assertEquals(95, (float) $item['unit_price']);
        $this->assertEquals(285, (float) $item['subtotal']);
    }

    public function test_sale_with_override_permission_uses_manual_unit_price(): void
    {
        $this->role()->givePermissionTo(['create sales', 'override sale price']);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $pm = PaymentMethod::create([
            'business_id' => $this->business->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/sales?current_business_id='.$this->business->id, [
            'branch_id' => $this->branch->id,
            'items' => [
                [
                    'product_id' => $this->branchProduct->product_id,
                    'quantity' => 2,
                    'unit_price' => 80,
                    'tax_rate' => 0,
                ],
            ],
            'payments' => [['payment_method_id' => $pm->id, 'amount' => 160]],
        ]);

        $response->assertStatus(201);
        $item = $response->json('sale.items.0');
        $this->assertEquals(80, (float) $item['unit_price']);
        $this->assertNotEmpty($item['metadata']['is_manual_override'] ?? null);
    }

    private function role(): Role
    {
        return Role::where('name', 'Manager')->where('business_id', $this->business->id)->first();
    }
}
