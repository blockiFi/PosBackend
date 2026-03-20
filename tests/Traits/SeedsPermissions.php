<?php

namespace Tests\Traits;

use Spatie\Permission\Models\Permission;

trait SeedsPermissions
{
    protected function seedAllPermissions(): void
    {
        $permissions = [
            // Sales
            'create-sales', 'view-sales', 'edit-sales', 'delete-sales', 'refund-sales',
            'create sales', 'view sales', 'manage sales',

            // Products
            'create-products', 'view-products', 'edit-products', 'delete-products',
            'create products', 'view products', 'edit products', 'delete products',
            'manage branch products', 'update product price', 'override sale price', 'set branch product selling price',

            // Categories
            'view categories', 'create categories', 'edit categories', 'delete categories',

            // Inventory
            'manage-inventory', 'view-inventory', 'adjust-inventory',
            'manage inventory', 'view inventory', 'adjust inventory',

            // Batches
            'view batches', 'manage batches',

            // Customers
            'create-customers', 'view-customers', 'edit-customers', 'delete-customers',
            'create customers', 'view customers', 'edit customers', 'delete customers',

            // Payment Methods
            'view payment methods', 'manage payment methods',

            // Reports & Analytics
            'view-reports', 'export-reports',
            'view analytics', 'view financial reports', 'view branch analytics', 'export analytics',

            // Users & Settings
            'manage-users', 'manage-branches', 'manage-settings', 'manage-roles', 'set user password',

            // Cash Management
            'open-register', 'close-register', 'manage-cash',

            // Shifts
            'view shifts', 'manage shifts', 'view user shift', 'view all shifts',
            'create shift', 'close shift',

            // Authentication
            'use-pin-login', 'manage-pin-codes',

            // Stock Transfer Workflow
            'request stock transfer', 'approve stock transfer',

            // Shelf/Store Move
            'request shelf store move', 'approve shelf store move',

            // Stock Write-offs
            'write off stock',

            // Refunds
            'request refund', 'approve refund',

            // Quick Sales
            'request quick sale', 'approve quick sale',

            // Branch Management (NEW)
            'view-branches',
            'manage-branches',

            // Sync Operations (NEW)
            'manage server sync',
            'sync data',
        ];

        foreach (array_unique($permissions) as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'api',
            ]);
        }
    }

    protected function seedBranchPermissions(): void
    {
        foreach (['view-branches', 'manage-branches'] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'api',
            ]);
        }
    }

    protected function seedSyncPermissions(): void
    {
        foreach (['manage server sync', 'sync data'] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'api',
            ]);
        }
    }

    protected function ensurePermissionExists(string $permission): void
    {
        Permission::firstOrCreate([
            'name' => $permission,
            'guard_name' => 'api',
        ]);
    }
}
