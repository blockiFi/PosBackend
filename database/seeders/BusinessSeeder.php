<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class BusinessSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->createDemoRetailBusiness();

        if (config('seeding.size', 'large') === 'large') {
            $this->createDemoWholesaleBusiness();
        }
    }

    private function createDemoRetailBusiness(): void
    {
        $this->command->info('Creating demo retail business...');

        // Create owner user first
        $owner = User::create([
            'name' => 'Business Owner',
            'email' => 'owner@acmeretail.com',
            'password' => Hash::make('password'),
        ]);

        // Create business
        $business = Business::create([
            'owner_id' => $owner->id,
            'name' => 'Acme Retail Store',
            'slug' => 'acme-retail-'.uniqid(),
            'legal_name' => 'Acme Retail Store LLC',
            'email' => 'contact@acmeretail.com',
            'phone' => '+1234567890',
            'address' => '123 Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
            'currency' => 'USD',
            'time_zone' => 'America/New_York',
            'tax_registration_number' => 'TAX-12345',
            'default_tax_rate' => 10.00,
            'settings' => [
                'low_stock_threshold' => 10,
                'near_expiry_days' => 30,
                'shift_discrepancy_threshold' => 50.00,
                'enable_loyalty' => true,
                'loyalty_points_rate' => 0.01,
            ],
            'is_active' => true,
        ]);

        // Create branches
        $mainBranch = Branch::create([
            'business_id' => $business->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_main' => true,
            'email' => 'main@acmeretail.com',
            'phone' => '+1234567891',
            'address' => '123 Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
            'time_zone' => 'America/New_York',
            'tax_rate' => 10.00,
            'is_active' => true,
        ]);

        $downtownBranch = Branch::create([
            'business_id' => $business->id,
            'name' => 'Downtown Branch',
            'code' => 'DOWN',
            'is_main' => false,
            'email' => 'downtown@acmeretail.com',
            'phone' => '+1234567892',
            'address' => '456 Downtown Ave',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10002',
            'country' => 'US',
            'time_zone' => 'America/New_York',
            'tax_rate' => 10.00,
            'is_active' => true,
        ]);

        // Create roles for this business first (required before assigning roles to users)
        $this->createRolesForBusiness($business);

        // Create users and assign roles
        $this->createUsersForBusiness($business, $mainBranch, $downtownBranch, $owner);

        $this->command->info('Demo retail business created successfully!');
    }

    private function createDemoWholesaleBusiness(): void
    {
        $this->command->info('Creating demo wholesale business...');

        // Create owner user first
        $owner = User::create([
            'name' => 'Wholesale Owner',
            'email' => 'owner@supermart.com',
            'password' => Hash::make('password'),
        ]);

        // Create business
        $business = Business::create([
            'owner_id' => $owner->id,
            'name' => 'SuperMart Wholesale',
            'slug' => 'supermart-wholesale-'.uniqid(),
            'legal_name' => 'SuperMart Wholesale Inc.',
            'email' => 'info@supermart.com',
            'phone' => '+1987654321',
            'address' => '789 Wholesale Plaza',
            'city' => 'Los Angeles',
            'state' => 'CA',
            'postal_code' => '90001',
            'country' => 'US',
            'currency' => 'USD',
            'time_zone' => 'America/Los_Angeles',
            'tax_registration_number' => 'TAX-67890',
            'default_tax_rate' => 8.50,
            'settings' => [
                'low_stock_threshold' => 50,
                'near_expiry_days' => 60,
                'shift_discrepancy_threshold' => 100.00,
            ],
            'is_active' => true,
        ]);

        // Create main branch
        $mainBranch = Branch::create([
            'business_id' => $business->id,
            'name' => 'Warehouse',
            'code' => 'WH01',
            'is_main' => true,
            'email' => 'warehouse@supermart.com',
            'phone' => '+1987654322',
            'address' => '789 Wholesale Plaza',
            'city' => 'Los Angeles',
            'state' => 'CA',
            'postal_code' => '90001',
            'country' => 'US',
            'time_zone' => 'America/Los_Angeles',
            'tax_rate' => 8.50,
            'is_active' => true,
        ]);

        // Create roles for this business first
        $this->createRolesForBusiness($business);

        // Attach owner to business and assign Owner role
        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);
        app()[PermissionRegistrar::class]->setPermissionsTeamId($business->id);
        $owner->assignRole('Owner');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command->info('Demo wholesale business created successfully!');
    }

    /**
     * Create default roles for a business using the role templates from RealisticRoleSeeder.
     */
    private function createRolesForBusiness(Business $business): void
    {
        $this->command->info("Creating roles for business: {$business->name}...");

        // Set the team context for Spatie
        app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($business->id);

        $roleTemplates = [
            'Owner' => Permission::where('guard_name', 'api')->pluck('name')->toArray(),
            'Manager' => [
                'view categories',
                'create categories',
                'edit categories',
                'view products',
                'create products',
                'edit products',
                'manage branch products',
                'update product price',
                'update base selling price',
                'view inventory',
                'manage inventory',
                'view batches',
                'manage batches',
                'view sales',
                'create sales',
                'manage sales',
                'view customers',
                'create customers',
                'edit customers',
                'view payment methods',
                'manage payment methods',
                'view user shift',
                'view all shifts',
                'create shift',
                'close shift',
                'manage shifts',
                'request quick sale',
                'approve quick sale',
                'request refund',
                'approve refund',
                'adjust inventory',
                'view-branches',
                'manage-branches',
                'manage server sync',
                'sync data',
                'create-sales',
                'view-sales',
                'edit-sales',
                'refund-sales',
                'create-products',
                'view-products',
                'edit-products',
                'set branch product selling price',
                'manage-inventory',
                'view-inventory',
                'adjust-inventory',
                'create-customers',
                'view-customers',
                'edit-customers',
                'view-reports',
                'export-reports',
                'manage-users',
                'manage-settings',
                'manage-roles',
                'open-register',
                'close-register',
                'manage-cash',
                'manage-pin-codes',
                'request stock transfer',
                'approve stock transfer',
                'accept stock transfer',
                'write off stock',
                'view analytics',
                'view financial reports',
                'view branch analytics',
                'export analytics',
                'request shelf store move',
                'approve shelf store move',
                'override sale price',
                'set user password',
            ],
            'Supervisor' => [
                'view products', 'create products', 'edit products',
                'manage branch products', 'manage inventory', 'view inventory',
                'view categories', 'create categories', 'edit categories',
                'view sales', 'create sales', 'view customers', 'create customers', 'edit customers',
                'view analytics', 'view reports',
                'manage shifts', 'view all shifts', 'view user shift', 'close shift',
                'request refund', 'request quick sale', 'manage transfers', 'use-pin-login',
            ],
            'Cashier' => [
                'view products', 'view inventory', 'view categories',
                'view sales', 'create sales', 'view customers', 'create customers',
                'view user shift', 'close shift',
                'request refund', 'request quick sale', 'use-pin-login',
            ],
        ];

        foreach ($roleTemplates as $roleName => $permissionNames) {
            // Use query()->create() to bypass Spatie's findByParam global lookup
            $role = Role::query()->create([
                'name' => $roleName,
                'guard_name' => 'api',
                'business_id' => $business->id,
            ]);

            $permissions = Permission::whereIn('name', $permissionNames)
                ->where('guard_name', 'api')
                ->get();

            $role->syncPermissions($permissions);

            $this->command->info("  ✓ {$roleName}: ".$permissions->count().' permissions assigned');
        }

        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function createUsersForBusiness(Business $business, Branch $mainBranch, Branch $downtownBranch, User $owner): void
    {
        app()[PermissionRegistrar::class]->setPermissionsTeamId($business->id);

        // Attach owner to business and assign Owner role
        $owner->businesses()->attach($business->id, [
            'is_active' => true,
        ]);
        $owner->assignRole('Owner');

        // Create admin user and assign Manager role
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@acmeretail.com',
            'password' => Hash::make('password'),
        ]);
        $admin->businesses()->attach($business->id, [
            'is_active' => true,
        ]);
        $admin->assignRole('Manager');

        // Create manager users
        $manager1 = User::create([
            'name' => 'John Manager',
            'email' => 'john.manager@acmeretail.com',
            'password' => Hash::make('password'),
        ]);
        $manager1->businesses()->attach($business->id, [
            'is_active' => true,
        ]);
        $manager1->assignRole('Manager');

        $manager2 = User::create([
            'name' => 'Jane Manager',
            'email' => 'jane.manager@acmeretail.com',
            'password' => Hash::make('password'),
        ]);
        $manager2->businesses()->attach($business->id, [
            'is_active' => true,
        ]);
        $manager2->assignRole('Manager');

        // Create cashier users
        for ($i = 1; $i <= 4; $i++) {
            $cashier = User::create([
                'name' => "Cashier {$i}",
                'email' => "cashier{$i}@acmeretail.com",
                'password' => Hash::make('password'),
            ]);

            $cashier->businesses()->attach($business->id, [
                'is_active' => true,
            ]);
            $cashier->assignRole('Cashier');
        }

        // Enable PIN login for cashier1 (demo PIN: 123456)
        $cashier1 = User::where('email', 'cashier1@acmeretail.com')->first();
        if ($cashier1) {
            $cashier1->update([
                'pin_code' => '123456',
                'can_use_pin_login' => true,
            ]);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->command->info('Users created and roles assigned for business');
    }
}
