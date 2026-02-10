<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class SalesPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // Sales permissions
            'view sales',
            'create sales',
            'manage sales',

            // Customer permissions
            'view customers',
            'create customers',
            'edit customers',
            'delete customers',

            // Payment method permissions
            'view payment methods',
            'manage payment methods',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'api']
            );
        }

        $this->command->info('Sales system permissions created successfully.');
    }
}
