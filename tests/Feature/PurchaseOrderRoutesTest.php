<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Business $business;

    private Branch $branch;

    private BranchProduct $rice;

    private BranchProduct $beans;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->business = Business::create(['name' => 'PO Business', 'owner_id' => $this->owner->id]);
        $this->owner->businesses()->attach($this->business->id, ['is_active' => true]);
        $this->branch = Branch::create(['name' => 'Main', 'code' => 'MAIN', 'business_id' => $this->business->id]);

        $this->rice = $this->branchProduct('Rice');
        $this->beans = $this->branchProduct('Beans');
    }

    public function test_supplier_store_returns_the_full_row_including_defaults(): void
    {
        $this->postJson('/api/suppliers', ['name' => 'Acme Foods'], $this->headers())
            ->assertCreated()
            ->assertJsonPath('supplier.name', 'Acme Foods')
            ->assertJsonPath('supplier.is_active', true)
            ->assertJsonPath('supplier.business_id', $this->business->id);
    }

    public function test_update_replaces_lines_on_a_draft_purchase_order(): void
    {
        $po = $this->createPurchaseOrder();

        $response = $this->putJson("/api/purchase-orders/{$po->id}", [
            'notes' => 'Revised',
            'lines' => [
                ['product_id' => $this->beans->product_id, 'branch_product_id' => $this->beans->id, 'quantity_ordered' => 6, 'unit_cost' => 40],
            ],
        ], $this->headers());

        $response->assertOk()
            ->assertJsonPath('purchase_order.notes', 'Revised')
            ->assertJsonCount(1, 'purchase_order.lines')
            ->assertJsonPath('purchase_order.lines.0.branch_product_id', $this->beans->id);
        $this->assertEquals(6, (float) $response->json('purchase_order.lines.0.quantity_ordered'));
        $this->assertFalse($po->lines()->where('branch_product_id', $this->rice->id)->exists());
    }

    public function test_update_without_lines_keeps_existing_lines(): void
    {
        $po = $this->createPurchaseOrder();

        $this->putJson("/api/purchase-orders/{$po->id}", ['notes' => 'Header only'], $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'purchase_order.lines')
            ->assertJsonPath('purchase_order.lines.0.branch_product_id', $this->rice->id);
    }

    public function test_update_rejects_a_branch_product_from_another_branch(): void
    {
        $po = $this->createPurchaseOrder();
        $otherBranch = Branch::create(['name' => 'Other', 'code' => 'OTH', 'business_id' => $this->business->id]);
        $foreign = BranchProduct::factory()->create(['branch_id' => $otherBranch->id, 'product_id' => $this->beans->product_id]);

        $this->putJson("/api/purchase-orders/{$po->id}", [
            'lines' => [['product_id' => $foreign->product_id, 'branch_product_id' => $foreign->id, 'quantity_ordered' => 1]],
        ], $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.branch_product_id');

        $this->assertSame(1, $po->lines()->count());
    }

    public function test_update_is_rejected_once_the_purchase_order_is_submitted(): void
    {
        $po = $this->createPurchaseOrder();
        $po->update(['status' => 'sent']);

        $this->putJson("/api/purchase-orders/{$po->id}", [
            'lines' => [['product_id' => $this->beans->product_id, 'branch_product_id' => $this->beans->id, 'quantity_ordered' => 2]],
        ], $this->headers())->assertUnprocessable();

        $this->assertSame($this->rice->id, $po->lines()->first()->branch_product_id);
    }

    private function createPurchaseOrder(): PurchaseOrder
    {
        $supplierId = $this->postJson('/api/suppliers', ['name' => 'Acme Foods'], $this->headers())->json('supplier.id');

        $id = $this->postJson('/api/purchase-orders', [
            'branch_id' => $this->branch->id,
            'supplier_id' => $supplierId,
            'lines' => [
                ['product_id' => $this->rice->product_id, 'branch_product_id' => $this->rice->id, 'quantity_ordered' => 10, 'unit_cost' => 50],
            ],
        ], $this->headers())->assertCreated()->json('purchase_order.id');

        return PurchaseOrder::findOrFail($id);
    }

    private function branchProduct(string $name): BranchProduct
    {
        $product = Product::factory()->create(['business_id' => $this->business->id, 'name' => $name]);

        return BranchProduct::factory()->create(['branch_id' => $this->branch->id, 'product_id' => $product->id]);
    }

    /**
     * @return array<string, int>
     */
    private function headers(): array
    {
        $this->actingAs($this->owner);

        return ['X-Business-Id' => $this->business->id];
    }
}
