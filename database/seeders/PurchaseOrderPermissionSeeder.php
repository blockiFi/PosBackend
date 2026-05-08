<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PurchaseOrderPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'manage purchase orders',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'api',
            ]);
        }

        $this->command?->info('Purchase order permissions created successfully.');
    }
}
