<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data migration: the POS supervisor Write-Off screen lists expired batches and writes them
 * off, but the default Supervisor role had neither `view batches` nor `write off stock`, so
 * the screen showed "Error loading expired batches". Grants both to every business's
 * Supervisor role.
 *
 * Idempotent: every grant is an insert-if-missing, so it is safe to re-run.
 */
return new class extends Migration
{
    private const GUARD = 'api';

    private const PERMISSIONS = ['view batches', 'write off stock'];

    public function up(): void
    {
        $tables = config('permission.table_names');

        $permissionIds = array_map(
            fn (string $name): int => Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => self::GUARD,
            ])->id,
            self::PERMISSIONS
        );

        $roleIds = DB::table($tables['roles'])
            ->where('guard_name', self::GUARD)
            ->where(DB::raw('LOWER(name)'), 'supervisor')
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            $existing = DB::table($tables['role_has_permissions'])
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $permissionIds)
                ->pluck('permission_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $missing = array_diff($permissionIds, $existing);
            if ($missing === []) {
                continue;
            }

            DB::table($tables['role_has_permissions'])->insert(array_map(
                fn (int $permissionId): array => ['permission_id' => $permissionId, 'role_id' => (int) $roleId],
                array_values($missing)
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Non-destructive: roles and grants may have been edited by the business since.
    }
};
