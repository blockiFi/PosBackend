<?php

namespace Tests\Feature\Sync;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Customer;
use App\Models\DeviceRegistration;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductCategory;
use App\Models\QuickSale;
use App\Models\Sale;
use App\Models\SalesShift;
use App\Models\SyncSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\SeedsPermissions;

class SyncControllerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPermissions;

    protected User $user;

    protected Business $business;

    protected Branch $branch;

    protected string $deviceId;

    protected function setUp(): void
    {
        parent::setUp();

        // Create user first
        $this->user = User::factory()->create();

        // Create business with user as owner
        $this->business = Business::factory()->create([
            'owner_id' => $this->user->id,
        ]);
        $this->branch = Branch::factory()->create([
            'business_id' => $this->business->id,
            'is_main' => true,
        ]);

        $this->user->businesses()->attach($this->business->id, [
            'is_active' => true,
        ]);

        $this->seedSyncPermissions();
        setPermissionsTeamId($this->business->id);
        $this->user->givePermissionTo('sync data');

        $this->deviceId = 'TEST-DEVICE-'.Str::random(8);

        // Authenticate user
        Sanctum::actingAs($this->user);
    }

    /** @test */
    public function it_can_register_a_new_device()
    {
        $response = $this->postJson('/api/sync/register-device', [
            'device_id' => $this->deviceId,
            'device_name' => 'Test POS Terminal',
            'device_type' => 'desktop',
            'os' => 'Windows 11',
            'app_version' => '1.0.0',
            'branch_id' => $this->branch->id,
            'business_id' => $this->business->id,
            'capabilities' => [
                'offline_mode' => true,
                'auto_sync' => true,
                'max_offline_days' => 30,
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'device' => [
                    'id',
                    'device_id',
                    'business_id',
                    'branch_id',
                    'status',
                    'created_at',
                ],
                'sync_token',
            ]);

        $this->assertDatabaseHas('device_registrations', [
            'device_id' => $this->deviceId,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);
    }

    /** @test */
    public function it_prevents_duplicate_device_registration()
    {
        DeviceRegistration::create([
            'device_id' => $this->deviceId,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'device_name' => 'Existing Device',
            'device_type' => 'desktop',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/sync/register-device', [
            'device_id' => $this->deviceId,
            'device_name' => 'Test POS Terminal',
            'device_type' => 'desktop',
            'os' => 'Windows 11',
            'app_version' => '1.0.0',
            'branch_id' => $this->branch->id,
            'business_id' => $this->business->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['device_id']);
    }

    /** @test */
    public function it_can_bootstrap_initial_data()
    {
        $this->createTestData();
        $device = $this->registerDevice();

        $response = $this->postJson('/api/sync/bootstrap', [
            'branch_id' => $this->branch->id,
            'entities' => ['products', 'categories', 'payment_methods', 'customers'],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $device->device_id,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'session_id',
                'server_timestamp',
                'business_settings' => [
                    'allow_decimal_quantities',
                ],
                'data' => [
                    'products',
                    'categories',
                    'payment_methods',
                    'customers',
                ],
                'metadata' => [
                    'total_records',
                    'checksum',
                ],
            ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data['products']);
        $this->assertNotEmpty($data['categories']);
        $this->assertNotEmpty($data['payment_methods']);
        $this->assertFalse($response->json('business_settings.allow_decimal_quantities'));

        $firstProduct = $data['products'][0];
        $this->assertIsInt($firstProduct['stock_quantity']);
        $this->assertIsInt($firstProduct['shelf_quantity']);
        $this->assertIsInt($firstProduct['store_quantity']);
    }

    /** @test */
    public function it_can_pull_changes_since_last_sync()
    {
        $device = $this->registerDevice();
        $this->createTestData();

        // Branch product must exist before last_sync_at to appear in "updated" (created_at <= since, updated_at > since)
        $branchProduct = BranchProduct::where('branch_id', $this->branch->id)->first();
        $this->assertNotNull($branchProduct);
        \Illuminate\Support\Facades\DB::table('branch_products')->where('id', $branchProduct->id)->update([
            'created_at' => now()->subHours(2),
        ]);
        $branchProduct->refresh();
        $branchProduct->update([
            'selling_price' => 199.99,
        ]);

        $response = $this->postJson('/api/sync/pull', [
            'last_sync_at' => now()->subHour()->toIso8601String(),
            'entities' => ['products'],
            'limit' => 100,
            'branch_id' => $this->branch->id,
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $device->device_id,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'session_id',
                'server_timestamp',
                'changes' => [
                    'products' => [
                        'created',
                        'updated',
                        'deleted',
                    ],
                ],
                'has_more',
            ]);

        $updated = $response->json('changes.products.updated');
        $created = $response->json('changes.products.created');
        $productId = $branchProduct->product_id;
        $productInUpdated = collect($updated)->contains('product_id', $productId);
        $productInCreated = collect($created)->contains('product_id', $productId);
        $this->assertTrue($productInUpdated || $productInCreated, 'Branch product should appear in pull changes (created or updated)');
    }

    /** @test */
    public function it_can_push_offline_sales()
    {
        $device = $this->registerDevice();
        $shift = $this->openShift();
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
        ]);
        $branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'stock_quantity' => 10,
            'cost_price' => 25.00,
            'selling_price' => 50.00,
        ]);
        $paymentMethod = PaymentMethod::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $clientUuid = Str::uuid()->toString();
        $saleNumber = 'SALE-TEST-'.time();

        $response = $this->postJson('/api/sync/push', [
            'session_id' => Str::uuid()->toString(),
            'changes' => [
                'sales' => [
                    [
                        'client_uuid' => $clientUuid,
                        'sale_number' => $saleNumber,
                        'branch_id' => $this->branch->id,
                        'shift_id' => $shift->id,
                        'sale_type' => 'pos',
                        'sale_date' => now()->toIso8601String(),
                        'subtotal' => 100.00,
                        'tax_amount' => 15.00,
                        'total_amount' => 115.00,
                        'payment_status' => 'paid',
                        'status' => 'completed',
                        'version' => 1,
                        'origin' => 'offline',
                        'items' => [
                            [
                                'client_uuid' => Str::uuid()->toString(),
                                'product_id' => $product->id,
                                'quantity' => 2,
                                'unit_price' => 50.00,
                                'subtotal' => 100.00,
                            ],
                        ],
                        'payments' => [
                            [
                                'client_uuid' => Str::uuid()->toString(),
                                'payment_method_id' => $paymentMethod->id,
                                'amount' => 115.00,
                                'payment_date' => now()->toIso8601String(),
                            ],
                        ],
                    ],
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $device->device_id,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'session_id',
                'status',
                'results' => [
                    'sales' => [
                        'accepted',
                        'rejected',
                        'conflicts',
                        'mappings',
                    ],
                ],
                'server_timestamp',
            ]);

        $this->assertEquals(1, $response->json('results.sales.accepted'));
        $this->assertDatabaseHas('sales', [
            'client_uuid' => $clientUuid,
            'sale_number' => $saleNumber,
            'origin' => 'offline',
        ]);

        $this->assertDatabaseHas('inventory_transactions', [
            'reference_number' => $saleNumber,
            'type' => 'sale',
            'quantity' => -2,
        ]);
        $branchProduct->refresh();
        $this->assertEqualsWithDelta(8.0, (float) $branchProduct->stock_quantity, 0.001, 'Stock should be decremented by 2');
    }

    /** @test */
    public function it_deducts_fractional_quantity_on_offline_sale_push()
    {
        $this->enableDecimalQuantities($this->business);
        $device = $this->registerDevice();
        $shift = $this->openShift();
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
        ]);
        $branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'stock_quantity' => 20,
            'shelf_quantity' => 20,
            'cost_price' => 25.00,
            'selling_price' => 50.00,
        ]);
        $paymentMethod = PaymentMethod::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $clientUuid = Str::uuid()->toString();
        $saleNumber = 'SALE-FRAC-'.time();

        $response = $this->postJson('/api/sync/push', [
            'session_id' => Str::uuid()->toString(),
            'changes' => [
                'sales' => [
                    [
                        'client_uuid' => $clientUuid,
                        'sale_number' => $saleNumber,
                        'branch_id' => $this->branch->id,
                        'shift_id' => $shift->id,
                        'sale_type' => 'pos',
                        'sale_date' => now()->toIso8601String(),
                        'subtotal' => 525.00,
                        'tax_amount' => 0,
                        'total_amount' => 525.00,
                        'payment_status' => 'paid',
                        'status' => 'completed',
                        'version' => 1,
                        'origin' => 'offline',
                        'items' => [
                            [
                                'client_uuid' => Str::uuid()->toString(),
                                'product_id' => $product->id,
                                'quantity' => 10.5,
                                'unit_price' => 50.00,
                                'subtotal' => 525.00,
                            ],
                        ],
                        'payments' => [
                            [
                                'client_uuid' => Str::uuid()->toString(),
                                'payment_method_id' => $paymentMethod->id,
                                'amount' => 525.00,
                                'payment_date' => now()->toIso8601String(),
                            ],
                        ],
                    ],
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $device->device_id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('results.sales.accepted'));

        $this->assertDatabaseHas('sale_items', [
            'product_id' => $product->id,
            'quantity' => 10.5,
        ]);

        $this->assertDatabaseHas('inventory_transactions', [
            'reference_number' => $saleNumber,
            'type' => 'sale',
            'quantity' => -10.5,
        ]);

        $branchProduct->refresh();
        $this->assertEqualsWithDelta(9.5, (float) $branchProduct->stock_quantity, 0.001, 'Stock should be decremented by 10.5');
    }

    /** @test */
    public function it_can_push_offline_deposit_sales_without_deducting_stock_when_configured()
    {
        $this->business->settings = ['deposit_stock_mode' => 'deduct_on_complete'];
        $this->business->save();

        $device = $this->registerDevice();
        $shift = $this->openShift();

        $customer = Customer::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $product = Product::factory()->create([
            'business_id' => $this->business->id,
        ]);
        $branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'stock_quantity' => 10,
            'cost_price' => 25.00,
            'selling_price' => 50.00,
        ]);

        $paymentMethod = PaymentMethod::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $clientUuid = Str::uuid()->toString();
        $saleNumber = 'DEP-OFFLINE-'.time();

        $response = $this->postJson('/api/sync/push', [
            'session_id' => Str::uuid()->toString(),
            'changes' => [
                'sales' => [
                    [
                        'client_uuid' => $clientUuid,
                        'sale_number' => $saleNumber,
                        'branch_id' => $this->branch->id,
                        'shift_id' => $shift->id,
                        'customer_id' => $customer->id,
                        'sale_type' => 'deposit',
                        'sale_date' => now()->toIso8601String(),
                        'subtotal' => 100.00,
                        'tax_amount' => 0,
                        'total_amount' => 100.00,
                        'payment_status' => 'unpaid',
                        'origin' => 'offline',
                        'items' => [
                            [
                                'client_uuid' => Str::uuid()->toString(),
                                'product_id' => $product->id,
                                'quantity' => 2,
                                'unit_price' => 50.00,
                                'subtotal' => 100.00,
                            ],
                        ],
                        'payments' => [
                            [
                                'client_uuid' => Str::uuid()->toString(),
                                'payment_method_id' => $paymentMethod->id,
                                'amount' => 20.00,
                                'payment_date' => now()->toIso8601String(),
                            ],
                        ],
                    ],
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $device->device_id,
        ]);

        $response->assertStatus(200);

        $serverSaleId = $response->json("results.sales.mappings.$clientUuid.server_id");
        $this->assertNotEmpty($serverSaleId);

        $this->assertDatabaseHas('sales', [
            'id' => $serverSaleId,
            'sale_number' => $saleNumber,
            'sale_type' => 'deposit',
            'status' => 'pending',
        ]);

        $this->assertDatabaseMissing('inventory_transactions', [
            'reference_number' => $saleNumber,
            'type' => 'sale',
        ]);

        $branchProduct->refresh();
        $this->assertEquals(10, (int) $branchProduct->stock_quantity, 'Stock should not be decremented for deferred deposit sales');

        $sale = Sale::findOrFail($serverSaleId);
        $this->assertIsArray($sale->metadata);
        $this->assertEquals('deduct_on_complete', $sale->metadata['deposit_stock_mode'] ?? null);
        $sale->refresh();
        $this->assertSame('partial', $sale->payment_status);
        $this->assertEquals(20.0, (float) $sale->paid_amount);
    }

    /** @test */
    public function it_rejects_push_sale_when_product_has_no_branch_product()
    {
        $device = $this->registerDevice();
        $shift = $this->openShift();
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
        ]);
        $paymentMethod = PaymentMethod::factory()->create([
            'business_id' => $this->business->id,
        ]);
        $clientUuid = Str::uuid()->toString();
        $saleNumber = 'SALE-REJECT-'.time();

        $response = $this->postJson('/api/sync/push', [
            'session_id' => Str::uuid()->toString(),
            'changes' => [
                'sales' => [
                    [
                        'client_uuid' => $clientUuid,
                        'sale_number' => $saleNumber,
                        'branch_id' => $this->branch->id,
                        'shift_id' => $shift->id,
                        'sale_type' => 'pos',
                        'sale_date' => now()->toIso8601String(),
                        'subtotal' => 100.00,
                        'total_amount' => 115.00,
                        'payment_status' => 'paid',
                        'status' => 'completed',
                        'items' => [
                            [
                                'product_id' => $product->id,
                                'quantity' => 2,
                                'unit_price' => 50.00,
                                'subtotal' => 100.00,
                            ],
                        ],
                        'payments' => [
                            [
                                'payment_method_id' => $paymentMethod->id,
                                'amount' => 115.00,
                                'payment_date' => now()->toIso8601String(),
                            ],
                        ],
                    ],
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $device->device_id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('results.sales.rejected'));
        $this->assertDatabaseMissing('sales', ['client_uuid' => $clientUuid]);
    }

    /** @test */
    public function it_deducts_from_active_quick_sale_batch_when_pushing_sale()
    {
        $device = $this->registerDevice();
        $shift = $this->openShift();
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        $branchProduct = BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'stock_quantity' => 20,
            'cost_price' => 25.00,
            'selling_price' => 50.00,
        ]);
        $batch = ProductBatch::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_quantity' => 15,
            'received_quantity' => 15,
            'status' => 'active',
            'expiry_date' => now()->addMonths(1),
        ]);
        QuickSale::create([
            'product_id' => $product->id,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'batch_id' => $batch->id,
            'requested_by' => $this->user->id,
            'reason' => 'Test quick sale for sync batch deduction',
            'expiry_date' => now()->addDays(7),
            'status' => QuickSale::STATUS_ACTIVE,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'start_time' => now()->subHour(),
            'end_time' => now()->addDays(1),
        ]);
        $paymentMethod = PaymentMethod::factory()->create(['business_id' => $this->business->id]);

        $clientUuid = Str::uuid()->toString();
        $saleNumber = 'SALE-QS-'.time();
        $response = $this->postJson('/api/sync/push', [
            'session_id' => Str::uuid()->toString(),
            'changes' => [
                'sales' => [
                    [
                        'client_uuid' => $clientUuid,
                        'sale_number' => $saleNumber,
                        'branch_id' => $this->branch->id,
                        'shift_id' => $shift->id,
                        'sale_type' => 'pos',
                        'sale_date' => now()->toIso8601String(),
                        'subtotal' => 135.00,
                        'total_amount' => 135.00,
                        'payment_status' => 'paid',
                        'status' => 'completed',
                        'items' => [
                            [
                                'product_id' => $product->id,
                                'quantity' => 3,
                                'unit_price' => 45.00,
                                'subtotal' => 135.00,
                            ],
                        ],
                        'payments' => [
                            [
                                'payment_method_id' => $paymentMethod->id,
                                'amount' => 135.00,
                                'payment_date' => now()->toIso8601String(),
                            ],
                        ],
                    ],
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $device->device_id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('results.sales.accepted'));
        $sale = Sale::where('client_uuid', $clientUuid)->first();
        $this->assertNotNull($sale);
        $saleItem = $sale->items()->first();
        $this->assertEquals($batch->id, $saleItem->batch_id);
        $this->assertDatabaseHas('inventory_transactions', [
            'reference_number' => $saleNumber,
            'type' => 'sale',
            'batch_id' => $batch->id,
            'quantity' => -3,
        ]);
        $batch->refresh();
        $this->assertEquals(12, $batch->current_quantity);
    }

    /** @test */
    public function it_can_push_offline_customers()
    {
        $device = $this->registerDevice();
        $clientUuid = Str::uuid()->toString();

        $response = $this->postJson('/api/sync/push', [
            'session_id' => Str::uuid()->toString(),
            'changes' => [
                'customers' => [
                    [
                        'client_uuid' => $clientUuid,
                        'customer_code' => 'CUST-'.time(),
                        'name' => 'Test Customer',
                        'email' => 'test@example.com',
                        'phone' => '1234567890',
                        'type' => 'walk-in',
                        'version' => 1,
                        'origin' => 'offline',
                    ],
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $device->device_id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('results.customers.accepted'));
        $this->assertDatabaseHas('customers', [
            'client_uuid' => $clientUuid,
            'name' => 'Test Customer',
        ]);
    }

    /** @test */
    public function it_detects_duplicate_push_via_client_uuid()
    {
        $device = $this->registerDevice();
        $clientUuid = Str::uuid()->toString();

        // Create existing customer with same client_uuid
        Customer::factory()->create([
            'business_id' => $this->business->id,
            'client_uuid' => $clientUuid,
            'customer_code' => 'CUST-EXISTING',
        ]);

        $response = $this->postJson('/api/sync/push', [
            'session_id' => Str::uuid()->toString(),
            'changes' => [
                'customers' => [
                    [
                        'client_uuid' => $clientUuid,
                        'customer_code' => 'CUST-NEW',
                        'name' => 'Duplicate Customer',
                        'type' => 'walk-in',
                        'version' => 1,
                    ],
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $device->device_id,
        ]);

        $response->assertStatus(200);
        // Should skip duplicate, not create new
        $this->assertEquals(1, Customer::where('client_uuid', $clientUuid)->count());
    }

    /** @test */
    public function it_can_check_sync_status()
    {
        $device = $this->registerDevice();

        $response = $this->getJson('/api/sync/status?include_pending=true', [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $device->device_id,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'device' => [
                    'device_id',
                    'status',
                    'last_sync_at',
                    'total_syncs',
                ],
                'pending_changes',
                'server_timestamp',
            ]);
    }

    /** @test */
    public function it_can_send_heartbeat()
    {
        $device = $this->registerDevice();
        $oldLastSeen = $device->last_seen_at;

        sleep(1);

        $response = $this->postJson('/api/sync/heartbeat', [], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $device->device_id,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'server_timestamp',
                'has_pending_changes',
                'should_sync',
            ]);

        $device->refresh();
        $this->assertNotEquals($oldLastSeen, $device->last_seen_at);
    }

    /** @test */
    public function it_requires_authentication_for_sync_endpoints()
    {
        $response = $this->postJson('/api/sync/bootstrap', [
            'branch_id' => 1,
            'entities' => ['products'],
        ]);

        $this->assertContains($response->getStatusCode(), [401, 400]);
    }

    /** @test */
    public function it_validates_device_registration_input()
    {
        $response = $this->postJson('/api/sync/register-device', [
            'device_id' => '', // Empty device_id
            'device_type' => 'invalid_type', // Invalid type
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['device_id', 'device_type']);
    }

    /** @test */
    public function it_tracks_sync_session_statistics()
    {
        $device = $this->registerDevice();
        $shift = $this->openShift();
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'stock_quantity' => 10,
        ]);
        $paymentMethod = PaymentMethod::factory()->create(['business_id' => $this->business->id]);

        $this->postJson('/api/sync/push', [
            'session_id' => $sessionId = Str::uuid()->toString(),
            'changes' => [
                'sales' => [
                    [
                        'client_uuid' => Str::uuid()->toString(),
                        'sale_number' => 'SALE-'.time(),
                        'branch_id' => $this->branch->id,
                        'shift_id' => $shift->id,
                        'sale_type' => 'pos',
                        'sale_date' => now()->toIso8601String(),
                        'subtotal' => 100.00,
                        'tax_amount' => 15.00,
                        'total_amount' => 115.00,
                        'payment_status' => 'paid',
                        'status' => 'completed',
                        'items' => [
                            [
                                'client_uuid' => Str::uuid()->toString(),
                                'product_id' => $product->id,
                                'quantity' => 1,
                                'unit_price' => 100.00,
                                'subtotal' => 100.00,
                            ],
                        ],
                        'payments' => [
                            [
                                'client_uuid' => Str::uuid()->toString(),
                                'payment_method_id' => $paymentMethod->id,
                                'amount' => 115.00,
                                'payment_date' => now()->toIso8601String(),
                            ],
                        ],
                    ],
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $device->device_id,
        ]);

        $this->assertDatabaseHas('sync_sessions', [
            'session_id' => $sessionId,
            'device_id' => $device->id,
            'status' => 'completed',
        ]);

        $session = SyncSession::where('session_id', $sessionId)->first();
        $this->assertEquals(1, $session->records_pushed);
    }

    /** @test */
    public function it_returns_online_devices_last_seen_within_five_minutes()
    {
        DeviceRegistration::create([
            'device_id' => 'ONLINE-DEVICE-1',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'device_name' => 'Online Device',
            'device_type' => 'desktop',
            'status' => 'active',
            'last_seen_at' => now(),
        ]);

        DeviceRegistration::create([
            'device_id' => 'OFFLINE-DEVICE-1',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'device_name' => 'Offline Device',
            'device_type' => 'mobile',
            'status' => 'active',
            'last_seen_at' => now()->subMinutes(10),
        ]);

        $response = $this->getJson('/api/sync/online-devices', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'device_id', 'device_name', 'last_seen_at', 'branch', 'user']]]);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('ONLINE-DEVICE-1', $response->json('data.0.device_id'));
    }

    /** @test */
    public function it_requires_sync_data_permission_for_online_devices()
    {
        $this->user->revokePermissionTo('sync data');

        $response = $this->getJson('/api/sync/online-devices', [
            'X-Business-Id' => $this->business->id,
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function it_returns_400_when_business_context_missing_for_online_devices()
    {
        $response = $this->getJson('/api/sync/online-devices');

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Business context is required');
    }

    // Helper Methods

    protected function registerDevice(): DeviceRegistration
    {
        return DeviceRegistration::create([
            'device_id' => $this->deviceId,
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'device_name' => 'Test Device',
            'device_type' => 'desktop',
            'os' => 'Test OS',
            'app_version' => '1.0.0',
            'status' => 'active',
        ]);
    }

    protected function openShift(): SalesShift
    {
        return SalesShift::create([
            'shift_number' => 'SHIFT-'.Str::random(8),
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'start_time' => now(),
            'opening_balance' => 0,
            'status' => 'open',
        ]);
    }

    protected function createTestData(): void
    {
        ProductCategory::factory()->count(3)->create([
            'business_id' => $this->business->id,
        ]);

        Product::factory()->count(5)->create([
            'business_id' => $this->business->id,
        ]);

        PaymentMethod::factory()->count(2)->create([
            'business_id' => $this->business->id,
        ]);

        Customer::factory()->count(3)->create([
            'business_id' => $this->business->id,
        ]);

        foreach (Product::where('business_id', $this->business->id)->get() as $product) {
            BranchProduct::create([
                'branch_id' => $this->branch->id,
                'product_id' => $product->id,
                'shelf_quantity' => 5,
                'store_quantity' => 95,
                'stock_quantity' => 100,
                'cost_price' => 25.00,
                'selling_price' => 50.00,
                'is_available' => true,
            ]);
        }
    }
}
