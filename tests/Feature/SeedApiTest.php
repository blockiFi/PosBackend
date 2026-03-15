<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeedApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    private Branch $branch;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->business = Business::create([
            'name' => 'Test Business',
            'email' => 'business@test.com',
            'owner_id' => $this->user->id,
        ]);
        $this->branch = Branch::create([
            'business_id' => $this->business->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'address' => '123 Main St',
        ]);
        $this->user->businesses()->attach($this->business->id, ['is_active' => true]);

        $this->role = Role::create([
            'name' => 'Manager',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        Permission::firstOrCreate(['name' => 'create products', 'guard_name' => 'api']);
        $this->role->givePermissionTo('create products');
        DB::table('model_has_roles')->insert([
            'role_id' => $this->role->id,
            'model_type' => User::class,
            'model_id' => $this->user->id,
            'business_id' => $this->business->id,
        ]);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function createCsvUpload(string $csvContent): UploadedFile
    {
        $tmp = storage_path('app/seed_test_'.uniqid().'.csv');
        file_put_contents($tmp, $csvContent);

        return new UploadedFile($tmp, 'seed.csv', 'text/csv', \UPLOAD_ERR_OK, true);
    }

    public function test_seed_requires_authentication(): void
    {
        $csv = "ItemID,ItemDescription,SupplyPrice\n123,Test Product,10.00";
        $file = UploadedFile::fake()->createWithContent('seed.csv', $csv);

        $response = $this->postJson('/api/seed', [
            'file' => $file,
            'entity' => 'products',
            'mapping' => ['ItemID' => 'barcode', 'ItemDescription' => 'name', 'SupplyPrice' => 'base_cost_price'],
            'unique_key' => 'barcode',
            'branch_id' => 1,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(401);
    }

    public function test_seed_requires_business_context(): void
    {
        setPermissionsTeamId($this->business->id);
        $csv = "ItemID,ItemDescription,SupplyPrice\n123,Test Product,10.00";
        $file = UploadedFile::fake()->createWithContent('seed.csv', $csv);

        $response = $this->actingAs($this->user, 'sanctum')
            ->post('/api/seed', [
                'file' => $file,
                'entity' => 'products',
                'mapping' => ['ItemID' => 'barcode', 'ItemDescription' => 'name', 'SupplyPrice' => 'base_cost_price'],
                'unique_key' => 'barcode',
                'branch_id' => $this->branch->id,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'Business context is required');
    }

    public function test_seed_products_from_csv_creates_products(): void
    {
        setPermissionsTeamId($this->business->id);
        $csv = "ItemID,ItemDescription,SupplyPrice\n123456,Test Product One,10.50\n789012,Test Product Two,20.00";
        $file = $this->createCsvUpload($csv);

        $response = $this->actingAs($this->user, 'sanctum')
            ->post('/api/seed', [
                'file' => $file,
                'entity' => 'products',
                'mapping' => [
                    'ItemID' => 'barcode',
                    'ItemDescription' => 'name',
                    'SupplyPrice' => 'base_cost_price',
                ],
                'unique_key' => 'barcode',
                'branch_id' => $this->branch->id,
            ], [
                'X-Business-Id' => $this->business->id,
                'Accept' => 'application/json',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('created', 2);
        $response->assertJsonPath('updated', 0);
        $response->assertJsonPath('failed', 0);

        $this->assertDatabaseHas('products', [
            'business_id' => $this->business->id,
            'barcode' => '123456',
            'name' => 'Test Product One',
        ]);
        $this->assertDatabaseHas('products', [
            'business_id' => $this->business->id,
            'barcode' => '789012',
            'name' => 'Test Product Two',
        ]);
    }

    public function test_seed_products_upserts_by_unique_key(): void
    {
        setPermissionsTeamId($this->business->id);
        Product::create([
            'business_id' => $this->business->id,
            'name' => 'Old Name',
            'sku' => 'OLD-SKU',
            'barcode' => '999',
            'base_cost_price' => 1,
            'base_selling_price' => 2,
        ]);

        $csv = "ItemID,ItemDescription,SupplyPrice\n999,Updated Name,15.00";
        $file = UploadedFile::fake()->createWithContent('seed.csv', $csv);

        $response = $this->actingAs($this->user, 'sanctum')
            ->post('/api/seed', [
                'file' => $file,
                'entity' => 'products',
                'mapping' => [
                    'ItemID' => 'barcode',
                    'ItemDescription' => 'name',
                    'SupplyPrice' => 'base_cost_price',
                ],
                'unique_key' => 'barcode',
                'branch_id' => $this->branch->id,
            ], [
                'X-Business-Id' => $this->business->id,
                'Accept' => 'application/json',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('created', 0);
        $response->assertJsonPath('updated', 1);

        $this->assertDatabaseHas('products', [
            'business_id' => $this->business->id,
            'barcode' => '999',
            'name' => 'Updated Name',
        ]);
    }

    public function test_seed_products_creates_branch_products_with_shelf_quantity(): void
    {
        setPermissionsTeamId($this->business->id);
        $csv = "ItemID,ItemDescription,SupplyPrice,Stock\n111,Branch Product,5.00,10";
        $file = $this->createCsvUpload($csv);

        $response = $this->actingAs($this->user, 'sanctum')
            ->post('/api/seed', [
                'file' => $file,
                'entity' => 'products',
                'mapping' => [
                    'ItemID' => 'barcode',
                    'ItemDescription' => 'name',
                    'SupplyPrice' => 'base_cost_price',
                    'Stock' => 'stock_quantity',
                ],
                'unique_key' => 'barcode',
                'branch_id' => $this->branch->id,
            ], [
                'X-Business-Id' => $this->business->id,
                'Accept' => 'application/json',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('created', 1);

        $product = Product::where('business_id', $this->business->id)->where('barcode', '111')->first();
        $this->assertNotNull($product);

        $bp = $product->branchProducts()->where('branch_id', $this->branch->id)->first();
        $this->assertNotNull($bp);
        $this->assertSame(10, (int) $bp->stock_quantity);
        $this->assertSame(10, (int) $bp->shelf_quantity);
    }

    public function test_seed_validates_entity_and_mapping(): void
    {
        setPermissionsTeamId($this->business->id);
        $csv = "ItemID,ItemDescription\n123,Test";
        $file = UploadedFile::fake()->createWithContent('seed.csv', $csv);

        $response = $this->actingAs($this->user, 'sanctum')
            ->post('/api/seed', [
                'file' => $file,
                'entity' => 'invalid_entity',
                'mapping' => ['ItemID' => 'barcode', 'ItemDescription' => 'name'],
                'unique_key' => 'barcode',
                'branch_id' => $this->branch->id,
            ], [
                'X-Business-Id' => $this->business->id,
                'Accept' => 'application/json',
            ]);

        $response->assertStatus(422);
    }

    public function test_seed_requires_branch_id(): void
    {
        setPermissionsTeamId($this->business->id);
        $csv = "ItemID,ItemDescription\n123,Test";
        $file = UploadedFile::fake()->createWithContent('seed.csv', $csv);

        $response = $this->actingAs($this->user, 'sanctum')
            ->post('/api/seed', [
                'file' => $file,
                'entity' => 'products',
                'mapping' => ['ItemID' => 'barcode', 'ItemDescription' => 'name'],
                'unique_key' => 'barcode',
            ], [
                'X-Business-Id' => $this->business->id,
                'Accept' => 'application/json',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('branch_id');
    }

    public function test_seed_computes_selling_price_from_retail_value(): void
    {
        setPermissionsTeamId($this->business->id);
        $csv = "ItemID,ItemDescription,SupplyPrice,RetailValue,Stock\nRV1,Retail Item,5.00,100.00,10";
        $file = $this->createCsvUpload($csv);

        $response = $this->actingAs($this->user, 'sanctum')
            ->post('/api/seed', [
                'file' => $file,
                'entity' => 'products',
                'mapping' => [
                    'ItemID' => 'barcode',
                    'ItemDescription' => 'name',
                    'SupplyPrice' => 'base_cost_price',
                    'RetailValue' => 'retail_value',
                    'Stock' => 'stock_quantity',
                ],
                'unique_key' => 'barcode',
                'branch_id' => $this->branch->id,
            ], [
                'X-Business-Id' => $this->business->id,
                'Accept' => 'application/json',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('created', 1);

        $product = Product::where('business_id', $this->business->id)->where('barcode', 'RV1')->first();
        $this->assertNotNull($product);
        $this->assertEquals(10.00, (float) $product->base_selling_price);

        $bp = $product->branchProducts()->where('branch_id', $this->branch->id)->first();
        $this->assertNotNull($bp);
        $this->assertEquals(10.00, (float) $bp->selling_price);
        $this->assertSame(10, (int) $bp->stock_quantity);
        $this->assertSame(10, (int) $bp->shelf_quantity);
    }

    public function test_seed_retail_value_skipped_when_stock_is_zero(): void
    {
        setPermissionsTeamId($this->business->id);
        $csv = "ItemID,ItemDescription,SupplyPrice,RetailValue,Stock\nRV0,Zero Stock,8.00,100.00,0";
        $file = $this->createCsvUpload($csv);

        $response = $this->actingAs($this->user, 'sanctum')
            ->post('/api/seed', [
                'file' => $file,
                'entity' => 'products',
                'mapping' => [
                    'ItemID' => 'barcode',
                    'ItemDescription' => 'name',
                    'SupplyPrice' => 'base_cost_price',
                    'RetailValue' => 'retail_value',
                    'Stock' => 'stock_quantity',
                ],
                'unique_key' => 'barcode',
                'branch_id' => $this->branch->id,
            ], [
                'X-Business-Id' => $this->business->id,
                'Accept' => 'application/json',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('created', 1);

        $product = Product::where('business_id', $this->business->id)->where('barcode', 'RV0')->first();
        $this->assertNotNull($product);
        $this->assertEquals(8.00, (float) $product->base_selling_price);
    }

    public function test_seed_delete_flag_removes_products(): void
    {
        setPermissionsTeamId($this->business->id);
        $product = Product::create([
            'business_id' => $this->business->id,
            'name' => 'To Delete',
            'barcode' => 'DEL1',
            'base_cost_price' => 5,
            'base_selling_price' => 10,
        ]);
        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'cost_price' => 5,
            'selling_price' => 10,
            'stock_quantity' => 5,
            'shelf_quantity' => 5,
            'store_quantity' => 0,
            'is_available' => true,
        ]);

        $csv = "ItemID\nDEL1";
        $file = $this->createCsvUpload($csv);

        $response = $this->actingAs($this->user, 'sanctum')
            ->post('/api/seed', [
                'file' => $file,
                'entity' => 'products',
                'mapping' => ['ItemID' => 'barcode'],
                'unique_key' => 'barcode',
                'branch_id' => $this->branch->id,
                'delete' => true,
            ], [
                'X-Business-Id' => $this->business->id,
                'Accept' => 'application/json',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('deleted', 1);
        $response->assertJsonPath('created', 0);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('branch_products', ['product_id' => $product->id]);
    }

    public function test_seed_delete_with_nonexistent_product_returns_zero_deleted(): void
    {
        setPermissionsTeamId($this->business->id);
        $csv = "ItemID\nNONEXIST";
        $file = $this->createCsvUpload($csv);

        $response = $this->actingAs($this->user, 'sanctum')
            ->post('/api/seed', [
                'file' => $file,
                'entity' => 'products',
                'mapping' => ['ItemID' => 'barcode'],
                'unique_key' => 'barcode',
                'branch_id' => $this->branch->id,
                'delete' => true,
            ], [
                'X-Business-Id' => $this->business->id,
                'Accept' => 'application/json',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('deleted', 0);
    }
}
