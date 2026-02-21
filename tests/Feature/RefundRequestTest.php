<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\RefundRequest;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RefundRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    private User $approver;

    private User $regularUser;

    private Business $business;

    private Branch $branch;

    private Product $product;

    private PaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        // Create business
        $this->business = Business::factory()->create();

        // Create branch
        $this->branch = Branch::factory()->create([
            'business_id' => $this->business->id,
        ]);

        // Create permissions for api guard
        Permission::firstOrCreate(['name' => 'request refund', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'approve refund', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'view sales', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'create sales', 'guard_name' => 'api']);

        // Create roles
        $requesterRole = Role::create([
            'name' => 'Requester',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $requesterRole->givePermissionTo(['request refund', 'view sales']);

        $approverRole = Role::create([
            'name' => 'Approver',
            'guard_name' => 'api',
            'business_id' => $this->business->id,
        ]);
        $approverRole->givePermissionTo(['approve refund', 'view sales']);

        // Create users
        $this->requester = User::factory()->create();
        $this->requester->businesses()->attach($this->business->id);
        DB::table('model_has_roles')->insert([
            'role_id' => $requesterRole->id,
            'model_type' => 'App\Models\User',
            'model_id' => $this->requester->id,
            'business_id' => $this->business->id,
        ]);

        $this->approver = User::factory()->create();
        $this->approver->businesses()->attach($this->business->id);
        DB::table('model_has_roles')->insert([
            'role_id' => $approverRole->id,
            'model_type' => 'App\Models\User',
            'model_id' => $this->approver->id,
            'business_id' => $this->business->id,
        ]);

        $this->regularUser = User::factory()->create();
        $this->regularUser->businesses()->attach($this->business->id);

        // Create product
        $this->product = Product::factory()->create([
            'business_id' => $this->business->id,
        ]);

        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'stock_quantity' => 100,
            'shelf_quantity' => 50,
            'store_quantity' => 50,
            'cost_price' => 10.00,
            'selling_price' => 15.00,
        ]);

        // Create payment method
        $this->paymentMethod = PaymentMethod::create([
            'business_id' => $this->business->id,
            'name' => 'Cash',
            'type' => 'cash',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function user_with_permission_can_create_refund_request()
    {
        $sale = $this->createCompletedSale();

        $response = $this->actingAs($this->requester)
            ->postJson('/api/refund-requests', [
                'sale_id' => $sale->id,
                'reason' => 'Customer returned damaged product',
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'refund_request' => [
                    'id',
                    'sale_id',
                    'amount',
                    'reason',
                    'status',
                    'requested_by',
                ],
            ]);

        $this->assertDatabaseHas('refund_requests', [
            'sale_id' => $sale->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->requester->id,
            'status' => RefundRequest::STATUS_PENDING,
        ]);
    }

    /** @test */
    public function user_without_permission_cannot_create_refund_request()
    {
        $sale = $this->createCompletedSale();

        $response = $this->actingAs($this->regularUser)
            ->postJson('/api/refund-requests', [
                'sale_id' => $sale->id,
                'reason' => 'Customer returned product',
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function cannot_create_refund_request_for_already_refunded_sale()
    {
        $sale = $this->createCompletedSale();
        $sale->markAsRefunded();

        $response = $this->actingAs($this->requester)
            ->postJson('/api/refund-requests', [
                'sale_id' => $sale->id,
                'reason' => 'Customer returned product',
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Sale is not eligible for refund',
                'reason' => 'Sale has already been refunded',
            ]);
    }

    /** @test */
    public function cannot_create_duplicate_pending_refund_request()
    {
        $sale = $this->createCompletedSale();

        // Create first refund request
        RefundRequest::create([
            'sale_id' => $sale->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->requester->id,
            'amount' => $sale->total_amount,
            'reason' => 'First request',
            'status' => RefundRequest::STATUS_PENDING,
        ]);

        // Try to create second request
        $response = $this->actingAs($this->requester)
            ->postJson('/api/refund-requests', [
                'sale_id' => $sale->id,
                'reason' => 'Second request',
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'A pending refund request already exists for this sale',
            ]);
    }

    /** @test */
    public function approver_can_approve_refund_request()
    {
        $sale = $this->createCompletedSale();
        $refundRequest = RefundRequest::create([
            'sale_id' => $sale->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->requester->id,
            'amount' => $sale->total_amount,
            'reason' => 'Customer returned product',
            'status' => RefundRequest::STATUS_PENDING,
        ]);

        // Check initial stock
        $initialStock = BranchProduct::where('branch_id', $this->branch->id)
            ->where('product_id', $this->product->id)
            ->first()
            ->stock_quantity;

        $response = $this->actingAs($this->approver)
            ->postJson("/api/refund-requests/{$refundRequest->id}/approve", [], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'refund_request' => [
                    'id',
                    'status',
                    'reviewed_by',
                    'reviewed_at',
                ],
            ]);

        // Verify refund request status
        $refundRequest->refresh();
        $this->assertEquals(RefundRequest::STATUS_PROCESSED, $refundRequest->status);
        $this->assertEquals($this->approver->id, $refundRequest->reviewed_by);
        $this->assertNotNull($refundRequest->reviewed_at);

        // Verify sale is marked as refunded
        $sale->refresh();
        $this->assertTrue($sale->is_refunded);
        $this->assertNotNull($sale->refunded_at);

        // Verify stock was restored
        $finalStock = BranchProduct::where('branch_id', $this->branch->id)
            ->where('product_id', $this->product->id)
            ->first()
            ->stock_quantity;

        $this->assertEquals($initialStock + 10, $finalStock);

        // Verify inventory transaction was created
        $this->assertDatabaseHas('inventory_transactions', [
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => 'adjustment',
            'quantity' => 10,
        ]);
    }

    /** @test */
    public function approver_can_reject_refund_request()
    {
        $sale = $this->createCompletedSale();
        $refundRequest = RefundRequest::create([
            'sale_id' => $sale->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->requester->id,
            'amount' => $sale->total_amount,
            'reason' => 'Customer returned product',
            'status' => RefundRequest::STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->approver)
            ->postJson("/api/refund-requests/{$refundRequest->id}/reject", [
                'rejection_reason' => 'Product was not actually damaged',
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200);

        $refundRequest->refresh();
        $this->assertEquals(RefundRequest::STATUS_REJECTED, $refundRequest->status);
        $this->assertEquals($this->approver->id, $refundRequest->reviewed_by);
        $this->assertEquals('Product was not actually damaged', $refundRequest->rejection_reason);
        $this->assertNotNull($refundRequest->reviewed_at);

        // Verify sale is NOT marked as refunded
        $sale->refresh();
        $this->assertFalse($sale->is_refunded);
    }

    /** @test */
    public function requester_cannot_approve_their_own_request()
    {
        // Give requester both roles
        $approverRole = Role::where('name', 'Approver')->first();
        DB::table('model_has_roles')->insert([
            'role_id' => $approverRole->id,
            'model_type' => 'App\Models\User',
            'model_id' => $this->requester->id,
            'business_id' => $this->business->id,
        ]);

        $sale = $this->createCompletedSale();
        $refundRequest = RefundRequest::create([
            'sale_id' => $sale->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->requester->id,
            'amount' => $sale->total_amount,
            'reason' => 'Customer returned product',
            'status' => RefundRequest::STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->requester)
            ->postJson("/api/refund-requests/{$refundRequest->id}/approve", [], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'You cannot approve your own refund request',
            ]);
    }

    /** @test */
    public function requester_cannot_reject_their_own_request()
    {
        // Give requester both roles
        $approverRole = Role::where('name', 'Approver')->first();
        DB::table('model_has_roles')->insert([
            'role_id' => $approverRole->id,
            'model_type' => 'App\Models\User',
            'model_id' => $this->requester->id,
            'business_id' => $this->business->id,
        ]);

        $sale = $this->createCompletedSale();
        $refundRequest = RefundRequest::create([
            'sale_id' => $sale->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->requester->id,
            'amount' => $sale->total_amount,
            'reason' => 'Customer returned product',
            'status' => RefundRequest::STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->requester)
            ->postJson("/api/refund-requests/{$refundRequest->id}/reject", [
                'rejection_reason' => 'Changed my mind',
            ], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'You cannot reject your own refund request',
            ]);
    }

    /** @test */
    public function cannot_approve_already_processed_request()
    {
        $sale = $this->createCompletedSale();
        $refundRequest = RefundRequest::create([
            'sale_id' => $sale->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->requester->id,
            'amount' => $sale->total_amount,
            'reason' => 'Customer returned product',
            'status' => RefundRequest::STATUS_APPROVED,
        ]);

        $response = $this->actingAs($this->approver)
            ->postJson("/api/refund-requests/{$refundRequest->id}/approve", [], [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Only pending refund requests can be approved',
            ]);
    }

    /** @test */
    public function requester_can_view_their_own_requests()
    {
        $sale = $this->createCompletedSale();
        $refundRequest = RefundRequest::create([
            'sale_id' => $sale->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->requester->id,
            'amount' => $sale->total_amount,
            'reason' => 'Customer returned product',
            'status' => RefundRequest::STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->requester)
            ->getJson('/api/refund-requests', [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $refundRequest->id);
    }

    /** @test */
    public function approver_can_view_all_requests()
    {
        $sale1 = $this->createCompletedSale();
        $sale2 = $this->createCompletedSale();

        $request1 = RefundRequest::create([
            'sale_id' => $sale1->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->requester->id,
            'amount' => $sale1->total_amount,
            'reason' => 'First request',
            'status' => RefundRequest::STATUS_PENDING,
        ]);

        $request2 = RefundRequest::create([
            'sale_id' => $sale2->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->regularUser->id,
            'amount' => $sale2->total_amount,
            'reason' => 'Second request',
            'status' => RefundRequest::STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->approver)
            ->getJson('/api/refund-requests', [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /** @test */
    public function can_filter_refund_requests_by_status()
    {
        $sale1 = $this->createCompletedSale();
        $sale2 = $this->createCompletedSale();

        RefundRequest::create([
            'sale_id' => $sale1->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->requester->id,
            'amount' => $sale1->total_amount,
            'reason' => 'Pending request',
            'status' => RefundRequest::STATUS_PENDING,
        ]);

        RefundRequest::create([
            'sale_id' => $sale2->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->requester->id,
            'amount' => $sale2->total_amount,
            'reason' => 'Rejected request',
            'status' => RefundRequest::STATUS_REJECTED,
        ]);

        $response = $this->actingAs($this->approver)
            ->getJson('/api/refund-requests?status=pending', [
                'X-Business-Id' => $this->business->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', RefundRequest::STATUS_PENDING);
    }

    /**
     * Helper method to create a completed sale
     */
    private function createCompletedSale(): Sale
    {
        $sale = Sale::create([
            'sale_number' => 'SAL-'.uniqid(),
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->requester->id,
            'sale_date' => now(),
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => 150.00,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 150.00,
            'paid_amount' => 150.00,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'product_sku' => $this->product->sku,
            'quantity' => 10,
            'unit_price' => 15.00,
            'discount_percentage' => 0,
            'tax_rate' => 0,
            'subtotal' => 150.00,
            'tax_amount' => 0,
            'total' => 150.00,
        ]);

        Payment::create([
            'sale_id' => $sale->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => 150.00,
            'payment_date' => now(),
            'status' => 'completed',
        ]);

        return $sale;
    }
}
