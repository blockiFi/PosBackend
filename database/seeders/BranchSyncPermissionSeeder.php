<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class BranchSyncPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command->info('Creating branch and sync permissions...');

        $permissions = [
            'view-branches' => 'View branch list and details',
            'manage-branches' => 'Create, update, and delete branches',
            'manage server sync' => 'Manage server-to-server synchronization',
            'sync data' => 'Synchronize data between client and server',
        ];

        foreach ($permissions as $name => $description) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'api',
            ]);
            $this->command->info("  ✓ Created permission: {$name}");
        }

        $this->command->newLine();
        $this->command->info('Branch and sync permissions created successfully!');
    }
}
