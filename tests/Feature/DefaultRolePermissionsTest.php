<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SeedsPermissions;

class DefaultRolePermissionsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPermissions;

    private const STOREKEEPER_PERMISSIONS = [
        'view products', 'create products', 'edit products', 'manage branch products',
        'view inventory', 'manage inventory', 'adjust inventory',
        'view categories', 'create categories', 'view batches', 'manage batches',
        'request shelf store move', 'request stock transfer', 'accept stock transfer',
        'write off stock', 'sync data', 'use-pin-login',
    ];

    public function test_new_business_default_roles_grant_sync_data_and_include_storekeeper(): void
    {
        $this->seedAllPermissions();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/businesses', ['name' => 'Corner Store']);

        $response->assertStatus(201);
        $roles = $response->json('data.roles');

        $this->assertContains('sync data', $roles['Cashier']['permissions']);
        $this->assertContains('sync data', $roles['Supervisor']['permissions']);
        $this->assertContains('sync data', $roles['Manager']['permissions']);
        $this->assertArrayHasKey('Storekeeper', $roles);
        $this->assertEqualsCanonicalizing(self::STOREKEEPER_PERMISSIONS, $roles['Storekeeper']['permissions']);
    }

    public function test_cashier_with_default_role_can_pull_sync_changes(): void
    {
        $this->seedAllPermissions();
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $created = $this->postJson('/api/businesses', ['name' => 'Corner Store'])->assertStatus(201);
        $businessId = (int) $created->json('data.business.id');
        $branchId = (int) $created->json('data.branch.id');

        $cashier = User::factory()->create();
        $cashier->businesses()->attach($businessId, ['is_active' => true]);
        DB::table('model_has_roles')->insert([
            'role_id' => $created->json('data.roles.Cashier.id'),
            'model_type' => User::class,
            'model_id' => $cashier->id,
            'business_id' => $businessId,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($cashier);
        $this->postJson('/api/sync/pull', [
            'last_sync_at' => now()->subDay()->toIso8601String(),
            'branch_id' => $branchId,
        ], ['X-Business-Id' => $businessId])->assertOk();
    }

    public function test_migration_backfills_sync_data_and_storekeeper_role_idempotently(): void
    {
        $this->seedAllPermissions();
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $cashierA = Role::create(['name' => 'cashier', 'guard_name' => 'api', 'business_id' => $businessA->id]);
        $cashierA->givePermissionTo('create sales');
        $supervisorA = Role::create(['name' => 'Supervisor', 'guard_name' => 'api', 'business_id' => $businessA->id]);
        $storekeeperA = Role::create(['name' => 'STOREKEEPER', 'guard_name' => 'api', 'business_id' => $businessA->id]);
        $storekeeperA->givePermissionTo('manage inventory');
        $customA = Role::create(['name' => 'Auditor', 'guard_name' => 'api', 'business_id' => $businessA->id]);
        $cashierB = Role::create(['name' => 'Cashier', 'guard_name' => 'api', 'business_id' => $businessB->id]);

        $migration = require database_path('migrations/2026_10_13_100000_grant_sync_data_and_storekeeper_role_permissions.php');
        $migration->up();
        $migration->up();

        foreach ([$cashierA, $supervisorA, $storekeeperA, $cashierB] as $role) {
            $this->assertTrue($role->fresh()->hasPermissionTo('sync data'), "{$role->name} should have sync data");
        }
        $this->assertFalse($customA->fresh()->hasPermissionTo('sync data'));
        $this->assertTrue($cashierA->fresh()->hasPermissionTo('create sales'));

        $this->assertEqualsCanonicalizing(
            self::STOREKEEPER_PERMISSIONS,
            $storekeeperA->fresh()->permissions->pluck('name')->all()
        );
        $this->assertSame(1, Role::where('business_id', $businessA->id)->whereRaw('LOWER(name) = ?', ['storekeeper'])->count());

        $storekeeperB = Role::where('business_id', $businessB->id)->where('name', 'Storekeeper')->first();
        $this->assertNotNull($storekeeperB);
        $this->assertSame('api', $storekeeperB->guard_name);
        $this->assertEqualsCanonicalizing(self::STOREKEEPER_PERMISSIONS, $storekeeperB->permissions->pluck('name')->all());
    }

    public function test_user_with_manage_inventory_can_add_product_to_branch(): void
    {
        $this->seedAllPermissions();
        $business = Business::factory()->create();
        $branch = Branch::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id]);

        $storekeeper = User::factory()->create();
        $storekeeper->businesses()->attach($business->id, ['is_active' => true]);
        $role = Role::create(['name' => 'Storekeeper', 'guard_name' => 'api', 'business_id' => $business->id]);
        $role->givePermissionTo('manage inventory');
        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $storekeeper->id,
            'business_id' => $business->id,
        ]);

        Sanctum::actingAs($storekeeper);
        $this->postJson('/api/branch-products', [
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock_quantity' => 5,
        ], ['X-Business-Id' => $business->id])->assertStatus(201);
    }

    public function test_user_without_branch_product_or_inventory_permission_cannot_add_product_to_branch(): void
    {
        $this->seedAllPermissions();
        $business = Business::factory()->create();
        $branch = Branch::factory()->create(['business_id' => $business->id]);
        $product = Product::factory()->create(['business_id' => $business->id]);

        $cashier = User::factory()->create();
        $cashier->businesses()->attach($business->id, ['is_active' => true]);
        $role = Role::create(['name' => 'Cashier', 'guard_name' => 'api', 'business_id' => $business->id]);
        $role->givePermissionTo('create sales');
        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $cashier->id,
            'business_id' => $business->id,
        ]);

        Sanctum::actingAs($cashier);
        $this->postJson('/api/branch-products', [
            'branch_id' => $branch->id,
            'product_id' => $product->id,
        ], ['X-Business-Id' => $business->id])->assertStatus(403);
    }
}
