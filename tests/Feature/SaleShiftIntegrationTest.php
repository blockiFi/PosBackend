<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SalesShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleShiftIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected $business;

    protected $branch;

    protected $product;

    protected $customer;

    protected $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        Permission::firstOrCreate(['name' => 'create sales', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'view sales', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'manage sales', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'view shifts', 'guard_name' => 'api']);

        // Create user
        $this->user = User::factory()->create();

        // Create business
        $this->business = Business::create([
            'name' => 'Test Business',
            'owner_id' => $this->user->id,
        ]);

        // Associate user with business
        $this->user->businesses()->attach($this->business->id);

        // Create branch
        $this->branch = Branch::create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'business_id' => $this->business->id,
            'address' => '123 Main St',
        ]);

        // Set permissions team
        setPermissionsTeamId($this->business->id);

        // Create role with permissions
        $role = Role::create(['name' => 'manager', 'guard_name' => 'api']);
        $role->givePermissionTo(['create sales', 'view sales', 'manage sales', 'view shifts']);
        $this->user->assignRole($role);

        // Create product
        $this->product = Product::create([
            'business_id' => $this->business->id,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'cost_price' => 50,
            'selling_price' => 100,
            'base_selling_price' => 100,
            'is_active' => true,
        ]);

        // Add product to branch with stock
        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'stock_quantity' => 100,
            'cost_price' => 50,
            'selling_price' => 100,
        ]);

        // Create customer
        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'customer_code' => 'CUST-001',
            'name' => 'Test Customer',
        ]);

        // Create payment method
        $this->paymentMethod = PaymentMethod::create([
            'business_id' => $this->business->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);
    }

    public function test_sale_automatically_associates_with_current_open_shift()
    {
        // Open a shift for the user
        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now(),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => 100,
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('sales', [
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'shift_id' => $shift->id,
        ]);
    }

    public function test_sale_can_specify_shift_id_manually()
    {
        // Open a shift
        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now(),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'shift_id' => $shift->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => 100,
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('sales', [
            'shift_id' => $shift->id,
        ]);
    }

    public function test_sale_without_open_shift_has_null_shift_id()
    {
        // No open shift exists

        $response = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => 100,
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('sales', [
            'business_id' => $this->business->id,
            'shift_id' => null,
        ]);
    }

    public function test_cannot_create_sale_with_closed_shift()
    {
        // Create a closed shift
        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subHours(8),
            'end_time' => now(),
            'opening_balance' => 100.00,
            'status' => 'closed',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'shift_id' => $shift->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => 100,
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Invalid or closed shift',
            ]);
    }

    public function test_cannot_create_sale_with_shift_from_different_branch()
    {
        // Create another branch
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTHER',
            'business_id' => $this->business->id,
            'address' => '456 Other St',
        ]);

        // Open shift in other branch
        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $otherBranch->id,
            'user_id' => $this->user->id,
            'start_time' => now(),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);

        // Add product to other branch
        BranchProduct::create([
            'branch_id' => $otherBranch->id,
            'product_id' => $this->product->id,
            'stock_quantity' => 100,
            'cost_price' => 50,
            'selling_price' => 100,
        ]);

        // Try to create sale in main branch with shift from other branch
        $response = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'shift_id' => $shift->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => 100,
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Shift branch does not match sale branch',
            ]);
    }

    public function test_sale_uses_correct_shift_when_multiple_shifts_exist()
    {
        // Create shift in different branch
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTHER',
            'business_id' => $this->business->id,
            'address' => '456 Other St',
        ]);

        SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $otherBranch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subHour(),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);

        // Create shift in main branch (this should be used)
        $mainBranchShift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0002',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now(),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => 100,
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('sales', [
            'business_id' => $this->business->id,
            'shift_id' => $mainBranchShift->id, // Should use the shift in the same branch
        ]);
    }

    public function test_shift_can_view_its_sales()
    {
        // Open a shift
        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now(),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);

        // Create a sale
        $response = $this->actingAs($this->user)->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => 100,
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $saleId = $response->json('sale.id');

        // View shift details
        $shiftResponse = $this->actingAs($this->user)->getJson("/api/shifts/{$shift->id}", [
            'X-Business-Id' => $this->business->id,
        ]);

        $shiftResponse->assertStatus(200);

        // Verify the sale is linked to the shift
        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'shift_id' => $shift->id,
        ]);
    }
}
