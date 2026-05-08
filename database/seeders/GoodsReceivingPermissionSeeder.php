<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class GoodsReceivingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'view grn',
            'create grn',
            'approve grn',
            'manage suppliers',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'api',
            ]);
        }

        $this->command?->info('Goods receiving permissions created successfully.');
    }
}
