<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class UserBusinessController extends Controller
{
    /**
     * List all users in a business
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $businessId = $request->input('current_business_id') ?? $request->input('business_id');

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

        // Get all users in this business with their pivot data
        $users = $business->users()
            ->withPivot('is_active', 'created_at')
            ->get()
            ->map(function (User $user) use ($businessId) {
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
        $businessId = $request->input('current_business_id') ?? $request->input('business_id');

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

        // Check if user is owner (only owners can add users)
        if ($business->owner_id !== $user->id) {
            return response()->json(['message' => 'Only business owners can add users'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
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

        if (! $targetUser) {
            // User doesn't exist, create new user with random password
            $randomPassword = Str::random(16);
            
            $targetUser = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($randomPassword),
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

        return response()->json([
            'message' => $isNewUser 
                ? 'New user created and added to business. User can reset password to login.' 
                : 'User added to business',
            'data' => [
                'user' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                ],
                'business' => [
                    'id' => $business->id,
                    'name' => $business->name,
                ],
                'is_active' => $data['is_active'] ?? true,
                'is_new_user' => $isNewUser,
            ],
        ], 201);
    }

    /**
     * Update user's status in a business
     */
    public function update(Request $request, int $userId)
    {
        $user = $request->user();
        $businessId = $request->input('current_business_id') ?? $request->input('business_id');

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
        if ($business->owner_id !== $user->id) {
            return response()->json(['message' => 'Only business owners can update users'], 403);
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
     * Remove a user from a business
     */
    public function destroy(Request $request, int $userId)
    {
        $user = $request->user();
        $businessId = $request->input('current_business_id') ?? $request->input('business_id');

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
        if ($business->owner_id !== $user->id) {
            return response()->json(['message' => 'Only business owners can remove users'], 403);
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
        $businessId = $request->input('current_business_id') ?? $request->input('business_id');

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
}
