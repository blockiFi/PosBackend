<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\ManagerActivity;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\SeedsPermissions;

class ManagerActivityTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPermissions;

    private User $owner;

    private User $manager;

    private User $storekeeper;

    private Business $business;

    private Branch $branch;

    private BranchProduct $bp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAllPermissions();

        $this->owner = User::factory()->create();
        $this->business = Business::factory()->create(['owner_id' => $this->owner->id]);
        $this->owner->businesses()->attach($this->business->id, ['is_active' => true]);
        $this->branch = Branch::factory()->create(['business_id' => $this->business->id]);

        $category = ProductCategory::factory()->create(['business_id' => $this->business->id]);
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'name' => 'Peak Milk 400g',
            'stock_tracking' => 'simple',
        ]);
        $this->bp = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'shelf_quantity' => 5,
            'store_quantity' => 5,
            'stock_quantity' => 10,
            'selling_price' => 10,
            'cost_price' => 5,
            'is_available' => true,
        ]);

        $this->manager = User::factory()->create(['name' => 'Bola Manager', 'pin_code' => '481516']);
        $this->manager->businesses()->attach($this->business->id, ['is_active' => true]);
        setPermissionsTeamId($this->business->id);
        $role = Role::create(['name' => 'Manager', 'guard_name' => 'api', 'business_id' => $this->business->id]);
        $role->givePermissionTo(['manage branch products', 'manage inventory', 'set branch product selling price']);
        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $this->manager->id,
            'business_id' => $this->business->id,
        ]);

        $this->storekeeper = User::factory()->create(['name' => 'Sade Store', 'pin_code' => '271828']);
        $this->storekeeper->businesses()->attach($this->business->id, ['is_active' => true]);
        $skRole = Role::create(['name' => 'Storekeeper', 'guard_name' => 'api', 'business_id' => $this->business->id]);
        $skRole->givePermissionTo(['manage branch products', 'manage inventory']);
        DB::table('model_has_roles')->insert([
            'role_id' => $skRole->id,
            'model_type' => User::class,
            'model_id' => $this->storekeeper->id,
            'business_id' => $this->business->id,
        ]);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function headers(array $extra = []): array
    {
        return ['X-Business-Id' => $this->business->id] + $extra;
    }

    public function test_manager_change_with_own_pin_is_recorded_with_before_and_after(): void
    {
        Sanctum::actingAs($this->manager);

        $this->putJson("/api/branch-products/{$this->bp->id}", [
            'selling_price' => 15,
            'store_quantity' => 8,
        ], $this->headers(['X-Staff-Pin' => '481516']))->assertSuccessful();

        $activity = ManagerActivity::sole();
        $this->assertSame($this->manager->id, $activity->user_id);
        $this->assertSame('Manager', $activity->role);
        $this->assertSame($this->branch->id, $activity->branch_id);
        $this->assertSame('Edited Peak Milk 400g', $activity->description);
        $this->assertTrue($activity->pin_ok);
        $this->assertEquals(10, $activity->changes['selling_price']['from']);
        $this->assertEquals(15, $activity->changes['selling_price']['to']);
        $this->assertEquals(8, $activity->changes['store_quantity']['to']);
    }

    public function test_manager_change_without_pin_is_saved_and_shows_no_pin(): void
    {
        // The PIN is checked by the dashboard and till before saving; the server only records it.
        Sanctum::actingAs($this->manager);

        $this->putJson("/api/branch-products/{$this->bp->id}", ['selling_price' => 20], $this->headers())
            ->assertSuccessful();

        $this->assertEquals(20, (float) $this->bp->fresh()->selling_price);
        $this->assertFalse(ManagerActivity::sole()->pin_ok);
    }

    public function test_someone_elses_pin_is_recorded_as_no_pin(): void
    {
        Sanctum::actingAs($this->manager);

        $this->putJson("/api/branch-products/{$this->bp->id}", ['selling_price' => 20], $this->headers(['X-Staff-Pin' => '271828']))
            ->assertSuccessful();

        $this->assertFalse(ManagerActivity::sole()->pin_ok);
    }

    public function test_pin_check_tells_a_manager_without_a_pin_to_ask_the_owner(): void
    {
        $this->manager->update(['pin_code' => null]);
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/staff-pin/check', ['pin' => '000000'], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'pin_not_set');
    }

    public function test_storekeeper_change_is_recorded_with_pin(): void
    {
        Sanctum::actingAs($this->storekeeper);

        $this->putJson("/api/branch-products/{$this->bp->id}", ['selling_price' => 12], $this->headers(['X-Staff-Pin' => '271828']))
            ->assertSuccessful();

        $activity = ManagerActivity::sole();
        $this->assertSame('Storekeeper', $activity->role);
        $this->assertTrue($activity->pin_ok);
    }

    public function test_too_many_wrong_pins_locks_for_a_minute(): void
    {
        Sanctum::actingAs($this->manager);

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/staff-pin/check', ['pin' => '000000'], $this->headers())->assertStatus(422);
        }

        $this->postJson('/api/staff-pin/check', ['pin' => '481516'], $this->headers())
            ->assertStatus(429)
            ->assertJsonPath('code', 'pin_locked');
    }

    public function test_pin_check_endpoint(): void
    {
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/staff-pin/check', ['pin' => '481516'], $this->headers())->assertOk()->assertJsonPath('ok', true);
        $this->postJson('/api/staff-pin/check', ['pin' => '123123'], $this->headers())->assertStatus(422)->assertJsonPath('code', 'pin_wrong');
    }

    public function test_owner_changes_and_reads_are_not_recorded_or_blocked(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/branch-products/{$this->bp->id}", ['selling_price' => 12], $this->headers())->assertSuccessful();
        $this->getJson('/api/branch-products?branch_id='.$this->branch->id, $this->headers())->assertSuccessful();

        $this->assertSame(0, ManagerActivity::count());
    }

    public function test_owner_can_list_activity_and_manager_cannot(): void
    {
        Sanctum::actingAs($this->manager);
        $this->putJson("/api/branch-products/{$this->bp->id}", ['selling_price' => 15], $this->headers(['X-Staff-Pin' => '481516']))
            ->assertSuccessful();

        $this->getJson('/api/manager-activities', $this->headers())->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/manager-activities?role=Manager', $this->headers())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.user.name', 'Bola Manager')
            ->assertJsonPath('data.0.role', 'Manager')
            ->assertJsonPath('data.0.description', 'Edited Peak Milk 400g')
            ->assertJsonPath('data.0.pin_ok', true);
        $this->getJson('/api/manager-activities?role=Storekeeper', $this->headers())
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }
}
