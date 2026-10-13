<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\AnalyticsRollupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    private Branch $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'UTC'));

        $this->user = User::factory()->create();
        $this->business = Business::factory()->create(['owner_id' => $this->user->id, 'time_zone' => null]);
        $this->user->businesses()->attach($this->business->id);
        $this->branch = Branch::factory()->create(['business_id' => $this->business->id, 'time_zone' => null]);
        $this->product = Product::factory()->create([
            'business_id' => $this->business->id,
            'base_cost_price' => 60,
            'base_selling_price' => 100,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);
        $this->user->givePermissionTo(['view analytics', 'view financial reports']);
        Sanctum::actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_live_analytics_use_sale_date_in_lagos_time_not_upload_time(): void
    {
        // 00:30 Lagos on the 13th, uploaded a week later.
        $this->sale(100, '2026-10-12 23:30:00', '2026-10-20 09:00:00');
        // 00:30 Lagos on the 14th: outside the 13th even though its UTC date is the 13th.
        $this->sale(40, '2026-10-13 23:30:00', '2026-10-13 23:30:00');

        $response = $this->getJson('/api/analytics/organization?start_date=2026-10-13&end_date=2026-10-13&compare_previous=0', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('current.transaction_count', 1)
            ->assertJsonPath('current.revenue', '100.00')
            ->assertJsonPath('period.start_date', '2026-10-13')
            ->assertJsonPath('revenue_trend.0.date', '2026-10-13')
            ->assertJsonCount(1, 'revenue_trend')
            ->assertJsonPath('branch_contributions.0.transaction_count', 1);

        $this->getJson("/api/analytics/profit-loss?period=custom&start_date=2026-10-13&end_date=2026-10-13&branch_id={$this->branch->id}", [
            'X-Business-Id' => $this->business->id,
        ])->assertOk()
            ->assertJsonPath('metrics.total_transactions', 1)
            ->assertJsonPath('revenue.net_revenue', '100.00');

        $this->getJson('/api/analytics/products?period=custom&start_date=2026-10-13&end_date=2026-10-13', [
            'X-Business-Id' => $this->business->id,
        ])->assertOk()->assertJsonPath('summary.total_revenue', '100.00');
    }

    public function test_business_time_zone_overrides_the_lagos_default(): void
    {
        $this->business->update(['time_zone' => 'UTC']);
        $this->sale(100, '2026-10-12 23:30:00', '2026-10-12 23:30:00');

        $this->getJson('/api/analytics/organization?start_date=2026-10-13&end_date=2026-10-13&compare_previous=0', [
            'X-Business-Id' => $this->business->id,
        ])->assertOk()->assertJsonPath('current.transaction_count', 0);
    }

    public function test_rollups_bucket_by_branch_local_sale_day_and_drop_stale_rows(): void
    {
        config(['analytics.use_rollups' => true]);

        $this->sale(100, '2026-10-12 23:30:00', '2026-10-15 09:00:00');

        // The observer already bucketed it on the Lagos day of its sale_date.
        $this->assertSame(['2026-10-13'], $this->rollupDays($this->branch));

        // A row left by the old created_at bucketing is removed by a range rebuild.
        DB::table('analytics_daily_summaries')->insert([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'sale_date' => '2026-10-15',
            'txn_count' => 1,
            'items_sold' => 1,
            'revenue' => 100,
            'discount' => 0,
            'cost' => 60,
            'profit' => 40,
            'computed_at' => now(),
        ]);

        $rebuilt = app(AnalyticsRollupService::class)->rollupDateRange(
            Carbon::parse('2026-10-12'),
            Carbon::parse('2026-10-16'),
            $this->business->id
        );

        $this->assertSame(2, $rebuilt);
        $this->assertSame(['2026-10-13'], $this->rollupDays($this->branch));
        $row = DB::table('analytics_daily_summaries')->where('branch_id', $this->branch->id)->first();
        $this->assertSame(1, (int) $row->txn_count);
        $this->assertEquals(60, (float) $row->cost);

        $this->getJson('/api/analytics/organization?start_date=2026-10-13&end_date=2026-10-13&compare_previous=0', [
            'X-Business-Id' => $this->business->id,
        ])->assertOk()->assertJsonPath('current.transaction_count', 1);
    }

    public function test_rollups_use_the_branch_time_zone_when_set(): void
    {
        config(['analytics.use_rollups' => true]);
        $newYork = Branch::factory()->create(['business_id' => $this->business->id, 'time_zone' => 'America/New_York']);

        // 02:00 UTC on the 13th is 22:00 on the 12th in New York.
        $this->sale(100, '2026-10-13 02:00:00', '2026-10-13 02:00:00', $newYork);

        $this->assertSame(['2026-10-12'], $this->rollupDays($newYork));
    }

    public function test_moving_a_sale_date_rebuilds_both_days(): void
    {
        config(['analytics.use_rollups' => true]);
        $sale = $this->sale(100, '2026-10-12 23:30:00', '2026-10-12 23:30:00');
        $this->assertSame(['2026-10-13'], $this->rollupDays($this->branch));

        $sale->update(['sale_date' => Carbon::parse('2026-10-14 10:00:00', 'UTC')]);

        $this->assertSame(['2026-10-14'], $this->rollupDays($this->branch));
    }

    /**
     * @return array<int, string>
     */
    private function rollupDays(Branch $branch): array
    {
        return DB::table('analytics_daily_summaries')
            ->where('branch_id', $branch->id)
            ->orderBy('sale_date')
            ->pluck('sale_date')
            ->map(fn ($date): string => Carbon::parse($date)->format('Y-m-d'))
            ->all();
    }

    private function sale(float $amount, string $saleDateUtc, string $createdAtUtc, ?Branch $branch = null): Sale
    {
        $branch ??= $this->branch;

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'branch_id' => $branch->id,
            'user_id' => $this->user->id,
            'sale_number' => 'SALE-'.Str::random(8),
            'sale_date' => Carbon::parse($saleDateUtc, 'UTC'),
            'status' => 'completed',
            'subtotal' => $amount,
            'discount_amount' => 0,
            'total_amount' => $amount,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => 1,
            'unit_price' => $amount,
            'subtotal' => $amount,
            'total' => $amount,
        ]);
        DB::table('sales')->where('id', $sale->id)->update(['created_at' => $createdAtUtc]);

        return $sale->fresh();
    }
}
