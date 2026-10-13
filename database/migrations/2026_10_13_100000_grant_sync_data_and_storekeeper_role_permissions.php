<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data migration: POS terminals sync as Cashier / Supervisor / Storekeeper users, but the
 * default role templates never granted `sync data`, so /sync/* answered 403 and offline
 * sales never uploaded. Grants `sync data` to the existing POS roles of every business and
 * makes sure each business has a Storekeeper role with the storekeeper permission set.
 *
 * Idempotent: every grant is an insert-if-missing, so it is safe to re-run.
 */
return new class extends Migration
{
    private const GUARD = 'api';

    /** Role names (lowercase) that run POS terminals and need `sync data`. */
    private const SYNC_ROLE_NAMES = ['cashier', 'supervisor', 'manager', 'storekeeper'];

    /** Kept in step with the Storekeeper template in BusinessController::createDefaultRoles. */
    private const STOREKEEPER_PERMISSIONS = [
        'view products',
        'create products',
        'edit products',
        'manage branch products',
        'view inventory',
        'manage inventory',
        'adjust inventory',
        'view categories',
        'create categories',
        'view batches',
        'manage batches',
        'request shelf store move',
        'request stock transfer',
        'accept stock transfer',
        'write off stock',
        'sync data',
        'use-pin-login',
    ];

    public function up(): void
    {
        $tables = config('permission.table_names');
        $teamKey = config('permission.column_names.team_foreign_key', 'business_id');

        $permissionIds = [];
        foreach (self::STOREKEEPER_PERMISSIONS as $name) {
            $permissionIds[$name] = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => self::GUARD,
            ])->id;
        }
        $syncDataId = $permissionIds['sync data'];

        $businessIds = DB::table('businesses')->pluck('id');

        foreach ($businessIds as $businessId) {
            $roles = DB::table($tables['roles'])
                ->where($teamKey, $businessId)
                ->where('guard_name', self::GUARD)
                ->whereIn(DB::raw('LOWER(name)'), self::SYNC_ROLE_NAMES)
                ->get(['id', 'name']);

            foreach ($roles as $role) {
                $this->grant($tables['role_has_permissions'], (int) $role->id, [$syncDataId]);
            }

            $storekeeperRoles = $roles->filter(fn (object $role): bool => strtolower($role->name) === 'storekeeper');

            if ($storekeeperRoles->isEmpty()) {
                $roleId = DB::table($tables['roles'])->insertGetId([
                    'name' => 'Storekeeper',
                    'guard_name' => self::GUARD,
                    $teamKey => $businessId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $storekeeperRoles = collect([(object) ['id' => $roleId, 'name' => 'Storekeeper']]);
            }

            foreach ($storekeeperRoles as $role) {
                $this->grant($tables['role_has_permissions'], (int) $role->id, array_values($permissionIds));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Non-destructive: roles and grants may have been edited by the business since.
    }

    /**
     * @param  array<int, int>  $permissionIds
     */
    private function grant(string $pivotTable, int $roleId, array $permissionIds): void
    {
        $existing = DB::table($pivotTable)
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $permissionIds)
            ->pluck('permission_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $missing = array_diff($permissionIds, $existing);
        if ($missing === []) {
            return;
        }

        DB::table($pivotTable)->insert(array_map(
            fn (int $permissionId): array => ['permission_id' => $permissionId, 'role_id' => $roleId],
            array_values($missing)
        ));
    }
};
