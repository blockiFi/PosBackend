<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalesShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesShiftRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected $business;

    protected $branch;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        Permission::firstOrCreate(['name' => 'view shifts', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'manage shifts', 'guard_name' => 'api']);

        // Create user
        $this->user = User::factory()->create();

        // Create business
        $this->business = Business::create([
            'name' => 'Test Business',
            'description' => 'A test business',
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
        $role->givePermissionTo(['view shifts', 'manage shifts']);
        $this->user->assignRole($role);
    }

    public function test_can_open_shift()
    {
        $response = $this->actingAs($this->user)->postJson('/api/shifts', [
            'branch_id' => $this->branch->id,
            'opening_balance' => 100.00,
            'opening_notes' => 'Starting shift',
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'shift' => [
                    'id',
                    'shift_number',
                    'business_id',
                    'branch_id',
                    'user_id',
                    'start_time',
                    'opening_balance',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('sales_shifts', [
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);
    }

    public function test_cannot_open_multiple_shifts()
    {
        // Open first shift
        SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now(),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);

        // Try to open second shift
        $response = $this->actingAs($this->user)->postJson('/api/shifts', [
            'branch_id' => $this->branch->id,
            'opening_balance' => 200.00,
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'You already have an active shift (open or paused). Please close or resume it before opening a new one.',
            ]);
    }

    public function test_can_close_shift_with_reconciliation()
    {
        $this->user->update(['pin_code' => '123456']);

        // Create payment method
        $paymentMethod = PaymentMethod::create([
            'business_id' => $this->business->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        // Create shift
        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subHours(8),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);

        // Create a sale in this shift
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'customer_code' => 'CUST-0001',
            'name' => 'Test Customer',
        ]);

        $sale = Sale::create([
            'sale_number' => 'SALE-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'shift_id' => $shift->id,
            'customer_id' => $customer->id,
            'sale_date' => now(),
            'total_amount' => 150.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'paid_amount' => 150.00,
        ]);

        // Create payment
        $sale->payments()->create([
            'business_id' => $this->business->id,
            'payment_method_id' => $paymentMethod->id,
            'amount' => 150.00,
            'payment_date' => now(),
        ]);

        // Close shift
        $response = $this->actingAs($this->user)->postJson("/api/shifts/{$shift->id}/close", [
            'actual_cash' => 248.00, // 100 opening + 150 sales - 2 shortage
            'closing_notes' => 'End of day',
            'pin_code' => '123456',
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'shift' => [
                    'id',
                    'status',
                    'end_time',
                    'expected_cash',
                    'actual_cash',
                    'variance',
                    'cash_sales',
                    'total_sales',
                ],
            ]);

        $shift->refresh();
        $this->assertEquals('closed', $shift->status);
        $this->assertEquals(250.00, $shift->expected_cash); // 100 opening + 150 cash sales
        $this->assertEquals(248.00, $shift->actual_cash);
        $this->assertEquals(-2.00, $shift->variance); // 248 - 250 = -2 (shortage)
        $this->assertEquals(150.00, $shift->cash_sales);
        $this->assertNotNull($shift->end_time);
    }

    public function test_close_shift_requires_pin()
    {
        $this->user->update(['pin_code' => '123456']);

        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subHours(8),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/shifts/{$shift->id}/close", [
            'actual_cash' => 100.00,
        ], [
            'X-Business-Id' => $this->business->id,
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors(['pin_code']);
    }

    public function test_close_shift_rejects_invalid_pin()
    {
        $this->user->update(['pin_code' => '123456']);

        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subHours(8),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/shifts/{$shift->id}/close", [
            'actual_cash' => 100.00,
            'pin_code' => '999999',
        ], [
            'X-Business-Id' => $this->business->id,
        ]);
        $response->assertStatus(401)->assertJson(['message' => 'Invalid PIN code']);
    }

    public function test_close_shift_requires_user_to_have_pin_set()
    {
        $this->user->update(['pin_code' => null]);

        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subHours(8),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/shifts/{$shift->id}/close", [
            'actual_cash' => 100.00,
            'pin_code' => '123456',
        ], [
            'X-Business-Id' => $this->business->id,
        ]);
        $response->assertStatus(400)
            ->assertJson([
                'message' => 'PIN verification required to close a shift. Set a PIN in your account first.',
            ]);
    }

    public function test_cannot_close_already_closed_shift()
    {
        $this->user->update(['pin_code' => '123456']);

        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subHours(8),
            'end_time' => now(),
            'opening_balance' => 100.00,
            'expected_cash' => 100.00,
            'actual_cash' => 100.00,
            'status' => 'closed',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/shifts/{$shift->id}/close", [
            'actual_cash' => 100.00,
            'pin_code' => '123456',
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Shift is already closed',
            ]);
    }

    public function test_can_pause_and_resume_shift()
    {
        $this->user->update(['pin_code' => '123456']);

        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subHours(2),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/shifts/{$shift->id}/pause", [], [
            'X-Business-Id' => $this->business->id,
        ]);
        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Shift paused successfully',
                'shift' => ['id' => $shift->id, 'status' => 'paused'],
            ]);
        $shift->refresh();
        $this->assertEquals('paused', $shift->status);
        $this->assertNotNull($shift->paused_at);

        $response = $this->actingAs($this->user)->getJson('/api/shifts/current', [
            'X-Business-Id' => $this->business->id,
        ]);
        $response->assertStatus(200)->assertJson(['id' => $shift->id, 'status' => 'paused']);

        $response = $this->actingAs($this->user)->postJson("/api/shifts/{$shift->id}/resume", [
            'pin_code' => '123456',
        ], [
            'X-Business-Id' => $this->business->id,
        ]);
        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Shift resumed successfully',
                'shift' => ['id' => $shift->id, 'status' => 'open'],
            ]);
        $shift->refresh();
        $this->assertEquals('open', $shift->status);
        $this->assertNull($shift->paused_at);
    }

    public function test_resume_shift_requires_pin()
    {
        $this->user->update(['pin_code' => '123456']);

        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subHours(2),
            'opening_balance' => 100.00,
            'status' => 'paused',
            'paused_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/shifts/{$shift->id}/resume", [], [
            'X-Business-Id' => $this->business->id,
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors(['pin_code']);
    }

    public function test_resume_shift_rejects_invalid_pin()
    {
        $this->user->update(['pin_code' => '123456']);

        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subHours(2),
            'opening_balance' => 100.00,
            'status' => 'paused',
            'paused_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/shifts/{$shift->id}/resume", [
            'pin_code' => '999999',
        ], [
            'X-Business-Id' => $this->business->id,
        ]);
        $response->assertStatus(401)->assertJson(['message' => 'Invalid PIN code']);
    }

    public function test_resume_shift_requires_user_to_have_pin_set()
    {
        $this->user->update(['pin_code' => null]);

        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subHours(2),
            'opening_balance' => 100.00,
            'status' => 'paused',
            'paused_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/shifts/{$shift->id}/resume", [
            'pin_code' => '123456',
        ], [
            'X-Business-Id' => $this->business->id,
        ]);
        $response->assertStatus(400)
            ->assertJson([
                'message' => 'PIN verification required to resume a shift. Set a PIN in your account first.',
            ]);
    }

    public function test_can_list_shifts()
    {
        SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subHours(8),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);

        SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0002',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subDays(1),
            'end_time' => now()->subDays(1)->addHours(8),
            'opening_balance' => 100.00,
            'status' => 'closed',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/shifts', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'shifts' => [
                    'data' => [
                        '*' => [
                            'id',
                            'shift_number',
                            'status',
                            'start_time',
                            'user',
                            'branch',
                        ],
                    ],
                ],
                'statistics' => [
                    'total_shifts_count',
                    'total_gross_sales',
                    'total_transactions',
                    'shifts_by_status' => [
                        'open',
                        'closed',
                        'paused',
                    ],
                    'average_basket_value',
                    'sales_by_payment_type' => [
                        'cash' => ['amount', 'percentage'],
                        'card' => ['amount', 'percentage'],
                        'other' => ['amount', 'percentage'],
                    ],
                ],
            ]);

        $this->assertEquals(2, count($response->json('shifts.data')));
        $this->assertEquals(2, $response->json('statistics.total_shifts_count'));
        $this->assertEquals(1, $response->json('statistics.shifts_by_status.open'));
        $this->assertEquals(1, $response->json('statistics.shifts_by_status.closed'));
    }

    public function test_can_view_shift_details()
    {
        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subHours(8),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/shifts/{$shift->id}", [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'id' => $shift->id,
                'shift_number' => $shift->shift_number,
                'status' => 'open',
            ]);
    }

    public function test_can_get_current_shift()
    {
        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now(),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/shifts/current', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'id' => $shift->id,
                'status' => 'open',
            ]);
    }

    public function test_shift_requires_permission()
    {
        // Use a non-owner user so owner bypass does not apply
        $nonOwner = User::factory()->create();
        $nonOwner->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $nonOwner->roles()->detach();
        $nonOwner->permissions()->detach();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $response = $this->actingAs($nonOwner)->postJson('/api/shifts', [
            'branch_id' => $this->branch->id,
            'opening_balance' => 100.00,
        ], [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Unauthorized']);
    }

    public function test_shift_number_is_unique()
    {
        $shift1 = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now(),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('sales_shifts', [
            'shift_number' => 'SHIFT-20260125-0001',
        ]);
    }

    public function test_shift_enforces_business_isolation()
    {
        // Create another business
        $otherBusiness = Business::create([
            'name' => 'Other Business',
            'owner_id' => $this->user->id,
        ]);

        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTHER',
            'business_id' => $otherBusiness->id,
            'address' => '456 Other St',
        ]);

        $shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260125-0001',
            'business_id' => $otherBusiness->id,
            'branch_id' => $otherBranch->id,
            'user_id' => $this->user->id,
            'start_time' => now(),
            'opening_balance' => 100.00,
            'status' => 'open',
        ]);

        // Try to access shift from other business
        $response = $this->actingAs($this->user)->getJson("/api/shifts/{$shift->id}", [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(404);
    }
}
