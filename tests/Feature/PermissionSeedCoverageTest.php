<?php

namespace Tests\Feature;

use Database\Seeders\AdjustInventoryPermissionSeeder;
use Database\Seeders\AnalyticsPermissionSeeder;
use Database\Seeders\BatchPermissionSeeder;
use Database\Seeders\BranchSyncPermissionSeeder;
use Database\Seeders\CategoryPermissionSeeder;
use Database\Seeders\DeviceGroupPermissionSeeder;
use Database\Seeders\GoodsReceivingPermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\ProductPermissionSeeder;
use Database\Seeders\PurchaseOrderPermissionSeeder;
use Database\Seeders\QuickSalePermissionSeeder;
use Database\Seeders\RefundPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Database\Seeders\ShelfStoreMovePermissionSeeder;
use Database\Seeders\ShiftPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Spatie throws `PermissionDoesNotExist` (a 500) when code checks a permission
 * that was never created, so every name the app checks must be seeded.
 */
class PermissionSeedCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_permission_checked_in_app_code_is_created_by_the_seeders(): void
    {
        $this->seed([
            CategoryPermissionSeeder::class,
            ProductPermissionSeeder::class,
            InventoryPermissionSeeder::class,
            BatchPermissionSeeder::class,
            GoodsReceivingPermissionSeeder::class,
            PurchaseOrderPermissionSeeder::class,
            SalesPermissionSeeder::class,
            ShiftPermissionSeeder::class,
            DeviceGroupPermissionSeeder::class,
            QuickSalePermissionSeeder::class,
            RefundPermissionSeeder::class,
            ShelfStoreMovePermissionSeeder::class,
            AdjustInventoryPermissionSeeder::class,
            AnalyticsPermissionSeeder::class,
            BranchSyncPermissionSeeder::class,
            RolePermissionSeeder::class,
        ]);

        $referenced = $this->permissionNamesReferencedIn(app_path());
        $this->assertNotEmpty($referenced);

        $seeded = Permission::query()->where('guard_name', 'api')->pluck('name')->all();

        $this->assertSame([], array_values(array_diff($referenced, $seeded)), 'Permissions checked in code but never seeded');
    }

    /**
     * @return list<string>
     */
    private function permissionNamesReferencedIn(string $directory): array
    {
        $names = [];
        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                '/(?:hasPermissionTo|hasAnyPermission|checkPermissionTo|->can)\(\s*[\'"]([^\'"]+)[\'"]/',
                $file->getContents(),
                $matches,
            );
            array_push($names, ...$matches[1]);
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
