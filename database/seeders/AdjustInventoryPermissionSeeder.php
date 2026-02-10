<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class AdjustInventoryPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create the adjust inventory permission with api guard
        Permission::firstOrCreate(
            ['name' => 'adjust inventory', 'guard_name' => 'api'],
            ['name' => 'adjust inventory', 'guard_name' => 'api']
        );

        $this->command->info('Adjust inventory permission created successfully!');
    }
}
