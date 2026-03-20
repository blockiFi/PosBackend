<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RealisticRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates realistic role templates with proper permission boundaries:
     * - Owner: System-wide control
     * - Manager: Approvals and operations management
     * - Supervisor: Monitoring and initiating workflows
     * - Cashier: Basic sales operations only
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command->info('Creating realistic role templates...');

        // Define role configurations with their permissions
        $roles = [
            'Owner' => [
                'description' => 'System-wide control - full access to all features',
                'permissions' => [
                    // Business & Branch Management
                    'manage-users',
                    'manage-branches',
                    'manage-settings',
                    'manage-roles',

                    // Products & Inventory
                    'view products',
                    'create products',
                    'edit products',
                    'delete products',
                    'manage branch products',
                    'update product price',
                    'override sale price',
                    'manage inventory',
                    'view inventory',

                    // Categories
                    'view categories',
                    'create categories',
                    'edit categories',
                    'delete categories',

                    // Sales & Customers
                    'view sales',
                    'create sales',
                    'manage sales',
                    'view customers',
                    'create customers',
                    'edit customers',
                    'delete customers',

                    // Payments
                    'view payment methods',
                    'manage payment methods',

                    // Shifts
                    'view user shift',
                    'view all shifts',
                    'create shift',
                    'close shift',
                    'manage shifts',

                    // Batches
                    'view batches',
                    'manage batches',

                    // Approvals & Workflows
                    'request refund',
                    'approve refund',
                    'request quick sale',
                    'approve quick sale',
                    'request stock transfer',
                    'approve stock transfer',
                    'accept stock transfer',
                    'write off stock',

                    // Analytics & Reports
                    'view analytics',
                    'view financial reports',
                    'view branch analytics',
                    'export analytics',

                    // Authentication
                    'use-pin-login',
                    'manage-pin-codes',

                    // Branch Management
                    'view-branches',
                    'manage-branches',

                    // Sync Operations
                    'manage server sync',
                    'sync data',
                ],
            ],

            'Manager' => [
                'description' => 'Operations management with approval authority',
                'permissions' => [
                    // Categories
                    'view categories',
                    'create categories',
                    'edit categories',

                    // Products
                    'view products',
                    'create products',
                    'edit products',
                    'manage branch products',
                    'update product price',
                    'update base selling price',

                    // Inventory & Batches
                    'view inventory',
                    'manage inventory',
                    'adjust inventory',
                    'view batches',
                    'manage batches',

                    // Sales
                    'view sales',
                    'create sales',
                    'manage sales',

                    // Customers
                    'view customers',
                    'create customers',
                    'edit customers',

                    // Payment Methods
                    'view payment methods',
                    'manage payment methods',

                    // Shifts
                    'view user shift',
                    'view all shifts',
                    'create shift',
                    'close shift',
                    'manage shifts',

                    // Quick sale + refund
                    'request quick sale',
                    'approve quick sale',
                    'request refund',
                    'approve refund',

                    // Branches
                    'view-branches',
                    'manage-branches',

                    // Sync
                    'manage server sync',
                    'sync data',

                    // Reports & analytics
                    'view-reports',
                    'export-reports',
                    'view analytics',
                    'view financial reports',
                    'view branch analytics',
                    'export analytics',

                    // User + role management & settings
                    'manage-users',
                    'manage-settings',
                    'manage-roles',
                    'manage-pin-codes',
                    'set user password',

                    // Register / cash
                    'open-register',
                    'close-register',
                    'manage-cash',

                    // Stock transfers / write-offs / moves
                    'request stock transfer',
                    'approve stock transfer',
                    'accept stock transfer',
                    'write off stock',
                    'request shelf store move',
                    'approve shelf store move',

                    // Pricing override
                    'override sale price',
                    'set branch product selling price',

                    // Also include legacy/dashed permissions referenced elsewhere
                    'create-sales',
                    'view-sales',
                    'edit-sales',
                    'refund-sales',
                    'create-products',
                    'view-products',
                    'edit-products',
                    'manage-inventory',
                    'view-inventory',
                    'adjust-inventory',
                    'create-customers',
                    'view-customers',
                    'edit-customers',
                    'view-reports',
                    'export-reports',
                    'manage-branches',
                ],
            ],

            'Supervisor' => [
                'description' => 'Monitoring and workflow initiation without approval authority',
                'permissions' => [
                    // Products & Inventory (View and basic operations)
                    'view products',
                    'view inventory',
                    'manage inventory',  // Can adjust stock levels
                    'manage branch products',

                    // Categories
                    'view categories',

                    // Sales & Customers
                    'view sales',
                    'create sales',
                    'view customers',
                    'create customers',

                    // Payment Methods
                    'view payment methods',

                    // Shifts (View only)
                    'view user shift',
                    'view all shifts',

                    // Batches
                    'view batches',

                    // Workflow Initiation (NO APPROVALS)
                    'request refund',        // Can request, cannot approve
                    'request quick sale',    // Can request, cannot approve
                    'request stock transfer', // Can request, cannot approve

                    // Analytics (View only, no financial details)
                    'view analytics',
                    'view branch analytics',

                    // Authentication
                    'use-pin-login',

                    // Branch Management
                    'view-branches',

                    // Sync Operations
                    'sync data',
                ],
            ],

            'Cashier' => [
                'description' => 'Basic sales operations only - no approvals or system access',
                'permissions' => [
                    // Products (View only)
                    'view products',
                    'view inventory',

                    // Categories (View only)
                    'view categories',

                    // Sales Operations
                    'view sales',
                    'create sales',

                    // Customers
                    'view customers',
                    'create customers',

                    // Payment Methods (View only)
                    'view payment methods',

                    // Own Shift Only
                    'view user shift',
                    'create shift',
                    'close shift',

                    // Batches (View only)
                    'view batches',

                    // Request Only (NO APPROVALS)
                    'request refund',       // Can request, cannot approve
                    'request quick sale',   // Can request, cannot approve

                    // Authentication
                    'use-pin-login',

                    // Branch Management
                    'view-branches',

                    // Sync Operations
                    'sync data',
                ],
            ],
        ];

        // Create permissions only — roles are created per-business, not globally
        foreach ($roles as $roleName => $config) {
            $this->command->info("Setting up permissions for {$roleName} role template...");

            foreach ($config['permissions'] as $permissionName) {
                Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'api',
                ]);
            }

            $this->command->info("  ✓ {$roleName}: ".count($config['permissions']).' permissions registered');
        }

        $this->command->newLine();
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('Permissions Created Successfully!');
        $this->command->info('Roles will be created per-business in BusinessSeeder.');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->newLine();

        // Display role summary
        $this->displayRoleSummary($roles);
    }

    /**
     * Display a summary of role configurations
     */
    private function displayRoleSummary(array $roles): void
    {
        foreach ($roles as $roleName => $config) {
            $this->command->info("📋 {$roleName}");
            $this->command->info("   {$config['description']}");
            $this->command->info('   Permissions: '.count($config['permissions']));

            // Highlight key capabilities
            $capabilities = $this->getKeyCapabilities($roleName, $config['permissions']);
            if (! empty($capabilities)) {
                $this->command->info('   Key Access:');
                foreach ($capabilities as $capability) {
                    $this->command->info("     • {$capability}");
                }
            }
            $this->command->newLine();
        }

        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('Usage:');
        $this->command->info('Assign roles to users: $user->assignRole(\'Owner\');');
        $this->command->info('For business-scoped roles, use team_id when assigning.');
        $this->command->info('═══════════════════════════════════════════════════════');
    }

    /**
     * Get key capabilities for a role
     */
    private function getKeyCapabilities(string $roleName, array $permissions): array
    {
        $capabilities = [];

        // Check for approval permissions
        $approvals = array_filter($permissions, fn ($p) => str_contains($p, 'approve'));
        if (! empty($approvals)) {
            $capabilities[] = 'Can approve: '.implode(', ', array_map(
                fn ($p) => str_replace('approve ', '', $p),
                $approvals
            ));
        }

        // Check for management permissions
        if (in_array('manage-users', $permissions)) {
            $capabilities[] = 'User & role management';
        }
        if (in_array('manage-branches', $permissions)) {
            $capabilities[] = 'Branch management';
        }

        // Check for financial access
        if (in_array('view financial reports', $permissions)) {
            $capabilities[] = 'Financial reports access';
        }

        // Check for shift management
        if (in_array('manage shifts', $permissions)) {
            $capabilities[] = 'Full shift management';
        } elseif (in_array('view user shift', $permissions) && ! in_array('view all shifts', $permissions)) {
            $capabilities[] = 'Own shift access only';
        }

        // Check for inventory write-off
        if (in_array('write off stock', $permissions)) {
            $capabilities[] = 'Stock write-off authority';
        }

        return $capabilities;
    }
}
