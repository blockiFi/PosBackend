<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\QuickSale;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    private Branch $branch;

    private Role $role;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->business = Business::create(['name' => 'Test Business', 'email' => 'b@test.com', 'owner_id' => $this->user->id]);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN', 'address' => '123 St']);
        $this->user->businesses()->attach($this->business->id, ['is_active' => true]);

        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'Test Product', 'sku' => 'TEST-001',
            'cost_price' => 50, 'selling_price' => 100, 'base_selling_price' => 100, 'is_active' => true,
        ]);

        BranchProduct::create(['branch_id' => $this->branch->id, 'product_id' => $this->product->id,
            'stock_quantity' => 100, 'cost_price' => 50, 'selling_price' => 100]);

        foreach (['view sales', 'create sales', 'manage sales'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']);
        }

        $this->role = Role::create(['name' => 'Manager', 'guard_name' => 'api', 'business_id' => $this->business->id]);
        setPermissionsTeamId($this->business->id);
        $this->user->assignRole($this->role);
    }

    public function test_can_create_sale_with_stock_update(): void
    {
        $this->role->givePermissionTo('create sales');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $pm = PaymentMethod::create(['business_id' => $this->business->id, 'name' => 'Cash', 'type' => 'cash', 'is_active' => true]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/sales?current_business_id='.$this->business->id, [
            'branch_id' => $this->branch->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 100, 'tax_rate' => 10]],
            'payments' => [['payment_method_id' => $pm->id, 'amount' => 550]],
        ]);

        if ($response->status() !== 201) {
            dump($response->json());
        }

        $response->assertStatus(201);
        $this->assertEquals(95, BranchProduct::where('branch_id', $this->branch->id)->value('stock_quantity'));
        $this->assertEquals('completed', Sale::latest()->value('status'));
    }

    public function test_cannot_sell_insufficient_stock(): void
    {
        $this->role->givePermissionTo('create sales');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/sales?current_business_id='.$this->business->id, [
            'branch_id' => $this->branch->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1000, 'unit_price' => 100]],
        ]);

        $response->assertStatus(500)
            ->assertJson(['message' => 'Failed to create sale']);
    }

    public function test_can_list_and_view_sales(): void
    {
        $this->role->givePermissionTo('view sales');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $sale = Sale::create(['sale_number' => 'SAL-001', 'business_id' => $this->business->id,
            'branch_id' => $this->branch->id, 'user_id' => $this->user->id, 'sale_date' => now(),
            'subtotal' => 100, 'total_amount' => 100, 'status' => 'completed', 'payment_status' => 'paid']);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/sales?current_business_id='.$this->business->id);
        $response->assertStatus(200)->assertJsonStructure(['data']);

        $response = $this->actingAs($this->user, 'sanctum')->getJson("/api/sales/{$sale->id}?current_business_id=".$this->business->id);
        $response->assertStatus(200)->assertJson(['id' => $sale->id]);
    }

    public function test_can_cancel_sale_and_restore_stock(): void
    {
        $this->role->givePermissionTo(['create sales', 'manage sales']);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $createResponse = $this->actingAs($this->user, 'sanctum')->postJson('/api/sales?current_business_id='.$this->business->id, [
            'branch_id' => $this->branch->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);

        $sale = Sale::latest()->first();
        $this->assertEquals(90, BranchProduct::where('branch_id', $this->branch->id)->value('stock_quantity'));

        $response = $this->actingAs($this->user, 'sanctum')->postJson("/api/sales/{$sale->id}/cancel?current_business_id=".$this->business->id);
        $response->assertStatus(200);
        $this->assertEquals(100, BranchProduct::where('branch_id', $this->branch->id)->value('stock_quantity'));
    }

    public function test_enforces_permissions(): void
    {
        $unprivilegedUser = User::factory()->create();
        $unprivilegedUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $response = $this->actingAs($unprivilegedUser, 'sanctum')->getJson('/api/sales?current_business_id='.$this->business->id);
        $response->assertStatus(403);
    }

    public function test_sale_deducts_from_active_quick_sale_batch_when_present(): void
    {
        $this->role->givePermissionTo('create sales');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'current_quantity' => 20,
            'received_quantity' => 20,
            'status' => 'active',
            'expiry_date' => now()->addMonths(1),
        ]);
        QuickSale::create([
            'product_id' => $this->product->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'batch_id' => $batch->id,
            'requested_by' => $this->user->id,
            'reason' => 'Test quick sale for batch deduction',
            'expiry_date' => now()->addDays(7),
            'status' => QuickSale::STATUS_ACTIVE,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'start_time' => now()->subHour(),
            'end_time' => now()->addDays(1),
        ]);

        $pm = PaymentMethod::create(['business_id' => $this->business->id, 'name' => 'Cash', 'type' => 'cash', 'is_active' => true]);
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/sales?current_business_id='.$this->business->id, [
            'branch_id' => $this->branch->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 3]],
            'payments' => [['payment_method_id' => $pm->id, 'amount' => 270]],
        ]);

        $response->assertStatus(201);
        $sale = Sale::latest()->first();
        $this->assertNotNull($sale);
        $saleItem = $sale->items()->first();
        $this->assertEquals($batch->id, $saleItem->batch_id);
        $this->assertDatabaseHas('inventory_transactions', [
            'reference_number' => $sale->sale_number,
            'type' => 'sale',
            'batch_id' => $batch->id,
            'quantity' => -3,
        ]);
        $batch->refresh();
        $this->assertEquals(17, $batch->current_quantity);
    }

    public function test_sale_creation_continues_when_batch_quantity_is_insufficient(): void
    {
        $this->role->givePermissionTo('create sales');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'current_quantity' => 2,
            'received_quantity' => 2,
            'status' => 'active',
            'expiry_date' => now()->addMonth(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/sales?current_business_id='.$this->business->id, [
            'branch_id' => $this->branch->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 100]],
        ]);

        $response->assertStatus(201);
        $sale = Sale::latest()->first();
        $this->assertNotNull($sale);
        $this->assertEquals(90, BranchProduct::where('branch_id', $this->branch->id)->value('stock_quantity'));

        $this->assertEquals(2, DB::table('inventory_transactions')
            ->where('reference_number', $sale->sale_number)
            ->where('type', 'batch_allocation')
            ->sum(DB::raw('ABS(quantity)')));
    }

    public function test_can_create_sale_with_fractional_quantity(): void
    {
        $this->enableDecimalQuantities($this->business);
        $this->role->givePermissionTo('create sales');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        BranchProduct::where('branch_id', $this->branch->id)
            ->where('product_id', $this->product->id)
            ->update(['stock_quantity' => 20, 'shelf_quantity' => 20]);

        $pm = PaymentMethod::create(['business_id' => $this->business->id, 'name' => 'Cash', 'type' => 'cash', 'is_active' => true]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/sales?current_business_id='.$this->business->id, [
            'branch_id' => $this->branch->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 10.5, 'unit_price' => 100, 'tax_rate' => 0]],
            'payments' => [['payment_method_id' => $pm->id, 'amount' => 1050]],
        ]);

        $response->assertStatus(201);

        $sale = Sale::latest()->first();
        $this->assertNotNull($sale);
        $this->assertEqualsWithDelta(10.5, (float) $sale->items->first()->quantity, 0.001);
        $this->assertEqualsWithDelta(9.5, (float) BranchProduct::where('branch_id', $this->branch->id)->value('stock_quantity'), 0.001);
    }

    public function test_fractional_sale_allocates_from_batch_via_fefo(): void
    {
        $this->enableDecimalQuantities($this->business);
        $this->role->givePermissionTo('create sales');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        BranchProduct::where('branch_id', $this->branch->id)
            ->where('product_id', $this->product->id)
            ->update(['stock_quantity' => 20, 'shelf_quantity' => 20]);

        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'current_quantity' => 20,
            'received_quantity' => 20,
            'status' => 'active',
            'expiry_date' => now()->addMonth(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/sales?current_business_id='.$this->business->id, [
            'branch_id' => $this->branch->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 10.5, 'unit_price' => 100]],
        ]);

        $response->assertStatus(201);
        $batch->refresh();
        $this->assertEqualsWithDelta(9.5, (float) $batch->current_quantity, 0.001);
        $this->assertEqualsWithDelta(9.5, (float) BranchProduct::where('branch_id', $this->branch->id)->value('stock_quantity'), 0.001);
    }

    public function test_rejects_fractional_sale_when_decimal_quantities_disabled(): void
    {
        $this->role->givePermissionTo('create sales');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $pm = PaymentMethod::create(['business_id' => $this->business->id, 'name' => 'Cash', 'type' => 'cash', 'is_active' => true]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/sales?current_business_id='.$this->business->id, [
            'branch_id' => $this->branch->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 10.5, 'unit_price' => 100, 'tax_rate' => 0]],
            'payments' => [['payment_method_id' => $pm->id, 'amount' => 1050]],
        ]);

        $response->assertStatus(422);

        $integerResponse = $this->actingAs($this->user, 'sanctum')->postJson('/api/sales?current_business_id='.$this->business->id, [
            'branch_id' => $this->branch->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 100, 'tax_rate' => 0]],
            'payments' => [['payment_method_id' => $pm->id, 'amount' => 1000]],
        ]);

        $integerResponse->assertStatus(201);
    }
}
