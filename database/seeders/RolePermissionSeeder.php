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

        // Create permissions (not team-specific, will be scoped via roles).
        // Keep both current space-based permissions and legacy dashed aliases.
        $permissions = [
            // Categories
            'view categories', 'create categories', 'edit categories', 'delete categories',

            // Products
            'view products', 'create products', 'edit products', 'delete products',
            'manage branch products', 'set branch product selling price',
            'update product price', 'update base selling price', 'override sale price',

            // Inventory & batches
            'view inventory', 'manage inventory', 'adjust inventory',
            'view batches', 'manage batches',

            // Sales, customers, payment methods
            'view sales', 'create sales', 'manage sales',
            'view customers', 'create customers', 'edit customers', 'delete customers',
            'view payment methods', 'manage payment methods',

            // Shifts
            'view user shift', 'view all shifts', 'create shift', 'close shift', 'manage shifts',

            // Authentication
            'use-pin-login', 'manage-pin-codes',

            // Workflows
            'request refund', 'approve refund',
            'request quick sale', 'approve quick sale',
            'request stock transfer', 'approve stock transfer', 'accept stock transfer',
            'request shelf store move', 'approve shelf store move',
            'write off stock',

            // Analytics
            'view analytics', 'view financial reports', 'view branch analytics', 'export analytics',

            // Branch + sync
            'view-branches', 'manage-branches',
            'manage server sync', 'sync data',

            // Users, settings, reports, cash
            'manage-users', 'manage-settings', 'manage-roles', 'set user password',
            'view-reports', 'export-reports',
            'open-register', 'close-register', 'manage-cash',

            // Legacy dashed aliases
            'create-sales', 'view-sales', 'edit-sales', 'delete-sales', 'refund-sales',
            'create-products', 'view-products', 'edit-products', 'delete-products',
            'manage-inventory', 'view-inventory', 'adjust-inventory',
            'create-customers', 'view-customers', 'edit-customers', 'delete-customers',
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
