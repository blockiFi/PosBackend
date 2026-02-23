<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class ShelfStoreMovePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            'request shelf store move',
            'approve shelf store move',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'api'],
                ['name' => $permission, 'guard_name' => 'api']
            );
        }

        $this->command->info('Shelf/store move permissions created successfully!');
    }
}
