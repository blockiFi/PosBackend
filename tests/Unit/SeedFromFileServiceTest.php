<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Business;
use App\Services\SeedFromFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SeedFromFileServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_csv_and_creates_products(): void
    {
        $business = Business::factory()->create();
        $branch = Branch::create([
            'business_id' => $business->id,
            'name' => 'Test Branch',
            'code' => 'TST',
            'address' => '1 Test St',
        ]);
        $csv = "ItemID,ItemDescription,SupplyPrice\n123456,Test Product One,10.50\n789012,Test Product Two,20.00";
        $tmp = storage_path('app/seed_test_'.uniqid().'.csv');
        file_put_contents($tmp, $csv);
        $this->assertFileExists($tmp);
        $file = new UploadedFile($tmp, 'seed.csv', 'text/csv', \UPLOAD_ERR_OK, true);

        $service = app(SeedFromFileService::class);
        $result = $service->run(
            $file,
            'products',
            [
                'ItemID' => 'barcode',
                'ItemDescription' => 'name',
                'SupplyPrice' => 'base_cost_price',
            ],
            'barcode',
            $business->id,
            $branch->id
        );

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['failed']);
        $this->assertDatabaseHas('products', [
            'business_id' => $business->id,
            'barcode' => '123456',
            'name' => 'Test Product One',
        ]);
        $this->assertDatabaseHas('products', [
            'business_id' => $business->id,
            'barcode' => '789012',
            'name' => 'Test Product Two',
        ]);
        if (file_exists($tmp)) {
            @unlink($tmp);
        }
    }
}
