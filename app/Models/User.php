<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $guard_name = 'api';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'pin_code',
        'profile_image',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = ['profile_image_url'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'pin_code',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the full URL for the user's profile image.
     * Uses the app URL (e.g. posbackend-main-a1gh7m.laravel.cloud) so images are served by this app.
     */
    public function getProfileImageUrlAttribute(): ?string
    {
        if (! $this->profile_image) {
            return null;
        }

        $base = rtrim(config('app.url', 'http://localhost'), '/');

        return $base.'/profile-images/'.basename($this->profile_image);
    }

    /**
     * Get businesses the user belongs to
     */
    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'user__businesses')
            ->withPivot('is_active')
            ->withTimestamps();
    }

    /**
     * Get active businesses for the user
     */
    public function activeBusinesses(): BelongsToMany
    {
        return $this->businesses()->wherePivot('is_active', true);
    }

    /**
     * Check if user has role in specific business
     */
    public function hasRoleInBusiness(string $role, int $businessId): bool
    {
        return $this->hasRole($role, $businessId);
    }

    /**
     * Check if user has permission in specific business
     */
    public function hasPermissionInBusiness(string $permission, int $businessId): bool
    {
        return $this->hasPermissionTo($permission, $businessId);
    }

    /**
     * Get user's role in a specific business
     */
    public function getRoleInBusiness(int $businessId): ?string
    {
        $membership = $this->businesses()->where('businesses.id', $businessId)->first();

        return $membership?->pivot->role;
    }

    /**
     * Check if user has a permission in a specific branch within a business context.
     * This checks permissions from roles that are assigned to the user for the specific branch.
     *
     * @param  string  $permission  The permission name to check
     * @param  int  $businessId  The business ID context
     * @param  int|null  $branchId  The branch ID to check (null checks for business-wide permissions)
     */
    public function hasPermissionInBranch(string $permission, int $businessId, ?int $branchId = null): bool
    {
        // Get all role IDs assigned to this user in the specified business and branch
        $roleIdsQuery = \DB::table('model_has_roles')
            ->where('model_type', self::class)
            ->where('model_id', $this->id)
            ->where('business_id', $businessId);

        if ($branchId !== null) {
            // Check for branch-specific roles or business-wide roles (branch_id is null)
            $roleIdsQuery->where(function ($query) use ($branchId) {
                $query->where('branch_id', $branchId)
                    ->orWhereNull('branch_id');
            });
        } else {
            // Only check business-wide roles (no branch assignment)
            $roleIdsQuery->whereNull('branch_id');
        }

        $roleIds = $roleIdsQuery->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return false;
        }

        // Get permission ID
        $permissionId = \DB::table('permissions')
            ->where('name', $permission)
            ->where('guard_name', 'api')
            ->value('id');

        if (! $permissionId) {
            return false;
        }

        // Check if any of the user's roles have this permission
        return \DB::table('role_has_permissions')
            ->whereIn('role_id', $roleIds)
            ->where('permission_id', $permissionId)
            ->exists();
    }

    /**
     * Get all permissions for a user in a specific branch.
     * Returns unique permissions from all roles assigned to the user in that branch.
     *
     * @param  int  $businessId  The business ID context
     * @param  int|null  $branchId  The branch ID (null for business-wide)
     * @return \Illuminate\Support\Collection Collection of permission names
     */
    public function getPermissionsInBranch(int $businessId, ?int $branchId = null): \Illuminate\Support\Collection
    {
        // Get all role IDs assigned to this user in the specified business and branch
        $roleIdsQuery = \DB::table('model_has_roles')
            ->where('model_type', self::class)
            ->where('model_id', $this->id)
            ->where('business_id', $businessId);

        if ($branchId !== null) {
            $roleIdsQuery->where(function ($query) use ($branchId) {
                $query->where('branch_id', $branchId)
                    ->orWhereNull('branch_id');
            });
        } else {
            $roleIdsQuery->whereNull('branch_id');
        }

        $roleIds = $roleIdsQuery->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return collect([]);
        }

        // Get all permissions for these roles
        return \DB::table('role_has_permissions')
            ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
            ->whereIn('role_has_permissions.role_id', $roleIds)
            ->where('permissions.guard_name', 'api')
            ->pluck('permissions.name')
            ->unique()
            ->values();
    }

    /**
     * Get all branches where the user has a specific role in a business.
     *
     * @param  int  $businessId  The business ID context
     * @param  string|null  $roleName  Optional role name to filter by
     * @return \Illuminate\Support\Collection Collection of branch IDs
     */
    public function getBranchesInBusiness(int $businessId, ?string $roleName = null): \Illuminate\Support\Collection
    {
        $query = \DB::table('model_has_roles')
            ->where('model_type', self::class)
            ->where('model_id', $this->id)
            ->where('business_id', $businessId)
            ->whereNotNull('branch_id');

        if ($roleName) {
            $roleId = \DB::table('roles')
                ->where('name', $roleName)
                ->where('guard_name', 'api')
                ->where('business_id', $businessId)
                ->value('id');

            if ($roleId) {
                $query->where('role_id', $roleId);
            } else {
                return collect([]);
            }
        }

        return $query->pluck('branch_id')->unique()->values();
    }
}
