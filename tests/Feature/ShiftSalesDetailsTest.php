<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalesShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShiftSalesDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    private Branch $branch;

    private SalesShift $shift;

    private PaymentMethod $cashMethod;

    private PaymentMethod $cardMethod;

    protected function setUp(): void
    {
        parent::setUp();

        // Create business
        $this->business = Business::factory()->create();

        // Create branch
        $this->branch = Branch::factory()->create([
            'business_id' => $this->business->id,
        ]);

        // Create user
        $this->user = User::factory()->create();
        $this->user->businesses()->attach($this->business->id, ['is_active' => true]);

        // Create permission and role (controller checks 'view all shifts' or 'view user shift')
        Permission::firstOrCreate(['name' => 'view all shifts', 'guard_name' => 'api']);
        $role = Role::create([
            'name' => 'Manager',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $role->givePermissionTo('view all shifts');

        // Assign role to user
        \Illuminate\Support\Facades\DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => 'App\Models\User',
            'model_id' => $this->user->id,
            'business_id' => $this->business->id,
        ]);

        // Create payment methods
        $this->cashMethod = PaymentMethod::create([
            'business_id' => $this->business->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $this->cardMethod = PaymentMethod::create([
            'business_id' => $this->business->id,
            'name' => 'Card',
            'type' => 'card',
            'is_active' => true,
        ]);

        // Create a shift
        $this->shift = SalesShift::create([
            'shift_number' => 'SHIFT-20260208-0001',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now()->subHours(8),
            'end_time' => now(),
            'opening_balance' => 100.00,
            'status' => 'closed',
        ]);
    }

    public function test_shift_details_include_duration(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/shifts/'.$this->shift->id, [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'statistics' => [
                    'shift_duration' => [
                        'start_time',
                        'end_time',
                        'duration_minutes',
                        'duration_formatted',
                    ],
                ],
            ]);

        $duration = $response->json('statistics.shift_duration');
        $this->assertEquals(480, $duration['duration_minutes']); // 8 hours
        $this->assertEquals('8h 0m', $duration['duration_formatted']);
    }

    public function test_shift_details_include_sales_summary(): void
    {
        // Create sales with different payment methods
        $sale1 = $this->createSale(150.00, $this->cashMethod);
        $sale2 = $this->createSale(250.00, $this->cardMethod);
        $sale3 = $this->createSale(100.00, $this->cashMethod);

        // Refresh shift to load sales
        $this->shift->load(['sales' => function ($query) {
            $query->with(['payments.paymentMethod', 'customer', 'items.product'])
                ->withTrashed();
        }]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/shifts/'.$this->shift->id, [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'sales_details' => [
                    'summary' => [
                        'total_sold_amount',
                        'sales_count',
                        'voided_sales_count',
                        'cash_amount',
                        'pos_amount',
                    ],
                    'active_sales',
                    'voided_sales',
                ],
            ]);

        $summary = $response->json('sales_details.summary');
        $this->assertEquals(500.00, $summary['total_sold_amount']);
        $this->assertEquals(3, $summary['sales_count']);
        $this->assertEquals(250.00, $summary['cash_amount']); // 150 + 100
        $this->assertEquals(250.00, $summary['pos_amount']);
    }

    public function test_shift_details_show_individual_sales_with_payment_methods(): void
    {
        $sale = $this->createSale(150.00, $this->cashMethod, 'SALE-001');

        $response = $this->actingAs($this->user)
            ->getJson('/api/shifts/'.$this->shift->id, [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200);

        $activeSales = $response->json('sales_details.active_sales');
        $this->assertCount(1, $activeSales);
        $this->assertEquals('SALE-001', $activeSales[0]['sale_number']);
        $this->assertEquals(150.00, $activeSales[0]['total']);
        $this->assertEquals('Cash', $activeSales[0]['payment_methods']);
        $this->assertFalse($activeSales[0]['is_voided']);
    }

    public function test_shift_details_include_voided_sales(): void
    {
        // Create active sale
        $activeSale = $this->createSale(150.00, $this->cashMethod, 'SALE-001');

        // Create and void a sale
        $voidedSale = $this->createSale(200.00, $this->cardMethod, 'SALE-002');
        $voidedSale->delete(); // Soft delete to void

        $response = $this->actingAs($this->user)
            ->getJson('/api/shifts/'.$this->shift->id, [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200);

        $summary = $response->json('sales_details.summary');
        $this->assertEquals(1, $summary['sales_count']); // Only active
        $this->assertEquals(1, $summary['voided_sales_count']);
        $this->assertEquals(150.00, $summary['total_sold_amount']); // Only active sales

        $voidedSales = $response->json('sales_details.voided_sales');
        $this->assertCount(1, $voidedSales);
        $this->assertEquals('SALE-002', $voidedSales[0]['sale_number']);
        $this->assertTrue($voidedSales[0]['is_voided']);
        $this->assertEquals('voided', $voidedSales[0]['status']);
    }

    public function test_can_get_paginated_sales_for_shift(): void
    {
        // Create multiple sales
        for ($i = 1; $i <= 25; $i++) {
            $this->createSale(50.00, $this->cashMethod, "SALE-{$i}");
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/shifts/'.$this->shift->id.'/sales', [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'current_page',
                'per_page',
                'total',
            ]);

        $this->assertEquals(20, count($response->json('data'))); // Default pagination
        $this->assertEquals(25, $response->json('total'));
    }

    public function test_can_filter_sales_by_status(): void
    {
        // Create active sales
        $this->createSale(100.00, $this->cashMethod, 'SALE-001');
        $this->createSale(150.00, $this->cardMethod, 'SALE-002');

        // Create and void sales
        $voidedSale1 = $this->createSale(200.00, $this->cashMethod, 'SALE-003');
        $voidedSale1->delete();
        $voidedSale2 = $this->createSale(250.00, $this->cardMethod, 'SALE-004');
        $voidedSale2->delete();

        // Filter for active sales only
        $response = $this->actingAs($this->user)
            ->getJson('/api/shifts/'.$this->shift->id.'/sales?status=active', [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200);
        $this->assertEquals(2, count($response->json('data')));

        // Filter for voided sales only
        $response = $this->actingAs($this->user)
            ->getJson('/api/shifts/'.$this->shift->id.'/sales?status=voided', [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200);
        $this->assertEquals(2, count($response->json('data')));
        foreach ($response->json('data') as $sale) {
            $this->assertTrue($sale['is_voided']);
        }
    }

    public function test_can_filter_sales_by_payment_method(): void
    {
        $this->createSale(100.00, $this->cashMethod, 'SALE-001');
        $this->createSale(150.00, $this->cashMethod, 'SALE-002');
        $this->createSale(200.00, $this->cardMethod, 'SALE-003');

        $response = $this->actingAs($this->user)
            ->getJson('/api/shifts/'.$this->shift->id.'/sales?payment_method=Cash', [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200);
        $this->assertEquals(2, count($response->json('data')));

        foreach ($response->json('data') as $sale) {
            $this->assertStringContainsString('Cash', $sale['payment_methods'][0]['method']);
        }
    }

    public function test_sales_details_include_payment_breakdown(): void
    {
        $sale = $this->createSale(100.00, $this->cashMethod, 'SALE-001');

        $response = $this->actingAs($this->user)
            ->getJson('/api/shifts/'.$this->shift->id.'/sales', [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200);

        $saleData = $response->json('data.0');
        $this->assertArrayHasKey('payment_methods', $saleData);
        $this->assertCount(1, $saleData['payment_methods']);
        $this->assertEquals('Cash', $saleData['payment_methods'][0]['method']);
        $this->assertEquals(100.00, $saleData['payment_methods'][0]['amount']);
    }

    private function createSale(float $amount, PaymentMethod $paymentMethod, ?string $saleNumber = null): Sale
    {
        $saleNumber = $saleNumber ?? 'SALE-'.uniqid();

        $sale = Sale::create([
            'sale_number' => $saleNumber,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'sale_date' => now(),
            'subtotal' => $amount,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $amount,
            'status' => 'completed',
        ]);

        Payment::create([
            'business_id' => $this->business->id,
            'sale_id' => $sale->id,
            'payment_method_id' => $paymentMethod->id,
            'amount' => $amount,
            'payment_date' => now(),
            'status' => 'completed',
        ]);

        return $sale;
    }
}
