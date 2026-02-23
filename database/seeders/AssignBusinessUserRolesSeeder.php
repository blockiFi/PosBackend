<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * One-off seeder to assign roles to existing business users who don't have a role yet.
 * Run with: php artisan db:seed --class=AssignBusinessUserRolesSeeder
 */
class AssignBusinessUserRolesSeeder extends Seeder
{
    /**
     * Demo user email -> role name (for known seeded users).
     *
     * @var array<string, string>
     */
    private const DEMO_EMAIL_ROLE_MAP = [
        'owner@acmeretail.com' => 'Owner',
        'admin@acmeretail.com' => 'Manager',
        'john.manager@acmeretail.com' => 'Manager',
        'jane.manager@acmeretail.com' => 'Manager',
        'cashier1@acmeretail.com' => 'Cashier',
        'cashier2@acmeretail.com' => 'Cashier',
        'cashier3@acmeretail.com' => 'Cashier',
        'cashier4@acmeretail.com' => 'Cashier',
        'owner@supermart.com' => 'Owner',
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $businesses = Business::all();

        foreach ($businesses as $business) {
            $this->assignRolesForBusiness($business);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->command->info('Business user roles assignment completed.');
    }

    private function assignRolesForBusiness(Business $business): void
    {
        app()[PermissionRegistrar::class]->setPermissionsTeamId($business->id);

        $members = $business->users()
            ->wherePivot('is_active', true)
            ->get();

        $assigned = 0;
        foreach ($members as $user) {
            if ($this->userAlreadyHasRoleForBusiness($user->id, $business->id)) {
                continue;
            }

            $roleName = $this->resolveRoleForUser($user, $business);
            if (! $roleName) {
                continue;
            }

            $user->assignRole($roleName);
            $assigned++;
            $this->command->info("  Assigned {$roleName} to {$user->email} in {$business->name}");
        }

        if ($assigned > 0) {
            $this->command->info("  {$business->name}: {$assigned} role(s) assigned");
        }
    }

    private function userAlreadyHasRoleForBusiness(int $userId, int $businessId): bool
    {
        return DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', $userId)
            ->where('business_id', $businessId)
            ->exists();
    }

    private function resolveRoleForUser(User $user, Business $business): ?string
    {
        if ((int) $business->owner_id === (int) $user->id) {
            return 'Owner';
        }

        return self::DEMO_EMAIL_ROLE_MAP[$user->email] ?? 'Cashier';
    }
}
