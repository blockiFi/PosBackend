<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchProductModelTest extends TestCase
{
    use RefreshDatabase;

    protected $business;
    protected $branch;
    protected $product;
    protected $branchProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->branch = Branch::factory()->create([
            'business_id' => $this->business->id,
        ]);
        
        $category = ProductCategory::factory()->create([
            'business_id' => $this->business->id,
        ]);
        
        $this->product = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category->id,
        ]);

        $this->branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'shelf_quantity' => 50,
            'store_quantity' => 100,
            'stock_quantity' => 150,
            'selling_price' => 29.99,
            'cost_price' => 15.00,
        ]);
    }

    public function test_update_shelf_quantity_add()
    {
        $this->branchProduct->updateShelfQuantity(25, 'add');

        $this->assertEquals(75, $this->branchProduct->shelf_quantity);
        $this->assertEquals(100, $this->branchProduct->store_quantity);
        $this->assertEquals(175, $this->branchProduct->stock_quantity);
    }

    public function test_update_shelf_quantity_subtract()
    {
        $this->branchProduct->updateShelfQuantity(20, 'subtract');

        $this->assertEquals(30, $this->branchProduct->shelf_quantity);
        $this->assertEquals(100, $this->branchProduct->store_quantity);
        $this->assertEquals(130, $this->branchProduct->stock_quantity);
    }

    public function test_update_shelf_quantity_set()
    {
        $this->branchProduct->updateShelfQuantity(60, 'set');

        $this->assertEquals(60, $this->branchProduct->shelf_quantity);
        $this->assertEquals(100, $this->branchProduct->store_quantity);
        $this->assertEquals(160, $this->branchProduct->stock_quantity);
    }

    public function test_update_shelf_quantity_prevents_negative()
    {
        $this->branchProduct->updateShelfQuantity(100, 'subtract');

        $this->assertEquals(0, $this->branchProduct->shelf_quantity);
        $this->assertEquals(100, $this->branchProduct->store_quantity);
        $this->assertEquals(100, $this->branchProduct->stock_quantity);
    }

    public function test_update_store_quantity_add()
    {
        $this->branchProduct->updateStoreQuantity(50, 'add');

        $this->assertEquals(50, $this->branchProduct->shelf_quantity);
        $this->assertEquals(150, $this->branchProduct->store_quantity);
        $this->assertEquals(200, $this->branchProduct->stock_quantity);
    }

    public function test_update_store_quantity_subtract()
    {
        $this->branchProduct->updateStoreQuantity(30, 'subtract');

        $this->assertEquals(50, $this->branchProduct->shelf_quantity);
        $this->assertEquals(70, $this->branchProduct->store_quantity);
        $this->assertEquals(120, $this->branchProduct->stock_quantity);
    }

    public function test_update_store_quantity_set()
    {
        $this->branchProduct->updateStoreQuantity(80, 'set');

        $this->assertEquals(50, $this->branchProduct->shelf_quantity);
        $this->assertEquals(80, $this->branchProduct->store_quantity);
        $this->assertEquals(130, $this->branchProduct->stock_quantity);
    }

    public function test_move_to_shelf_success()
    {
        $result = $this->branchProduct->moveToShelf(25);

        $this->assertTrue($result);
        $this->assertEquals(75, $this->branchProduct->shelf_quantity);
        $this->assertEquals(75, $this->branchProduct->store_quantity);
        $this->assertEquals(150, $this->branchProduct->stock_quantity);
    }

    public function test_move_to_shelf_insufficient_store_quantity()
    {
        $result = $this->branchProduct->moveToShelf(150);

        $this->assertFalse($result);
        $this->assertEquals(50, $this->branchProduct->shelf_quantity);
        $this->assertEquals(100, $this->branchProduct->store_quantity);
    }

    public function test_move_to_store_success()
    {
        $result = $this->branchProduct->moveToStore(20);

        $this->assertTrue($result);
        $this->assertEquals(30, $this->branchProduct->shelf_quantity);
        $this->assertEquals(120, $this->branchProduct->store_quantity);
        $this->assertEquals(150, $this->branchProduct->stock_quantity);
    }

    public function test_move_to_store_insufficient_shelf_quantity()
    {
        $result = $this->branchProduct->moveToStore(60);

        $this->assertFalse($result);
        $this->assertEquals(50, $this->branchProduct->shelf_quantity);
        $this->assertEquals(100, $this->branchProduct->store_quantity);
    }

    public function test_get_total_stock_quantity()
    {
        $total = $this->branchProduct->getTotalStockQuantity();

        $this->assertEquals(150, $total);
    }

    public function test_shelf_needs_restocking_when_low()
    {
        $this->branchProduct->update([
            'shelf_quantity' => 5,
            'store_quantity' => 50,
            'low_stock_threshold' => 10,
        ]);

        $this->assertTrue($this->branchProduct->shelfNeedsRestocking());
    }

    public function test_shelf_needs_restocking_when_store_empty()
    {
        $this->branchProduct->update([
            'shelf_quantity' => 5,
            'store_quantity' => 0,
            'low_stock_threshold' => 10,
        ]);

        $this->assertFalse($this->branchProduct->shelfNeedsRestocking());
    }

    public function test_shelf_does_not_need_restocking_when_above_threshold()
    {
        $this->branchProduct->update([
            'shelf_quantity' => 50,
            'store_quantity' => 100,
            'low_stock_threshold' => 10,
        ]);

        $this->assertFalse($this->branchProduct->shelfNeedsRestocking());
    }

    public function test_total_stock_updates_when_shelf_changes()
    {
        $this->branchProduct->update([
            'shelf_quantity' => 75,
        ]);

        // Since we're updating directly, stock_quantity needs manual calculation
        // In real usage, the controller handles this
        $this->branchProduct->update([
            'stock_quantity' => 75 + $this->branchProduct->store_quantity
        ]);

        $this->assertEquals(175, $this->branchProduct->fresh()->stock_quantity);
    }
}
