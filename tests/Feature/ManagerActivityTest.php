<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchAuthorization;
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

        $this->manager = User::factory()->create(['name' => 'Bola Manager']);
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
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function ownerCode(): string
    {
        BranchAuthorization::create([
            'user_id' => $this->owner->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'auth_code' => '123456',
            'expires_at' => now()->addMinutes(15),
        ]);

        return '123456';
    }

    public function test_manager_change_with_owner_code_is_recorded_with_before_and_after(): void
    {
        Sanctum::actingAs($this->manager);

        $this->putJson("/api/branch-products/{$this->bp->id}", [
            'selling_price' => 15,
            'store_quantity' => 8,
        ], ['X-Business-Id' => $this->business->id, 'X-Owner-Code' => $this->ownerCode()])->assertSuccessful();

        $activity = ManagerActivity::sole();
        $this->assertSame($this->manager->id, $activity->user_id);
        $this->assertSame($this->branch->id, $activity->branch_id);
        $this->assertSame('Edited Peak Milk 400g', $activity->description);
        $this->assertTrue($activity->owner_code_ok);
        $this->assertEquals(10, $activity->changes['selling_price']['from']);
        $this->assertEquals(15, $activity->changes['selling_price']['to']);
        $this->assertEquals(8, $activity->changes['store_quantity']['to']);
    }

    public function test_manager_change_without_code_is_still_recorded_and_flagged(): void
    {
        Sanctum::actingAs($this->manager);

        $this->putJson("/api/branch-products/{$this->bp->id}", ['selling_price' => 20], [
            'X-Business-Id' => $this->business->id,
        ])->assertSuccessful();

        $this->assertFalse(ManagerActivity::sole()->owner_code_ok);
    }

    public function test_code_made_by_someone_other_than_the_owner_does_not_count(): void
    {
        BranchAuthorization::create([
            'user_id' => $this->manager->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'auth_code' => '654321',
            'expires_at' => now()->addMinutes(15),
        ]);
        Sanctum::actingAs($this->manager);

        $this->putJson("/api/branch-products/{$this->bp->id}", ['selling_price' => 20], [
            'X-Business-Id' => $this->business->id,
            'X-Owner-Code' => '654321',
        ])->assertSuccessful();

        $this->assertFalse(ManagerActivity::sole()->owner_code_ok);
    }

    public function test_owner_changes_and_reads_are_not_recorded(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/branch-products/{$this->bp->id}", ['selling_price' => 12], [
            'X-Business-Id' => $this->business->id,
        ])->assertSuccessful();
        $this->getJson('/api/branch-products?branch_id='.$this->branch->id, [
            'X-Business-Id' => $this->business->id,
        ])->assertSuccessful();

        $this->assertSame(0, ManagerActivity::count());
    }

    public function test_owner_can_list_activity_and_manager_cannot(): void
    {
        Sanctum::actingAs($this->manager);
        $this->putJson("/api/branch-products/{$this->bp->id}", ['selling_price' => 15], [
            'X-Business-Id' => $this->business->id,
            'X-Owner-Code' => $this->ownerCode(),
        ])->assertSuccessful();

        $this->getJson('/api/manager-activities', ['X-Business-Id' => $this->business->id])->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/manager-activities', ['X-Business-Id' => $this->business->id])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.user.name', 'Bola Manager')
            ->assertJsonPath('data.0.description', 'Edited Peak Milk 400g')
            ->assertJsonPath('data.0.owner_code_ok', true);
    }
}
