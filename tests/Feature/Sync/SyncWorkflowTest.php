<?php

namespace Tests\Feature\Sync;

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Business;
use App\Models\Customer;
use App\Models\DeviceRegistration;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SyncSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\SeedsPermissions;

class SyncWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPermissions;

    protected User $user;

    protected Business $business;

    protected Branch $branch;

    protected DeviceRegistration $device;

    protected function setUp(): void
    {
        parent::setUp();

        // Create user first
        $this->user = User::factory()->create();

        // Create test environment with user as owner
        $this->business = Business::factory()->create([
            'owner_id' => $this->user->id,
        ]);
        $this->branch = Branch::factory()->create([
            'business_id' => $this->business->id,
            'is_main' => true,
        ]);

        $this->user->businesses()->attach($this->business->id, ['is_active' => true]);

        $this->seedSyncPermissions();
        setPermissionsTeamId($this->business->id);
        $this->user->givePermissionTo('sync data');

        Sanctum::actingAs($this->user);
    }

    /** @test */
    public function it_completes_full_device_registration_to_sync_workflow()
    {
        // Step 1: Register device
        $deviceId = 'POS-WORKFLOW-'.Str::random(8);

        $registerResponse = $this->postJson('/api/sync/register-device', [
            'device_id' => $deviceId,
            'device_name' => 'Workflow Test Terminal',
            'device_type' => 'desktop',
            'os' => 'Windows 11',
            'app_version' => '1.0.0',
            'business_id' => $this->business->id,
            'branch_id' => $this->branch->id,
            'capabilities' => [
                'offline_mode' => true,
                'auto_sync' => true,
            ],
        ]);

        $registerResponse->assertStatus(201);
        $this->device = DeviceRegistration::where('device_id', $deviceId)->first();

        // Step 2: Bootstrap initial data
        $this->seedTestData();

        $bootstrapResponse = $this->postJson('/api/sync/bootstrap', [
            'branch_id' => $this->branch->id,
            'entities' => ['products', 'categories', 'payment_methods', 'customers'],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $deviceId,
        ]);

        $bootstrapResponse->assertStatus(200);
        $bootstrapData = $bootstrapResponse->json('data');

        $this->assertNotEmpty($bootstrapData['products']);
        $this->assertNotEmpty($bootstrapData['categories']);
        $this->assertNotEmpty($bootstrapData['payment_methods']);

        // Step 3: Simulate offline operation - create sale
        $clientSaleUuid = Str::uuid()->toString();
        $branchProduct = BranchProduct::where('branch_id', $this->branch->id)->first();
        $this->assertNotNull($branchProduct);
        $branchProduct->update([
            'stock_quantity' => 10,
            'cost_price' => 50.00,
            'selling_price' => 100.00,
        ]);
        $product = $branchProduct->product;
        $paymentMethod = PaymentMethod::first();

        $pushResponse = $this->postJson('/api/sync/push', [
            'session_id' => Str::uuid()->toString(),
            'changes' => [
                'sales' => [
                    [
                        'client_uuid' => $clientSaleUuid,
                        'sale_number' => 'SALE-WORKFLOW-001',
                        'branch_id' => $this->branch->id,
                        'sale_type' => 'pos',
                        'sale_date' => now()->toIso8601String(),
                        'subtotal' => 200.00,
                        'tax_amount' => 30.00,
                        'total_amount' => 230.00,
                        'payment_status' => 'paid',
                        'status' => 'completed',
                        'items' => [
                            [
                                'client_uuid' => Str::uuid()->toString(),
                                'product_id' => $product->id,
                                'quantity' => 2,
                                'unit_price' => 100.00,
                                'subtotal' => 200.00,
                            ],
                        ],
                        'payments' => [
                            [
                                'client_uuid' => Str::uuid()->toString(),
                                'payment_method_id' => $paymentMethod->id,
                                'amount' => 230.00,
                                'payment_date' => now()->toIso8601String(),
                            ],
                        ],
                    ],
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $deviceId,
        ]);

        $pushResponse->assertStatus(200);
        $pushResults = $pushResponse->json('results');

        $this->assertEquals(1, $pushResults['sales']['accepted']);
        $this->assertEquals(0, $pushResults['sales']['conflicts']);

        // Verify sale was created with server ID
        $serverSaleId = $pushResults['sales']['mappings'][$clientSaleUuid]['server_id'];
        $this->assertDatabaseHas('sales', [
            'id' => $serverSaleId,
            'client_uuid' => $clientSaleUuid,
            'sale_number' => 'SALE-WORKFLOW-001',
            'origin' => 'offline',
        ]);

        $this->assertDatabaseHas('inventory_transactions', [
            'reference_number' => 'SALE-WORKFLOW-001',
            'type' => 'sale',
            'quantity' => -2,
        ]);
        $branchProduct->refresh();
        $this->assertEquals(8, (int) $branchProduct->stock_quantity, 'Stock should be decremented by 2');

        // Step 4: Pull changes from server
        $lastSync = now()->subMinute()->toIso8601String();

        $pullResponse = $this->postJson('/api/sync/pull', [
            'last_sync_at' => $lastSync,
            'branch_id' => $this->branch->id,
            'entities' => ['products', 'customers'],
            'limit' => 100,
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $deviceId,
        ]);

        $pullResponse->assertStatus(200);

        // Step 5: Check sync status
        $statusResponse = $this->getJson('/api/sync/status', [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $deviceId,
        ]);

        $statusResponse->assertStatus(200)
            ->assertJsonStructure([
                'device' => ['device_id', 'status', 'total_syncs'],
                'pending_changes',
                'server_timestamp',
            ]);

        // Step 6: Send heartbeat
        $heartbeatResponse = $this->postJson('/api/sync/heartbeat', [], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $deviceId,
        ]);

        $heartbeatResponse->assertStatus(200);

        // Verify device was updated
        $this->device->refresh();
        $this->assertNotNull($this->device->last_seen_at);
    }

    /** @test */
    public function it_handles_offline_customer_creation_workflow()
    {
        $this->device = $this->registerTestDevice();

        $clientCustomerUuid = Str::uuid()->toString();

        // Push offline-created customer
        $response = $this->postJson('/api/sync/push', [
            'session_id' => Str::uuid()->toString(),
            'changes' => [
                'customers' => [
                    [
                        'client_uuid' => $clientCustomerUuid,
                        'customer_code' => 'CUST-OFFLINE-001',
                        'name' => 'Offline Customer',
                        'email' => 'offline@test.com',
                        'phone' => '1234567890',
                        'type' => 'regular',
                        'version' => 1,
                        'origin' => 'offline',
                    ],
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $this->device->device_id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('results.customers.accepted'));

        // Verify customer created with mapping
        $serverCustomerId = $response->json('results.customers.mappings')[$clientCustomerUuid]['server_id'];
        $this->assertDatabaseHas('customers', [
            'id' => $serverCustomerId,
            'client_uuid' => $clientCustomerUuid,
            'customer_code' => 'CUST-OFFLINE-001',
        ]);
    }

    /** @test */
    public function it_handles_multiple_devices_syncing_concurrently()
    {
        $device1 = $this->registerTestDevice('DEVICE-1');
        $device2 = $this->registerTestDevice('DEVICE-2');

        // Device 1 creates a customer
        $customer1Uuid = Str::uuid()->toString();
        $this->postJson('/api/sync/push', [
            'session_id' => Str::uuid()->toString(),
            'changes' => [
                'customers' => [
                    [
                        'client_uuid' => $customer1Uuid,
                        'customer_code' => 'CUST-DEV1-001',
                        'name' => 'Device 1 Customer',
                        'type' => 'walk-in',
                        'version' => 1,
                    ],
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $device1->device_id,
        ]);

        // Device 2 creates a different customer
        $customer2Uuid = Str::uuid()->toString();
        $this->postJson('/api/sync/push', [
            'session_id' => Str::uuid()->toString(),
            'changes' => [
                'customers' => [
                    [
                        'client_uuid' => $customer2Uuid,
                        'customer_code' => 'CUST-DEV2-001',
                        'name' => 'Device 2 Customer',
                        'type' => 'walk-in',
                        'version' => 1,
                    ],
                ],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $device2->device_id,
        ]);

        // Both customers should exist
        $this->assertEquals(2, Customer::whereIn('client_uuid', [$customer1Uuid, $customer2Uuid])->count());

        // Device 1 pulls changes - should NOT get its own customer back
        $pullResponse = $this->postJson('/api/sync/pull', [
            'last_sync_at' => now()->subHour()->toIso8601String(),
            'branch_id' => $this->branch->id,
            'entities' => ['customers'],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $device1->device_id,
        ]);

        $createdCustomers = $pullResponse->json('changes.customers.created') ?? [];

        // Device 1 should see customers created since last_sync_at (both devices' customers)
        $this->assertCount(2, $createdCustomers, 'Pull should return both customers created by the two devices');
        $clientUuids = collect($createdCustomers)->pluck('client_uuid')->toArray();
        $this->assertContains($customer2Uuid, $clientUuids, 'Device 2\'s customer should be in pull response');
        $this->assertContains($customer1Uuid, $clientUuids, 'Device 1\'s customer should be in pull response');
    }

    /** @test */
    public function it_tracks_sync_session_throughout_workflow()
    {
        $this->device = $this->registerTestDevice();
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        BranchProduct::create([
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'stock_quantity' => 10,
        ]);
        $paymentMethod = PaymentMethod::factory()->create(['business_id' => $this->business->id]);

        $sessionId = Str::uuid()->toString();

        // Push with specific session ID
        $this->postJson('/api/sync/push', [
            'session_id' => $sessionId,
            'changes' => [
                'sales' => [
                    [
                        'client_uuid' => Str::uuid()->toString(),
                        'sale_number' => 'SALE-SESSION-001',
                        'branch_id' => $this->branch->id,
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
            'X-Device-Id' => $this->device->device_id,
        ]);

        // Verify session was created and tracked
        $session = SyncSession::where('session_id', $sessionId)->first();
        $this->assertNotNull($session);
        $this->assertEquals('completed', $session->status);
        $this->assertEquals(1, $session->records_pushed);
        $this->assertEquals($this->device->id, $session->device_id);
    }

    /** @test */
    public function it_prevents_duplicate_sync_via_idempotency()
    {
        $this->device = $this->registerTestDevice();

        $clientUuid = Str::uuid()->toString();
        $customerData = [
            'client_uuid' => $clientUuid,
            'customer_code' => 'CUST-IDEM-001',
            'name' => 'Idempotent Customer',
            'type' => 'walk-in',
            'version' => 1,
        ];

        // Push first time
        $this->postJson('/api/sync/push', [
            'session_id' => Str::uuid()->toString(),
            'changes' => [
                'customers' => [$customerData],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $this->device->device_id,
        ]);

        $this->assertEquals(1, Customer::where('client_uuid', $clientUuid)->count());

        // Push again (simulating retry after network error)
        $this->postJson('/api/sync/push', [
            'session_id' => Str::uuid()->toString(),
            'changes' => [
                'customers' => [$customerData],
            ],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $this->device->device_id,
        ]);

        // Should still be only 1 customer
        $this->assertEquals(1, Customer::where('client_uuid', $clientUuid)->count());
    }

    /** @test */
    public function it_handles_extended_offline_period_workflow()
    {
        $this->device = $this->registerTestDevice();

        // Simulate 7 days offline - device creates multiple records
        $customers = [];
        for ($i = 1; $i <= 10; $i++) {
            $customers[] = [
                'client_uuid' => Str::uuid()->toString(),
                'customer_code' => 'CUST-OFFLINE-'.str_pad($i, 3, '0', STR_PAD_LEFT),
                'name' => "Offline Customer $i",
                'type' => 'walk-in',
                'version' => 1,
                'origin' => 'offline',
            ];
        }

        // Batch push all offline data
        $response = $this->postJson('/api/sync/push', [
            'session_id' => Str::uuid()->toString(),
            'changes' => [
                'customers' => $customers,
            ],
        ], [
            'X-Business-Id' => $this->business->id,
            'X-Device-Id' => $this->device->device_id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(10, $response->json('results.customers.accepted'));

        // Verify all customers created (customers table has no origin; count by client_uuid)
        $this->assertEquals(10, Customer::whereNotNull('client_uuid')->count());
    }

    // Helper Methods

    protected function registerTestDevice(?string $suffix = null): DeviceRegistration
    {
        $deviceId = 'TEST-'.($suffix ?? Str::random(8));

        return DeviceRegistration::create([
            'device_id' => $deviceId,
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

    protected function seedTestData(): void
    {
        ProductCategory::factory()->count(5)->create([
            'business_id' => $this->business->id,
        ]);

        Product::factory()->count(10)->create([
            'business_id' => $this->business->id,
        ]);

        PaymentMethod::factory()->count(3)->create([
            'business_id' => $this->business->id,
        ]);

        Customer::factory()->count(5)->create([
            'business_id' => $this->business->id,
        ]);

        foreach (Product::where('business_id', $this->business->id)->get() as $product) {
            BranchProduct::create([
                'branch_id' => $this->branch->id,
                'product_id' => $product->id,
                'shelf_quantity' => 5,
                'store_quantity' => 95,
                'stock_quantity' => 100,
                'cost_price' => 50.00,
                'selling_price' => 100.00,
                'is_available' => true,
            ]);
        }
    }
}
