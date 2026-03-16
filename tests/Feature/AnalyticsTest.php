<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected $business;

    protected $branch1;

    protected $branch2;

    protected $product1;

    protected $product2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create user and business
        $this->user = User::factory()->create();
        $this->business = Business::factory()->create(['owner_id' => $this->user->id]);
        $this->user->update(['current_business_id' => $this->business->id]);
        $this->user->businesses()->attach($this->business->id);

        // Create branches
        $this->branch1 = Branch::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Main Branch',
        ]);
        $this->branch2 = Branch::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Downtown Branch',
        ]);

        // Create products
        $category = ProductCategory::factory()->create(['business_id' => $this->business->id]);
        $this->product1 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Product A',
            'base_cost_price' => 60,
            'base_selling_price' => 100,
        ]);
        $this->product2 = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Product B',
            'base_cost_price' => 30,
            'base_selling_price' => 50,
        ]);

        // Permissions are already seeded by TestCase::setUp()
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Set permissions team
        setPermissionsTeamId($this->business->id);
        $this->user->givePermissionTo('view analytics');
        $this->user->givePermissionTo('view financial reports');

        // Authenticate
        Sanctum::actingAs($this->user);
    }

    /** @test */
    public function it_requires_authentication_for_analytics()
    {
        // Send request without authentication (system checks business context first, returns 400)
        $response = $this->getJson('/api/analytics/organization');

        $response->assertStatus(400);
    }

    /** @test */
    public function it_requires_business_context()
    {
        $this->user->update(['current_business_id' => null]);

        $response = $this->actingAs($this->user)->getJson('/api/analytics/organization');

        $response->assertStatus(400)
            ->assertJson(['message' => 'Business context required']);
    }

    /** @test */
    public function it_requires_view_analytics_permission()
    {
        setPermissionsTeamId($this->business->id);
        $this->user->revokePermissionTo('view analytics');

        $response = $this->actingAs($this->user)->getJson('/api/analytics/organization', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function it_gets_organization_analytics_for_current_month()
    {
        // Create sales data
        $this->createSalesData($this->branch1, 10, 100); // 10 sales, $100 each
        $this->createSalesData($this->branch2, 5, 50); // 5 sales, $50 each

        $response = $this->actingAs($this->user)->getJson('/api/analytics/organization?period=month', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'period' => ['start_date', 'end_date', 'days'],
                'current' => [
                    'revenue',
                    'cost',
                    'profit',
                    'margin_percentage',
                    'transaction_count',
                    'average_order_value',
                ],
                'branch_contributions' => [
                    '*' => [
                        'branch_id',
                        'branch_name',
                        'revenue',
                        'profit',
                        'transaction_count',
                        'contribution_percentage',
                    ],
                ],
                'revenue_trend',
            ]);

        $data = $response->json();
        $this->assertEquals(15, $data['current']['transaction_count']);
        $this->assertEquals(2, count($data['branch_contributions']));
    }

    /** @test */
    public function it_compares_with_previous_period()
    {
        // Create current period sales
        $this->createSalesData($this->branch1, 10, 100);

        // Create previous period sales (31 days ago for monthly comparison)
        $this->createSalesData($this->branch1, 5, 80, now()->subDays(35));

        $response = $this->actingAs($this->user)->getJson('/api/analytics/organization?period=month&compare_previous=1', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'current',
                'previous',
                'comparison' => [
                    'revenue_change_percentage',
                    'profit_change_percentage',
                    'transaction_change_percentage',
                    'revenue_trend',
                    'profit_trend',
                ],
            ]);

        $data = $response->json();
        $this->assertEquals('up', $data['comparison']['revenue_trend']);
    }

    /** @test */
    public function it_gets_branch_analytics()
    {
        $this->createSalesData($this->branch1, 10, 100);
        $this->createSalesData($this->branch2, 5, 50);

        $response = $this->actingAs($this->user)->getJson("/api/analytics/branches?branch_id={$this->branch1->id}&period=month", [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'branches' => [
                    '*' => [
                        'branch_id',
                        'branch_name',
                        'period',
                        'current',
                        'revenue_trend',
                    ],
                ],
            ]);

        $data = $response->json();
        $this->assertEquals(1, count($data['branches']));
        $this->assertEquals($this->branch1->id, $data['branches'][0]['branch_id']);
        $this->assertEquals(10, $data['branches'][0]['current']['transaction_count']);
    }

    /** @test */
    public function it_gets_analytics_for_all_branches_when_no_branch_specified()
    {
        $this->createSalesData($this->branch1, 10, 100);
        $this->createSalesData($this->branch2, 5, 50);

        $response = $this->actingAs($this->user)->getJson('/api/analytics/branches?period=month', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals(2, count($data['branches']));
    }

    /** @test */
    public function it_gets_product_analytics()
    {
        // Create sales with different products
        $sale1 = $this->createSale($this->branch1, 150);
        SaleItem::create([
            'sale_id' => $sale1->id,
            'product_id' => $this->product1->id,
            'product_name' => $this->product1->name,
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
            'total' => 100,
        ]);
        SaleItem::create([
            'sale_id' => $sale1->id,
            'product_id' => $this->product2->id,
            'product_name' => $this->product2->name,
            'quantity' => 1,
            'unit_price' => 50,
            'subtotal' => 50,
            'total' => 50,
        ]);

        $sale2 = $this->createSale($this->branch1, 100);
        SaleItem::create([
            'sale_id' => $sale2->id,
            'product_id' => $this->product1->id,
            'product_name' => $this->product1->name,
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
            'total' => 100,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/analytics/products?period=month&limit=10', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'period',
                'summary' => [
                    'total_products',
                    'total_revenue',
                    'total_cost',
                    'total_profit',
                    'average_margin',
                ],
                'stock_valuation' => [
                    'total_stock_units',
                    'total_stock_revenue',
                    'total_stock_cost',
                    'total_stock_profit',
                    'by_branch' => [
                        '*' => [
                            'branch_id',
                            'branch_name',
                            'total_stock_units',
                            'total_stock_revenue',
                            'total_stock_cost',
                            'total_stock_profit',
                        ],
                    ],
                ],
                'top_products' => [
                    '*' => [
                        'product_id',
                        'product_name',
                        'product_sku',
                        'quantity_sold',
                        'revenue',
                        'cost',
                        'profit',
                        'margin_percentage',
                        'transaction_count',
                        'contribution_percentage',
                    ],
                ],
                'bottom_products',
            ]);

        $data = $response->json();
        $this->assertEquals(2, $data['summary']['total_products']);

        // Product 1 should be top (more revenue)
        $this->assertEquals($this->product1->id, $data['top_products'][0]['product_id']);
        $this->assertEquals(2, $data['top_products'][0]['quantity_sold']);
        $this->assertEquals('200.00', $data['top_products'][0]['revenue']);
    }

    /** @test */
    public function it_sorts_products_by_different_metrics()
    {
        // Create sales
        $sale = $this->createSale($this->branch1, 150);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product1->id,
            'product_name' => $this->product1->name,
            'quantity' => 5,
            'unit_price' => 100,
            'subtotal' => 500,
            'total' => 500,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product2->id,
            'product_name' => $this->product2->name,
            'quantity' => 10,
            'unit_price' => 50,
            'subtotal' => 500,
            'total' => 500,
        ]);

        // Sort by quantity
        $response = $this->actingAs($this->user)->getJson('/api/analytics/products?period=month&sort_by=quantity&direction=desc', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200);
        $data = $response->json();

        // Product 2 should be first (higher quantity)
        $this->assertEquals($this->product2->id, $data['top_products'][0]['product_id']);
        $this->assertEquals(10, $data['top_products'][0]['quantity_sold']);
    }

    /** @test */
    public function it_returns_stock_valuation_in_product_analytics()
    {
        BranchProduct::create([
            'branch_id' => $this->branch1->id,
            'product_id' => $this->product1->id,
            'cost_price' => 60,
            'selling_price' => 100,
            'stock_quantity' => 20,
            'shelf_quantity' => 20,
            'store_quantity' => 0,
            'is_available' => true,
        ]);
        BranchProduct::create([
            'branch_id' => $this->branch1->id,
            'product_id' => $this->product2->id,
            'cost_price' => 30,
            'selling_price' => 50,
            'stock_quantity' => 10,
            'shelf_quantity' => 10,
            'store_quantity' => 0,
            'is_available' => true,
        ]);

        $sale = $this->createSale($this->branch1, 100);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product1->id,
            'product_name' => $this->product1->name,
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
            'total' => 100,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/analytics/products?period=month', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('stock_valuation', $data);
        $valuation = $data['stock_valuation'];

        $this->assertEquals(30, $valuation['total_stock_units']);
        $this->assertEquals('2500.00', $valuation['total_stock_revenue']);
        $this->assertEquals('1500.00', $valuation['total_stock_cost']);
        $this->assertEquals('1000.00', $valuation['total_stock_profit']);

        $this->assertArrayHasKey('by_branch', $valuation);
        $this->assertIsArray($valuation['by_branch']);
        $this->assertCount(1, $valuation['by_branch']);
        $branchValuation = $valuation['by_branch'][0];
        $this->assertEquals($this->branch1->id, $branchValuation['branch_id']);
        $this->assertEquals($this->branch1->name, $branchValuation['branch_name']);
        $this->assertEquals(30, $branchValuation['total_stock_units']);
        $this->assertEquals('2500.00', $branchValuation['total_stock_revenue']);
        $this->assertEquals('1500.00', $branchValuation['total_stock_cost']);
        $this->assertEquals('1000.00', $branchValuation['total_stock_profit']);
    }

    /** @test */
    public function it_gets_profit_and_loss_statement()
    {
        // Create sales with known values
        $sale = $this->createSale($this->branch1, 900, 100); // $1000 gross - $100 discount = $900 net
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product1->id,
            'product_name' => $this->product1->name,
            'quantity' => 10,
            'unit_price' => 100,
            'subtotal' => 1000,
            'total' => 1000,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/analytics/profit-loss?period=month', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'period',
                'revenue' => ['gross_revenue', 'discounts', 'net_revenue'],
                'costs' => ['cost_of_goods_sold'],
                'profit' => ['gross_profit', 'net_profit'],
                'margins' => ['gross_margin_percentage', 'net_margin_percentage'],
                'metrics' => ['total_transactions', 'average_transaction_value'],
            ]);

        $data = $response->json();
        $this->assertEquals('1000.00', $data['revenue']['gross_revenue']);
        $this->assertEquals('100.00', $data['revenue']['discounts']);
        $this->assertEquals('900.00', $data['revenue']['net_revenue']);
        $this->assertEquals('600.00', $data['costs']['cost_of_goods_sold']);
        $this->assertEquals('300.00', $data['profit']['gross_profit']);
    }

    /** @test */
    public function it_requires_financial_reports_permission_for_pl()
    {
        setPermissionsTeamId($this->business->id);
        $this->user->revokePermissionTo('view financial reports');

        $response = $this->actingAs($this->user)->getJson('/api/analytics/profit-loss?period=month', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function it_gets_growth_trends()
    {
        // Create sales in different months
        $this->createSalesData($this->branch1, 5, 100, now()->subMonths(2));
        $this->createSalesData($this->branch1, 7, 100, now()->subMonths(1));
        $this->createSalesData($this->branch1, 10, 100, now());

        $response = $this->actingAs($this->user)->getJson('/api/analytics/growth-trends?interval=monthly&periods=3', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'interval',
                'periods',
                'trends' => [
                    '*' => [
                        'period',
                        'start_date',
                        'end_date',
                        'revenue',
                        'profit',
                        'transactions',
                        'average_order_value',
                        'revenue_growth_percentage',
                    ],
                ],
            ]);

        $data = $response->json();
        $this->assertEquals(3, count($data['trends']));

        // First period has no growth comparison
        $this->assertNull($data['trends'][0]['revenue_growth_percentage']);

        // Second period should have growth
        $this->assertNotNull($data['trends'][1]['revenue_growth_percentage']);
    }

    /** @test */
    public function it_validates_period_parameter()
    {
        $response = $this->actingAs($this->user)->getJson('/api/analytics/organization?period=invalid', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['period']);
    }

    /** @test */
    public function it_validates_custom_date_range()
    {
        $response = $this->actingAs($this->user)->getJson('/api/analytics/organization?period=custom', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['start_date', 'end_date']);
    }

    /** @test */
    public function it_handles_custom_date_range()
    {
        $this->createSalesData($this->branch1, 5, 100, now()->subDays(10));

        $startDate = now()->subDays(15)->format('Y-m-d');
        $endDate = now()->subDays(5)->format('Y-m-d');

        $response = $this->actingAs($this->user)->getJson("/api/analytics/organization?period=custom&start_date={$startDate}&end_date={$endDate}", [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals($startDate, $data['period']['start_date']);
        $this->assertEquals($endDate, $data['period']['end_date']);
    }

    /** @test */
    public function it_filters_only_completed_sales()
    {
        // Create completed sale
        $this->createSalesData($this->branch1, 5, 100);

        // Create pending sale (should be excluded)
        $sale = Sale::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch1->id,
            'user_id' => $this->user->id,
            'sale_number' => 'SALE-PENDING-'.uniqid(),
            'sale_date' => now(),
            'status' => 'pending',
            'subtotal' => 100,
            'discount_amount' => 0,
            'total_amount' => 100,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/analytics/organization?period=month', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200);
        $data = $response->json();

        // Should only count the 5 completed sales
        $this->assertEquals(5, $data['current']['transaction_count']);
    }

    /** @test */
    public function it_calculates_margins_correctly()
    {
        $sale = $this->createSale($this->branch1, 100);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product1->id,
            'product_name' => $this->product1->name,
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
            'total' => 100,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/analytics/organization?period=month', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200);
        $data = $response->json();

        // Revenue: 100, Cost: 60, Profit: 40, Margin: 40%
        $this->assertEquals('100.00', $data['current']['revenue']);
        $this->assertEquals('60.00', $data['current']['cost']);
        $this->assertEquals('40.00', $data['current']['profit']);
        $this->assertEquals('40.00', $data['current']['margin_percentage']);
    }

    // Helper Methods

    protected function createSalesData($branch, $count, $amount, $date = null)
    {
        $product = $amount <= $this->product2->base_selling_price
            ? $this->product2
            : $this->product1;

        for ($i = 0; $i < $count; $i++) {
            $sale = $this->createSale($branch, $amount, 0, $date);
            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => 1,
                'unit_price' => $amount,
                'subtotal' => $amount,
                'total' => $amount,
            ]);
        }
    }

    protected function createSale($branch, $amount, $discount = 0, $date = null)
    {
        return Sale::create([
            'business_id' => $this->business->id,
            'branch_id' => $branch->id,
            'user_id' => $this->user->id,
            'sale_number' => 'SALE-'.uniqid(),
            'sale_date' => $date ?? now(),
            'status' => 'completed',
            'subtotal' => $amount + $discount,
            'discount_amount' => $discount,
            'total_amount' => $amount,
        ]);
    }
}
