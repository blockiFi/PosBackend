<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class QuickSalePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            'request quick sale',
            'approve quick sale',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'api'],
                ['name' => $permission, 'guard_name' => 'api']
            );
        }

        $this->command->info('Quick sale permissions created successfully!');
    }
}
