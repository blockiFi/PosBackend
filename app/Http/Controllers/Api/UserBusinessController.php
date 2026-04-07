<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\Business;
use App\Models\User;
use App\Services\ProfileImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserBusinessController extends Controller
{
    use HasBranchAccess;

    /**
     * List all users in a business
     */
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

        // Check permission - owner or user with manage-users permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage-users')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = $business->users()->withPivot('is_active', 'created_at');

        // Optional branch filter: only users with a role in this branch (or business-wide)
        if ($request->filled('branch_id')) {
            $branchId = (int) $request->branch_id;
            $branch = $business->branches()->find($branchId);
            if (! $branch) {
                return response()->json(['message' => 'Branch not found or does not belong to this business'], 404);
            }
            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json(['message' => 'Unauthorized access to this branch'], 403);
            }
            $userIdsInBranch = DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->where('business_id', $businessId)
                ->where(function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId)->orWhereNull('branch_id');
                })
                ->distinct()
                ->pluck('model_id');
            $query->whereIn('users.id', $userIdsInBranch);
        }

        // Get all users in this business with their pivot data
        $users = $query->get()->map(function (User $user) use ($businessId) {
            // Get user's roles in this business
            $roleIds = DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->where('model_id', $user->id)
                ->where('business_id', $businessId)
                ->pluck('role_id');

            $roles = DB::table('roles')
                ->whereIn('id', $roleIds)
                ->where('business_id', $businessId)
                ->get(['id', 'name']);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'profile_image' => $user->profile_image,
                'profile_image_url' => $user->profile_image_url,
                'is_active' => $user->pivot->is_active,
                'joined_at' => $user->pivot->created_at,
                'roles' => $roles,
            ];
        });

        return response()->json(['data' => $users]);
    }

    /**
     * Add a user to a business
     */
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

        // Permission: allow owners OR users with 'manage-users' permission for this business
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage-users')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'profile_image' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Check if user exists by email
        $targetUser = User::where('email', $data['email'])->first();
        $isNewUser = false;
        $generatedPassword = null;

        if (! $targetUser) {
            // User doesn't exist, create new user with random password
            $generatedPassword = Str::random(16);

            $profileImagePath = null;
            if ($request->hasFile('profile_image')) {
                $profileImagePath = app(ProfileImageService::class)->store($request->file('profile_image'));
            }

            $targetUser = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($generatedPassword),
                'profile_image' => $profileImagePath,
            ]);

            $isNewUser = true;
        }

        // Check if user is already a member
        $exists = $business->users()
            ->where('users.id', $targetUser->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'User is already a member of this business',
            ], 422);
        }

        // Add user to business
        $business->users()->attach($targetUser->id, [
            'is_active' => $data['is_active'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignedRoles = [];
        if (! empty($data['role_ids'])) {
            $businessRoles = Role::where('business_id', $businessId)
                ->where('guard_name', 'api')
                ->whereIn('id', $data['role_ids'])
                ->get();

            foreach ($businessRoles as $role) {
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
                        'branch_id' => null,
                    ]);
                    $assignedRoles[] = ['id' => $role->id, 'name' => $role->name];
                }
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $generatedPin = null;
        $hasCashierRole = collect($assignedRoles)->contains('name', 'Cashier');
        if ($hasCashierRole && ! $targetUser->pin_code) {
            $generatedPin = $this->generateUniquePinCode();
            $targetUser->update(['pin_code' => $generatedPin]);
        }

        $responseData = [
            'user' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'profile_image' => $targetUser->profile_image,
                'profile_image_url' => $targetUser->profile_image_url,
            ],
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
            ],
            'is_active' => $data['is_active'] ?? true,
            'is_new_user' => $isNewUser,
            'roles' => $assignedRoles,
        ];

        if ($isNewUser && $generatedPassword !== null) {
            $responseData['password'] = $generatedPassword;
        }

        if ($generatedPin !== null) {
            $responseData['pin_code'] = $generatedPin;
        }

        return response()->json([
            'message' => $isNewUser
                ? 'New user created and added to business. Share the password with the user for first login; they can reset it later.'
                : 'User added to business',
            'data' => $responseData,
        ], 201);
    }

    /**
     * Update user's status in a business
     */
    public function update(Request $request, int $userId)
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

        // Check if user is owner (only owners can update users)
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage-users')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }


        $targetUser = User::findOrFail($userId);

        // Check if target user is a member
        $exists = $business->users()
            ->where('users.id', $targetUser->id)
            ->exists();

        if (! $exists) {
            return response()->json([
                'message' => 'User is not a member of this business',
            ], 404);
        }

        // Prevent owner from deactivating themselves
        if ($targetUser->id === $business->owner_id && $request->input('is_active') === false) {
            return response()->json([
                'message' => 'Business owner cannot deactivate themselves',
            ], 422);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'is_active' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Update user status
        $business->users()->updateExistingPivot($targetUser->id, [
            'is_active' => $data['is_active'],
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'User status updated',
            'data' => [
                'user' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                ],
                'is_active' => $data['is_active'],
            ],
        ]);
    }

    /**
     * Set password for a user in the business (requires "set user password" permission or owner).
     */
    public function setPassword(Request $request, int $userId): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('set user password')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ], [
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $targetUser = User::find($userId);
        if (! $targetUser) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $exists = $business->users()->where('users.id', $targetUser->id)->exists();
        if (! $exists) {
            return response()->json([
                'message' => 'User is not a member of this business',
            ], 404);
        }

        $passwordProvided = $request->filled('password');
        $newPassword = $passwordProvided
            ? $request->input('password')
            : Str::random(16);

        $targetUser->update([
            'password' => Hash::make($newPassword),
        ]);

        $data = [
            'user' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
            ],
        ];
        if (! $passwordProvided) {
            $data['password'] = $newPassword;
        }

        return response()->json([
            'message' => 'Password updated successfully',
            'data' => $data,
        ]);
    }

    /**
     * Remove a user from a business
     */
    public function destroy(Request $request, int $userId)
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

        // Check if user is owner (only owners can remove users)
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage-users')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }


        $targetUser = User::findOrFail($userId);

        // Check if target user is a member
        $exists = $business->users()
            ->where('users.id', $targetUser->id)
            ->exists();

        if (! $exists) {
            return response()->json([
                'message' => 'User is not a member of this business',
            ], 404);
        }

        // Prevent owner from removing themselves
        if ($targetUser->id === $business->owner_id) {
            return response()->json([
                'message' => 'Business owner cannot remove themselves from the business',
            ], 422);
        }

        // Remove all role assignments for this user in this business
        DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', $targetUser->id)
            ->where('business_id', $businessId)
            ->delete();

        // Remove user from business
        $business->users()->detach($targetUser->id);

        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json(['message' => 'User removed from business']);
    }

    /**
     * Get a specific user's details in a business
     */
    public function show(Request $request, int $userId)
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

        // Check permission - owner, manage-users, or viewing self
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage-users') && $user->id !== $userId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $targetUser = $business->users()
            ->where('users.id', $userId)
            ->withPivot('is_active', 'created_at', 'updated_at')
            ->first();

        if (! $targetUser) {
            return response()->json([
                'message' => 'User is not a member of this business',
            ], 404);
        }

        // Get user's roles in this business
        $roleIds = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', $targetUser->id)
            ->where('business_id', $businessId)
            ->pluck('role_id');

        $roles = DB::table('roles')
            ->whereIn('id', $roleIds)
            ->where('business_id', $businessId)
            ->get(['id', 'name']);

        // Get all permissions through roles
        $permissions = DB::table('role_has_permissions')
            ->whereIn('role_id', $roleIds)
            ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
            ->distinct()
            ->pluck('permissions.name');

        return response()->json([
            'data' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'is_active' => $targetUser->pivot->is_active,
                'is_owner' => $targetUser->id === $business->owner_id,
                'joined_at' => $targetUser->pivot->created_at,
                'updated_at' => $targetUser->pivot->updated_at,
                'roles' => $roles,
                'permissions' => $permissions->values(),
            ],
        ]);
    }

    /**
     * Generate a unique 6-digit PIN code not already assigned to any user.
     */
    private function generateUniquePinCode(): string
    {
        $maxAttempts = 100;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            if (! User::where('pin_code', $pin)->exists()) {
                return $pin;
            }
        }

        throw new \RuntimeException('Unable to generate unique PIN code after '.$maxAttempts.' attempts.');
    }
}
