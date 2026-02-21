<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionController extends Controller
{
    use \App\Http\Traits\HasBranchAccess;

    // ==================== Permissions ====================

    public function listPermissions(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id');

        if ($businessId) {
            setPermissionsTeamId($businessId);
            $business = $user->businesses()
                ->where('businesses.id', $businessId)
                ->wherePivot('is_active', true)
                ->first();

            if (! $business) {
                return response()->json(['message' => 'Business not found or access denied'], 404);
            }

            if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage-roles')) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        $permissions = Permission::where('guard_name', 'api')
            ->orderBy('name')
            ->get()
            ->map(function (Permission $permission) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                ];
            });

        return response()->json(['data' => $permissions]);
    }

    // ==================== Roles ====================

    public function index(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Check if user is owner or has manage-roles permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage-roles')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $roles = Role::where('guard_name', 'api')
            ->where('business_id', $businessId)
            ->with('permissions')
            ->get()
            ->map(function (Role $role) use ($businessId) {
                $usersCount = DB::table('model_has_roles')
                    ->where('role_id', $role->id)
                    ->where('business_id', $businessId)
                    ->where('model_type', User::class)
                    ->count();

                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => $role->permissions->pluck('name'),
                    'users_count' => $usersCount,
                ];
            });

        return response()->json(['data' => $roles]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Check if user is owner (only owners can create roles)
        if ($business->owner_id !== $user->id) {
            return response()->json(['message' => 'Only business owners can create roles'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255', 'unique:roles,name,NULL,id,guard_name,api,business_id,'.$businessId],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name,guard_name,api'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Set team context for Spatie permission scoping
        app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($businessId);

        // Use query()->create() to bypass Spatie's internal findByParam which
        // incorrectly matches roles with NULL business_id (global/seeded roles).
        // The DB unique constraint on (business_id, name, guard_name) ensures correctness.
        $role = Role::query()->create([
            'name' => $data['name'],
            'guard_name' => 'api',
            'business_id' => $businessId,
        ]);

        // Clear cached permissions so the new role is recognized
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        if (! empty($data['permissions'])) {
            $permissions = Permission::whereIn('name', $data['permissions'])
                ->where('guard_name', 'api')
                ->get();
            $role->syncPermissions($permissions);
        }

        return response()->json([
            'message' => 'Role created',
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name'),
            ],
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Check if user is owner or has manage-roles permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage-roles')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $role = Role::where('id', $id)
            ->where('guard_name', 'api')
            ->where('business_id', $businessId)
            ->with('permissions')
            ->first();

        if (! $role) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        $userIds = DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('business_id', $businessId)
            ->where('model_type', User::class)
            ->pluck('model_id');

        $users = User::whereIn('id', $userIds)
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ];
            });

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name'),
                'users' => $users,
            ],
        ]);
    }

    public function update(Request $request, int $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        $role = Role::where('id', $id)
            ->where('guard_name', 'api')
            ->where('business_id', $businessId)
            ->first();

        if (! $role) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        // Check if user is owner (only owners can update roles)
        if ($business->owner_id !== $user->id) {
            return response()->json(['message' => 'Only business owners can update roles'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'name' => ['sometimes', 'string', 'max:255', 'unique:roles,name,'.$id.',id,guard_name,api,business_id,'.$businessId],
            'permissions' => ['sometimes', 'nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name,guard_name,api'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Set team context so Spatie scopes its internal checks to this business
        app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($businessId);

        if (isset($data['name'])) {
            $role->name = $data['name'];
            $role->save();
        }

        if (isset($data['permissions'])) {
            $permissions = Permission::whereIn('name', $data['permissions'])
                ->where('guard_name', 'api')
                ->get();
            $role->syncPermissions($permissions);
        }

        return response()->json([
            'message' => 'Role updated',
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name'),
            ],
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        $role = Role::where('id', $id)
            ->where('guard_name', 'api')
            ->where('business_id', $businessId)
            ->first();

        if (! $role) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        // Check if user is owner (only owners can delete roles)
        if ($business->owner_id !== $user->id) {
            return response()->json(['message' => 'Only business owners can delete roles'], 403);
        }

        // Delete role assignments first to avoid relationship issues
        DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('business_id', $businessId)
            ->delete();

        // Delete role permissions
        DB::table('role_has_permissions')
            ->where('role_id', $role->id)
            ->delete();

        // Clear permission cache before deletion
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Delete the role directly from database to avoid Spatie event issues
        DB::table('roles')
            ->where('id', $role->id)
            ->delete();

        return response()->json(['message' => 'Role deleted']);
    }

    // ==================== User Role Assignment ====================

    public function assignRoleToUser(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage-roles')) {
            return response()->json(['message' => 'You do not have permission to assign roles'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $targetUser = User::findOrFail($data['user_id']);

        // Verify target user is a member of the business
        $hasMembership = $targetUser->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->exists();

        if (! $hasMembership) {
            return response()->json([
                'message' => 'User is not a member of this business',
            ], 422);
        }

        $role = Role::where('id', $data['role_id'])
            ->where('guard_name', 'api')
            ->where('business_id', $businessId)
            ->first();

        if (! $role) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        // Verify branch belongs to business if provided
        if (! empty($data['branch_id'])) {
            $branch = $business->branches()->where('id', $data['branch_id'])->first();
            if (! $branch) {
                return response()->json(['message' => 'Branch not found or does not belong to this business'], 422);
            }
            if (! $this->userHasBranchAccess($user, $businessId, (int) $data['branch_id'])) {
                return response()->json(['message' => 'You do not have access to this branch'], 403);
            }
        }

        // Assign role with business context (team)
        // With teams, we need to insert directly into model_has_roles
        // Check if role already assigned to avoid duplicates
        $exists = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', $targetUser->id)
            ->where('role_id', $role->id)
            ->where('business_id', $businessId)
            ->exists();

        if (! $exists) {
            DB::table('model_has_roles')->insert([
                'role_id' => $role->id,
                'model_type' => User::class,
                'model_id' => $targetUser->id,
                'business_id' => $businessId,
                'branch_id' => $data['branch_id'] ?? null,
            ]);
        } elseif (! empty($data['branch_id'])) {
            // Update branch_id if role already assigned
            DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->where('model_id', $targetUser->id)
                ->where('role_id', $role->id)
                ->where('business_id', $businessId)
                ->update(['branch_id' => $data['branch_id']]);
        }

        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json([
            'message' => 'Role assigned to user',
            'data' => [
                'user' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                ],
                'role' => [
                    'id' => $role->id,
                    'name' => $role->name,
                ],
            ],
        ]);
    }

    public function removeRoleFromUser(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Check if user is owner or has manage-roles permission
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage-roles', 'api', $businessId)) {
            return response()->json(['message' => 'You do not have permission to remove roles'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $targetUser = User::findOrFail($data['user_id']);
        $role = Role::where('id', $data['role_id'])
            ->where('guard_name', 'api')
            ->where('business_id', $businessId)
            ->first();

        if (! $role) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        // Remove role using direct DB query (more reliable with teams)
        DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', $targetUser->id)
            ->where('role_id', $role->id)
            ->where('business_id', $businessId)
            ->delete();

        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['message' => 'Role removed from user']);
    }

    public function getUserRoles(Request $request, int $userId)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Check if user is owner or has manage-roles permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage-roles')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $targetUser = User::findOrFail($userId);

        // Verify target user is a member of the business
        $hasMembership = $targetUser->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->exists();

        if (! $hasMembership) {
            return response()->json([
                'message' => 'User is not a member of this business',
            ], 404);
        }

        // Get role IDs for this business
        $roleIds = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', $targetUser->id)
            ->where('business_id', $businessId)
            ->pluck('role_id');

        $roles = Role::whereIn('id', $roleIds)
            ->where('business_id', $businessId)
            ->with('permissions')
            ->get()
            ->map(function (Role $role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => $role->permissions->pluck('name'),
                ];
            });

        // Collect all unique permissions from user's roles for this business
        $permissions = Role::whereIn('id', $roleIds)
            ->where('business_id', $businessId)
            ->with('permissions')
            ->get()
            ->flatMap(function (Role $role) {
                return $role->permissions;
            })
            ->pluck('name')
            ->unique()
            ->values();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                ],
                'roles' => $roles,
                'permissions' => $permissions,
            ],
        ]);
    }

    public function addPermissionToRole(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Check if user is owner or has manage-roles permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage-roles')) {
            return response()->json(['message' => 'You do not have permission to manage role permissions'], 403);
        }

        $data = $request->all();

        $validator = Validator::make($data, [
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'permission_name' => ['required', 'array'],
            'permission_name.*' => ['required', 'string', 'exists:permissions,name,guard_name,api'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Scope role to business to prevent cross-business access
        $role = Role::where('id', $data['role_id'])
            ->where('guard_name', 'api')
            ->where('business_id', $businessId)
            ->first();

        if (! $role) {
            return response()->json(['message' => 'Role not found in this business'], 404);
        }

        $permissions = Permission::whereIn('name', $data['permission_name'])
            ->where('guard_name', 'api')
            ->get();

        if ($permissions->isEmpty()) {
            return response()->json(['message' => 'No valid permissions found'], 404);
        }

        if ($role->hasAllPermissions($permissions)) {
            return response()->json(['message' => 'Role already has the specified permissions'], 422);
        }
        $role->givePermissionTo($permissions);

        return response()->json([
            'message' => 'Permissions added to role',
            'data' => [
                'role' => [
                    'id' => $role->id,
                    'name' => $role->name,
                ],
                'permissions' => $permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                    ];
                }),
            ],
        ]);
    }

    public function removePermissionFromRole(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Check if user is owner or has manage-roles permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage-roles')) {
            return response()->json(['message' => 'You do not have permission to manage role permissions'], 403);
        }

        $data = $request->all();

        $validator = Validator::make($data, [
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'permission_name' => ['required', 'array'],
            'permission_name.*' => ['required', 'string', 'exists:permissions,name,guard_name,api'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Scope role to business to prevent cross-business access
        $role = Role::where('id', $data['role_id'])
            ->where('guard_name', 'api')
            ->where('business_id', $businessId)
            ->first();

        if (! $role) {
            return response()->json(['message' => 'Role not found in this business'], 404);
        }

        $permissions = Permission::whereIn('name', $data['permission_name'])
            ->where('guard_name', 'api')
            ->get();

        if ($permissions->isEmpty()) {
            return response()->json(['message' => 'No valid permissions found'], 404);
        }

        // Check if role has any of the permissions
        $hasAnyPermission = false;
        foreach ($permissions as $permission) {
            if ($role->hasPermissionTo($permission)) {
                $hasAnyPermission = true;
                break;
            }
        }

        if (! $hasAnyPermission) {
            return response()->json(['message' => 'Role does not have any of the specified permissions'], 422);
        }

        $role->revokePermissionTo($permissions);

        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json([
            'message' => 'Permissions removed from role',
            'data' => [
                'role' => [
                    'id' => $role->id,
                    'name' => $role->name,
                ],
                'removed_permissions' => $permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                    ];
                }),
            ],
        ]);
    }
}
