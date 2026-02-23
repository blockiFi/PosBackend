<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchAuthorization;
use App\Models\User;
use App\Services\ProfileImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthenticationController extends Controller
{
    public function getBusinessDetailsWithBranchAuthorization(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'auth_code' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchAuthorization = BranchAuthorization::with(['business', 'branch'])
            ->where('auth_code', $request->auth_code)
            ->where('expires_at', '>', now())
            ->first();

        if (! $branchAuthorization) {
            return response()->json([
                'message' => 'Invalid or expired auth code',
            ], 401);
        }

        return response()->json([
            'message' => 'Business details with branch authorization',
            'business' => $branchAuthorization->business,
            'branch' => $branchAuthorization->branch,
        ]);
    }

    public function register(Request $request)
    {
        $data = $request->all();

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'profile_image' => ['nullable', 'image', 'max:2048'],
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $profileImagePath = null;
        if ($request->hasFile('profile_image')) {
            $profileImagePath = app(ProfileImageService::class)->store($request->file('profile_image'));
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'profile_image' => $profileImagePath,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'profile_image' => $user->profile_image,
                'profile_image_url' => $user->profile_image_url,
            ],
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $business = $user->businesses()->wherePivot('is_active', true)->first();
        $branches = $business
            ? $business->branches()->get(['id', 'name', 'business_id'])
            : collect();

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'profile_image' => $user->profile_image,
                'profile_image_url' => $user->profile_image_url,
            ],
            'business' => $business,
            'branches' => $branches,
            'roles' => $user->roles()->get(['id', 'name']),
        ]);
    }

    /**
     * Fast login with 6-digit PIN code
     * Only users with 'use-pin-login' permission can use this feature
     */
    public function pinLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pin_code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('pin_code', $request->pin_code)->first();

        if (! $user) {
            return response()->json([
                'message' => 'Invalid PIN code',
            ], 401);
        }

        // Check if user has permission to use PIN login in ANY business
        $hasPermission = false;
        $businesses = $user->businesses;
        foreach ($businesses as $business) {
            setPermissionsTeamId($business->id);
            if ($user->hasPermissionTo('use-pin-login')) {
                $hasPermission = true;
                break;
            }
        }

        if (! $hasPermission) {
            return response()->json([
                'message' => 'You do not have permission to use PIN login',
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'profile_image' => $user->profile_image,
                'profile_image_url' => $user->profile_image_url,
            ],
        ]);
    }

    /**
     * Set or update PIN code for a user
     * Requires 'manage-pin-codes' permission (or user setting their own PIN)
     * Password required only when setting your own PIN
     */
    public function setPin(Request $request)
    {
        $authenticatedUser = $request->user();
        $isSettingOwnPin = $request->user_id == $authenticatedUser->id;

        // Validation rules - password only required when setting your own PIN
        $rules = [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'pin_code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ];

        if ($isSettingOwnPin) {
            $rules['password'] = ['required', 'string'];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // If setting someone else's PIN, check for manage-pin-codes permission
        if (! $isSettingOwnPin) {
            $hasPermission = false;
            $businesses = $authenticatedUser->businesses;
            foreach ($businesses as $business) {
                setPermissionsTeamId($business->id);
                if ($authenticatedUser->hasPermissionTo('manage-pin-codes')) {
                    $hasPermission = true;
                    break;
                }
            }

            if (! $hasPermission) {
                return response()->json([
                    'message' => 'You do not have permission to manage PIN codes',
                ], 403);
            }
        }

        // Verify password only when setting your own PIN
        if ($isSettingOwnPin && ! Hash::check($request->password, $authenticatedUser->password)) {
            return response()->json([
                'message' => 'Invalid password',
            ], 401);
        }

        // Get the target user
        $targetUser = User::findOrFail($request->user_id);

        // Check if PIN is already taken by another user
        $existingUser = User::where('pin_code', $request->pin_code)
            ->where('id', '!=', $targetUser->id)
            ->first();

        if ($existingUser) {
            return response()->json([
                'message' => 'This PIN code is already in use',
            ], 422);
        }

        $targetUser->pin_code = $request->pin_code;
        $targetUser->save();

        return response()->json([
            'message' => 'PIN code set successfully',
        ]);
    }

    /**
     * Remove PIN code from a user
     * Requires 'manage-pin-codes' permission
     */
    public function removePin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $authenticatedUser = $request->user();

        // Check if user has permission to manage PIN codes in ANY business
        $hasPermission = false;
        $businesses = $authenticatedUser->businesses;
        foreach ($businesses as $business) {
            setPermissionsTeamId($business->id);
            if ($authenticatedUser->hasPermissionTo('manage-pin-codes')) {
                $hasPermission = true;
                break;
            }
        }

        if (! $hasPermission) {
            return response()->json([
                'message' => 'You do not have permission to manage PIN codes',
            ], 403);
        }

        // Verify password before allowing PIN removal
        if (! Hash::check($request->password, $authenticatedUser->password)) {
            return response()->json([
                'message' => 'Invalid password',
            ], 401);
        }

        // Get the target user
        $targetUser = User::findOrFail($request->user_id);

        $targetUser->pin_code = null;
        $targetUser->save();

        return response()->json([
            'message' => 'PIN code removed successfully',
        ]);
    }

    /**
     * Update the authenticated user's profile (name, profile image).
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $data = $request->all();

        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'profile_image' => ['nullable', 'image', 'max:2048'],
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->hasFile('profile_image')) {
            $user->profile_image = app(ProfileImageService::class)->replace(
                $user->profile_image,
                $request->file('profile_image')
            );
        }

        if (array_key_exists('name', $data)) {
            $user->name = $data['name'];
        }

        $user->save();

        return response()->json([
            'message' => 'Profile updated',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'profile_image' => $user->profile_image,
                'profile_image_url' => $user->profile_image_url,
            ],
        ]);
    }
}
