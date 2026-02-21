<?php

namespace Tests\Feature\Sync;

use App\Models\Branch;
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
    }

    /** @test */
    public function it_can_pull_changes_since_last_sync()
    {
        $device = $this->registerDevice();
        $this->createTestData();

        // Product must exist before last_sync_at to appear in "updated" (created_at <= since, updated_at > since)
        $product = Product::first();
        \Illuminate\Support\Facades\DB::table('products')->where('id', $product->id)->update([
            'created_at' => now()->subHours(2),
        ]);
        $product->refresh();
        $product->update([
            'base_selling_price' => 199.99,
            'version' => 2,
        ]);

        $response = $this->postJson('/api/sync/pull', [
            'last_sync_at' => now()->subHour()->toIso8601String(),
            'entities' => ['products'],
            'limit' => 100,
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
        $productInUpdated = collect($updated)->contains('id', $product->id);
        $productInCreated = collect($created)->contains('id', $product->id);
        $this->assertTrue($productInUpdated || $productInCreated, 'Product should appear in pull changes (created or updated)');
    }

    /** @test */
    public function it_can_push_offline_sales()
    {
        $device = $this->registerDevice();
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
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
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        $paymentMethod = PaymentMethod::factory()->create(['business_id' => $this->business->id]);

        $this->postJson('/api/sync/push', [
            'session_id' => $sessionId = Str::uuid()->toString(),
            'changes' => [
                'sales' => [
                    [
                        'client_uuid' => Str::uuid()->toString(),
                        'sale_number' => 'SALE-'.time(),
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
    }
}
