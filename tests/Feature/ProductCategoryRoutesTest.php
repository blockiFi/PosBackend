<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductCategoryRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        // Create user and business
        $this->user = User::factory()->create();
        $this->business = Business::create([
            'name' => 'Test Business',
            'email' => 'business@test.com',
            'owner_id' => $this->user->id,
        ]);

        // Attach user to business
        $this->user->businesses()->attach($this->business->id, [
            'is_active' => true,
        ]);

        // Create role with permissions
        $this->role = Role::create([
            'name' => 'Category Manager',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);

        // Create permissions
        $permissions = [
            'view categories',
            'create categories',
            'edit categories',
            'delete categories',
        ];

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate(
                ['name' => $permissionName, 'guard_name' => 'api']
            );
        }

        // Assign role to user with business_id
        DB::table('model_has_roles')->insert([
            'role_id' => $this->role->id,
            'model_type' => User::class,
            'model_id' => $this->user->id,
            'business_id' => $this->business->id,
        ]);
    }

    public function test_can_list_categories_with_permission(): void
    {
        $this->role->givePermissionTo('view categories');

        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Set permissions team context before request
        setPermissionsTeamId($this->business->id);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/categories?current_business_id='.$this->business->id);

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_cannot_list_categories_without_permission(): void
    {
        $unprivilegedUser = User::factory()->create();
        $unprivilegedUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $response = $this->actingAs($unprivilegedUser, 'sanctum')
            ->getJson('/api/categories?current_business_id='.$this->business->id);

        $response->assertStatus(403);
    }

    public function test_can_create_category_with_permission(): void
    {
        $this->role->givePermissionTo('create categories');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $data = [
            'name' => 'Electronics',
            'description' => 'Electronic devices and accessories',
            'is_active' => true,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/categories?current_business_id='.$this->business->id, $data);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'description',
                ],
            ]);

        $this->assertDatabaseHas('product_categories', [
            'business_id' => $this->business->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);
    }

    public function test_cannot_create_category_without_permission(): void
    {
        $unprivilegedUser = User::factory()->create();
        $unprivilegedUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $data = [
            'name' => 'Electronics',
        ];

        $response = $this->actingAs($unprivilegedUser, 'sanctum')
            ->postJson('/api/categories?current_business_id='.$this->business->id, $data);

        $response->assertStatus(403);
    }

    public function test_can_create_nested_category(): void
    {
        $this->role->givePermissionTo('create categories');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        // Create parent category
        $parent = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        // Create child category
        $data = [
            'name' => 'Computers',
            'parent_id' => $parent->id,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/categories?current_business_id='.$this->business->id, $data);

        $response->assertStatus(201);

        $this->assertDatabaseHas('product_categories', [
            'business_id' => $this->business->id,
            'name' => 'Computers',
            'parent_id' => $parent->id,
        ]);
    }

    public function test_auto_generates_slug_from_name(): void
    {
        $this->role->givePermissionTo('create categories');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $data = [
            'name' => 'Home & Garden',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/categories?current_business_id='.$this->business->id, $data);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'slug' => 'home-garden',
                ],
            ]);
    }

    public function test_can_view_category_with_permission(): void
    {
        $this->role->givePermissionTo('view categories');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/categories/'.$category->id.'?current_business_id='.$this->business->id);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $category->id,
                    'name' => 'Electronics',
                ],
            ]);
    }

    public function test_can_update_category_with_permission(): void
    {
        $this->role->givePermissionTo('edit categories');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        $data = [
            'name' => 'Electronic Devices',
            'description' => 'Updated description',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/categories/'.$category->id.'?current_business_id='.$this->business->id, $data);

        $response->assertStatus(200);

        $this->assertDatabaseHas('product_categories', [
            'id' => $category->id,
            'name' => 'Electronic Devices',
            'description' => 'Updated description',
        ]);
    }

    public function test_cannot_update_category_without_permission(): void
    {
        $unprivilegedUser = User::factory()->create();
        $unprivilegedUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        $data = ['name' => 'Updated Name'];

        $response = $this->actingAs($unprivilegedUser, 'sanctum')
            ->putJson('/api/categories/'.$category->id.'?current_business_id='.$this->business->id, $data);

        $response->assertStatus(403);
    }

    public function test_prevents_circular_reference_on_update(): void
    {
        $this->role->givePermissionTo('edit categories');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $parent = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Parent',
            'slug' => 'parent',
        ]);

        $child = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Child',
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        // Try to make parent a child of its own child
        $data = ['parent_id' => $child->id];

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/categories/'.$parent->id.'?current_business_id='.$this->business->id, $data);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Cannot set a descendant as parent (circular reference)',
            ]);
    }

    public function test_can_delete_category_with_permission(): void
    {
        $this->role->givePermissionTo('delete categories');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/categories/'.$category->id.'?current_business_id='.$this->business->id);

        $response->assertStatus(200);

        $this->assertSoftDeleted('product_categories', [
            'id' => $category->id,
        ]);
    }

    public function test_cannot_delete_category_without_permission(): void
    {
        $unprivilegedUser = User::factory()->create();
        $unprivilegedUser->businesses()->attach($this->business->id, ['is_active' => true]);

        $category = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        $response = $this->actingAs($unprivilegedUser, 'sanctum')
            ->deleteJson('/api/categories/'.$category->id.'?current_business_id='.$this->business->id);

        $response->assertStatus(403);
    }

    public function test_cannot_delete_category_with_children(): void
    {
        $this->role->givePermissionTo('delete categories');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $parent = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Parent',
            'slug' => 'parent',
        ]);

        ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Child',
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/categories/'.$parent->id.'?current_business_id='.$this->business->id);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Cannot delete category with subcategories. Please delete subcategories first.',
            ]);
    }

    public function test_can_get_breadcrumb_trail(): void
    {
        $this->role->givePermissionTo('view categories');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $grandparent = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        $parent = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Computers',
            'slug' => 'computers',
            'parent_id' => $grandparent->id,
        ]);

        $child = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Laptops',
            'slug' => 'laptops',
            'parent_id' => $parent->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/categories/'.$child->id.'/breadcrumb?current_business_id='.$this->business->id);

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJson([
                'data' => [
                    'Electronics',
                    'Computers',
                    'Laptops',
                ],
            ]);
    }

    public function test_can_list_hierarchical_categories(): void
    {
        $this->role->givePermissionTo('view categories');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        $parent = ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Computers',
            'slug' => 'computers',
            'parent_id' => $parent->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/categories?current_business_id='.$this->business->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'children',
                    ],
                ],
            ]);
    }

    public function test_can_list_flat_categories(): void
    {
        $this->role->givePermissionTo('view categories');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        ProductCategory::create([
            'business_id' => $this->business->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/categories?flat=true&current_business_id='.$this->business->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'depth',
                        'has_children',
                    ],
                ],
            ]);
    }

    public function test_requires_business_context(): void
    {
        $this->role->givePermissionTo('view categories');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/categories');

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Business context is required',
            ]);
    }

    public function test_cannot_access_other_business_categories(): void
    {
        $this->role->givePermissionTo('view categories');
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId($this->business->id);

        // Create another business
        $otherBusiness = Business::create([
            'name' => 'Other Business',
            'email' => 'other@test.com',
            'owner_id' => User::factory()->create()->id,
        ]);

        $category = ProductCategory::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Category',
            'slug' => 'other-category',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/categories/'.$category->id.'?current_business_id='.$this->business->id);

        $response->assertStatus(404);
    }
}
