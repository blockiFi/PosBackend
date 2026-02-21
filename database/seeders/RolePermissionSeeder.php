<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions (not team-specific, will be scoped via roles)
        $permissions = [
            // Sales
            'create-sales', 'view-sales', 'edit-sales', 'delete-sales', 'refund-sales',

            // Products
            'create-products', 'view-products', 'edit-products', 'delete-products', 'manage branch products', 'set branch product selling price',

            // Inventory
            'manage-inventory', 'view-inventory', 'adjust-inventory',

            // Customers
            'create-customers', 'view-customers', 'edit-customers', 'delete-customers',

            // Reports
            'view-reports', 'export-reports',

            // Users & Settings
            'manage-users', 'manage-branches', 'manage-settings', 'manage-roles',

            // Cash Management
            'open-register', 'close-register', 'manage-cash',

            // Authentication
            'use-pin-login', 'manage-pin-codes',

            // Stock Transfer Workflow
            'request stock transfer', 'approve stock transfer',

            // Stock Write-offs
            'write off stock',

            // Branch Management
            'view-branches',
            'manage-branches',

            // Sync Operations
            'manage server sync',
            'sync data',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'api']
            );
        }

        // Note: Roles will be created per business when needed
        // Define role templates for reference
        $roleTemplates = [
            'owner' => Permission::all()->pluck('name')->toArray(),
            'admin' => Permission::all()->pluck('name')->toArray(),
            'manager' => [
                'create-sales', 'view-sales', 'edit-sales', 'refund-sales',
                'create-products', 'view-products', 'edit-products',
                'manage-inventory', 'view-inventory', 'adjust-inventory',
                'create-customers', 'view-customers', 'edit-customers',
                'view-reports', 'export-reports',
                'manage-cash', 'open-register', 'close-register',
                'view-branches', 'manage-branches',
                'sync data',
            ],
            'cashier' => [
                'create-sales', 'view-sales', 'refund-sales',
                'view-products', 'view-customers', 'create-customers',
                'open-register', 'close-register',
                'view-branches',
                'sync data',
            ],
            'staff' => [
                'create-sales', 'view-sales',
                'view-products', 'view-customers',
                'view-branches',
                'sync data',
            ],
        ];

        // Store role templates in config for later use
        config(['pos.role_templates' => $roleTemplates]);
    }
}
