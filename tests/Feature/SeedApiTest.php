<?php

namespace Tests\Feature;

use App\Jobs\ProcessSeedImport;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Product;
use App\Models\SeedImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

    private function seedPost(array $data, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->post('/api/seed', $data, array_merge([
                'X-Business-Id' => $this->business->id,
                'Accept' => 'application/json',
            ], $headers));
    }

    /**
     * Dispatch the job synchronously so the import actually runs.
     * Uses Queue::fake() during the POST so the sync driver doesn't
     * execute (and delete) the file before we can inspect it.
     */
    private function dispatchSeedSync(array $data): SeedImport
    {
        Queue::fake();
        $response = $this->seedPost($data);
        $response->assertStatus(202);

        $import = SeedImport::find($response->json('id'));

        Queue::assertPushed(ProcessSeedImport::class);

        // Run the job manually now that the file is safely on disk
        (new ProcessSeedImport($import))->handle(app(\App\Services\SeedFromFileService::class));
        $import->refresh();

        return $import;
    }

    // -------------------------------------------------------
    // Auth & Validation
    // -------------------------------------------------------

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

    public function test_seed_validates_entity_and_mapping(): void
    {
        setPermissionsTeamId($this->business->id);
        $csv = "ItemID,ItemDescription\n123,Test";
        $file = UploadedFile::fake()->createWithContent('seed.csv', $csv);

        $response = $this->seedPost([
            'file' => $file,
            'entity' => 'invalid_entity',
            'mapping' => ['ItemID' => 'barcode', 'ItemDescription' => 'name'],
            'unique_key' => 'barcode',
            'branch_id' => $this->branch->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_seed_requires_branch_id(): void
    {
        setPermissionsTeamId($this->business->id);
        $csv = "ItemID,ItemDescription\n123,Test";
        $file = UploadedFile::fake()->createWithContent('seed.csv', $csv);

        $response = $this->seedPost([
            'file' => $file,
            'entity' => 'products',
            'mapping' => ['ItemID' => 'barcode', 'ItemDescription' => 'name'],
            'unique_key' => 'barcode',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('branch_id');
    }

    // -------------------------------------------------------
    // Queued Dispatch
    // -------------------------------------------------------

    public function test_seed_returns_202_and_dispatches_job(): void
    {
        Queue::fake();
        setPermissionsTeamId($this->business->id);
        $csv = "ItemID,ItemDescription,SupplyPrice\n123456,Test Product,10.50";
        $file = $this->createCsvUpload($csv);

        $response = $this->seedPost([
            'file' => $file,
            'entity' => 'products',
            'mapping' => ['ItemID' => 'barcode', 'ItemDescription' => 'name', 'SupplyPrice' => 'base_cost_price'],
            'unique_key' => 'barcode',
            'branch_id' => $this->branch->id,
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['message', 'id', 'uuid', 'status']);
        $response->assertJsonPath('status', 'pending');

        Queue::assertPushed(ProcessSeedImport::class, function (ProcessSeedImport $job) use ($response) {
            return $job->seedImport->id === $response->json('id');
        });

        $this->assertDatabaseHas('seed_imports', [
            'id' => $response->json('id'),
            'status' => 'pending',
            'entity' => 'products',
            'business_id' => $this->business->id,
        ]);
    }

    // -------------------------------------------------------
    // Status Endpoint
    // -------------------------------------------------------

    public function test_status_endpoint_returns_import_progress(): void
    {
        setPermissionsTeamId($this->business->id);
        $csv = "ItemID,ItemDescription,SupplyPrice\n123456,Test Product One,10.50\n789012,Test Product Two,20.00";
        $file = $this->createCsvUpload($csv);

        $import = $this->dispatchSeedSync([
            'file' => $file,
            'entity' => 'products',
            'mapping' => ['ItemID' => 'barcode', 'ItemDescription' => 'name', 'SupplyPrice' => 'base_cost_price'],
            'unique_key' => 'barcode',
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/seed/{$import->id}/status", [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'id', 'uuid', 'status', 'entity', 'total_rows',
            'created', 'updated', 'deleted', 'failed', 'errors',
            'started_at', 'completed_at',
        ]);
        $response->assertJsonPath('status', 'completed');
        $response->assertJsonPath('created', 2);
    }

    public function test_status_returns_not_found_for_wrong_business(): void
    {
        setPermissionsTeamId($this->business->id);
        $csv = "ItemID,ItemDescription,SupplyPrice\n123,Test,10.00";
        $file = $this->createCsvUpload($csv);

        $import = $this->dispatchSeedSync([
            'file' => $file,
            'entity' => 'products',
            'mapping' => ['ItemID' => 'barcode', 'ItemDescription' => 'name', 'SupplyPrice' => 'base_cost_price'],
            'unique_key' => 'barcode',
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/seed/{$import->id}/status", [
                'X-Business-Id' => 99999,
            ]);

        $this->assertTrue(
            in_array($response->status(), [403, 404]),
            "Expected 403 or 404, got {$response->status()}"
        );
    }

    // -------------------------------------------------------
    // Full Processing (synchronous dispatch in tests)
    // -------------------------------------------------------

    public function test_seed_products_from_csv_creates_products(): void
    {
        setPermissionsTeamId($this->business->id);
        $csv = "ItemID,ItemDescription,SupplyPrice\n123456,Test Product One,10.50\n789012,Test Product Two,20.00";
        $file = $this->createCsvUpload($csv);

        $import = $this->dispatchSeedSync([
            'file' => $file,
            'entity' => 'products',
            'mapping' => ['ItemID' => 'barcode', 'ItemDescription' => 'name', 'SupplyPrice' => 'base_cost_price'],
            'unique_key' => 'barcode',
            'branch_id' => $this->branch->id,
        ]);

        $this->assertSame('completed', $import->status);
        $this->assertSame(2, $import->created);
        $this->assertSame(0, $import->failed);

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
        $file = $this->createCsvUpload($csv);

        $import = $this->dispatchSeedSync([
            'file' => $file,
            'entity' => 'products',
            'mapping' => ['ItemID' => 'barcode', 'ItemDescription' => 'name', 'SupplyPrice' => 'base_cost_price'],
            'unique_key' => 'barcode',
            'branch_id' => $this->branch->id,
        ]);

        $this->assertSame('completed', $import->status);
        $this->assertSame(0, $import->created);
        $this->assertSame(1, $import->updated);

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

        $import = $this->dispatchSeedSync([
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
        ]);

        $this->assertSame('completed', $import->status);
        $this->assertSame(1, $import->created);

        $product = Product::where('business_id', $this->business->id)->where('barcode', '111')->first();
        $this->assertNotNull($product);

        $bp = $product->branchProducts()->where('branch_id', $this->branch->id)->first();
        $this->assertNotNull($bp);
        $this->assertSame(10, (int) $bp->stock_quantity);
        $this->assertSame(10, (int) $bp->shelf_quantity);
    }

    public function test_seed_computes_selling_price_from_retail_value(): void
    {
        setPermissionsTeamId($this->business->id);
        $csv = "ItemID,ItemDescription,SupplyPrice,RetailValue,Stock\nRV1,Retail Item,5.00,100.00,10";
        $file = $this->createCsvUpload($csv);

        $import = $this->dispatchSeedSync([
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
        ]);

        $this->assertSame('completed', $import->status);
        $this->assertSame(1, $import->created);

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

        $import = $this->dispatchSeedSync([
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
        ]);

        $this->assertSame('completed', $import->status);

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

        $import = $this->dispatchSeedSync([
            'file' => $file,
            'entity' => 'products',
            'mapping' => ['ItemID' => 'barcode'],
            'unique_key' => 'barcode',
            'branch_id' => $this->branch->id,
            'delete' => true,
        ]);

        $this->assertSame('completed', $import->status);
        $this->assertSame(1, $import->deleted);
        $this->assertSame(0, $import->created);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('branch_products', ['product_id' => $product->id]);
    }

    public function test_seed_delete_with_nonexistent_product_returns_zero_deleted(): void
    {
        setPermissionsTeamId($this->business->id);
        $csv = "ItemID\nNONEXIST";
        $file = $this->createCsvUpload($csv);

        $import = $this->dispatchSeedSync([
            'file' => $file,
            'entity' => 'products',
            'mapping' => ['ItemID' => 'barcode'],
            'unique_key' => 'barcode',
            'branch_id' => $this->branch->id,
            'delete' => true,
        ]);

        $this->assertSame('completed', $import->status);
        $this->assertSame(0, $import->deleted);
    }
}
