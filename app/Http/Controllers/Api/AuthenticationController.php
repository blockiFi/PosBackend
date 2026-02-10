<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthenticationController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
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

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
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

        if (!$user) {
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
        
        if (!$hasPermission) {
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
        if (!$isSettingOwnPin) {
            $hasPermission = false;
            $businesses = $authenticatedUser->businesses;
            foreach ($businesses as $business) {
                setPermissionsTeamId($business->id);
                if ($authenticatedUser->hasPermissionTo('manage-pin-codes')) {
                    $hasPermission = true;
                    break;
                }
            }
            
            if (!$hasPermission) {
                return response()->json([
                    'message' => 'You do not have permission to manage PIN codes',
                ], 403);
            }
        }

        // Verify password only when setting your own PIN
        if ($isSettingOwnPin && !Hash::check($request->password, $authenticatedUser->password)) {
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
        
        if (!$hasPermission) {
            return response()->json([
                'message' => 'You do not have permission to manage PIN codes',
            ], 403);
        }

        // Verify password before allowing PIN removal
        if (!Hash::check($request->password, $authenticatedUser->password)) {
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
}
