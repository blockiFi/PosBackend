<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\Branch;
use App\Models\BranchAuthorization;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BranchController extends Controller
{
    use HasBranchAccess;

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

        // Check permission
        setPermissionsTeamId($businessId);
        if (! $user->hasPermissionTo('view-branches') && $business->owner_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Get user's accessible branches
        $accessibleBranches = $user->getBranchesInBusiness($businessId);

        // If user has specific branch assignments, only show those branches
        $query = Branch::where('business_id', $businessId);

        if ($accessibleBranches->isNotEmpty()) {
            $query->whereIn('id', $accessibleBranches);
        }
        // Otherwise, user has business-wide access and can see all branches

        $branches = $query->get()
            ->map(function (Branch $branch) {
                return [
                    'id' => $branch->id,
                    'uuid' => $branch->uuid,
                    'name' => $branch->name,
                    'code' => $branch->code,
                    'is_main' => $branch->is_main,
                    'email' => $branch->email,
                    'phone' => $branch->phone,
                    'address' => $branch->address,
                    'city' => $branch->city,
                    'state' => $branch->state,
                    'postal_code' => $branch->postal_code,
                    'country' => $branch->country,
                    'time_zone' => $branch->time_zone,
                    'tax_rate' => $branch->tax_rate,
                    'settings' => $branch->settings,
                    'is_active' => $branch->is_active,
                ];
            });

        return response()->json(['data' => $branches]);
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

        // Check if user is owner or has manage-branches permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage-branches')) {
            return response()->json(['message' => 'You do not have permission to create branches'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', 'unique:branches,code,NULL,id,business_id,'.$businessId],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:150'],
            'state' => ['nullable', 'string', 'max:150'],
            'postal_code' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'size:2'],
            'time_zone' => ['nullable', 'string', 'max:100'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settings' => ['nullable', 'array'],
            'is_main' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // If setting as main branch, unset other main branches
        if ($data['is_main'] ?? false) {
            Branch::where('business_id', $businessId)
                ->where('is_main', true)
                ->update(['is_main' => false]);
        }

        $branch = Branch::create([
            'uuid' => Str::uuid(),
            'business_id' => $businessId,
            'name' => $data['name'],
            'code' => $data['code'],
            'is_main' => $data['is_main'] ?? false,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'country' => $data['country'] ?? null,
            'time_zone' => $data['time_zone'] ?? null,
            'tax_rate' => $data['tax_rate'] ?? null,
            'settings' => $data['settings'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Branch created',
            'data' => $branch->fresh(),
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

        // Check permission
        setPermissionsTeamId($businessId);
        if (! $user->hasPermissionTo('view-branches') && $business->owner_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $branch = Branch::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (! $branch) {
            return response()->json(['message' => 'Branch not found'], 404);
        }

        // Check if user has access to this specific branch
        $accessibleBranches = $user->getBranchesInBusiness($businessId);
        if ($accessibleBranches->isNotEmpty() && ! $accessibleBranches->contains($id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        return response()->json([
            'data' => [
                'id' => $branch->id,
                'uuid' => $branch->uuid,
                'name' => $branch->name,
                'code' => $branch->code,
                'is_main' => $branch->is_main,
                'email' => $branch->email,
                'phone' => $branch->phone,
                'address' => $branch->address,
                'city' => $branch->city,
                'state' => $branch->state,
                'postal_code' => $branch->postal_code,
                'country' => $branch->country,
                'time_zone' => $branch->time_zone,
                'tax_rate' => $branch->tax_rate,
                'settings' => $branch->settings,
                'is_active' => $branch->is_active,
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

        $branch = Branch::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (! $branch) {
            return response()->json(['message' => 'Branch not found'], 404);
        }

        // Check if user is owner or has manage-branches permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage-branches')) {
            return response()->json(['message' => 'You do not have permission to update branches'], 403);
        }

        // Verify branch access
        if (! $this->userHasBranchAccess($user, $businessId, $id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:32', 'unique:branches,code,'.$id.',id,business_id,'.$businessId],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string'],
            'city' => ['sometimes', 'nullable', 'string', 'max:150'],
            'state' => ['sometimes', 'nullable', 'string', 'max:150'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'time_zone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'is_main' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // If setting as main branch, unset other main branches
        if (isset($data['is_main']) && $data['is_main'] && ! $branch->is_main) {
            Branch::where('business_id', $businessId)
                ->where('id', '!=', $id)
                ->where('is_main', true)
                ->update(['is_main' => false]);
        }

        $branch->update($validator->validated());

        return response()->json([
            'message' => 'Branch updated',
            'data' => $branch->fresh(),
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

        $branch = Branch::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (! $branch) {
            return response()->json(['message' => 'Branch not found'], 404);
        }

        // Check if user is owner or has manage-branches permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage-branches')) {
            return response()->json(['message' => 'You do not have permission to delete branches'], 403);
        }

        // Verify branch access
        if (! $this->userHasBranchAccess($user, $businessId, $id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        // Prevent deletion of main branch
        if ($branch->is_main) {
            return response()->json([
                'message' => 'Cannot delete the main branch',
            ], 422);
        }

        $branch->delete();

        return response()->json(['message' => 'Branch deleted']);
    }

    public function generateAuthCode(Request $request)
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
        if (! $user->hasPermissionTo('manage-branches') && $business->owner_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $permittedBranchIds = $user->getBranchesInBusiness($businessId);
        if ($permittedBranchIds->isEmpty()) {
            $permittedBranchIds = Branch::where('business_id', $businessId)->pluck('id');
        }

        // Allow enough time for physical cashier onboarding (enter API URL + code + confirm device).
        $expiresAt = now()->addMinutes(15);
        $authorizations = [];

        foreach ($permittedBranchIds as $branchId) {
            $existing = BranchAuthorization::where('user_id', $user->id)
                ->where('business_id', $businessId)
                ->where('branch_id', $branchId)
                ->orderByDesc('id')
                ->first();

            if ($existing && ! $existing->expires_at->isPast()) {
                $record = $existing;
                $record->load('branch');
            } else {
                $authCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                if ($existing) {
                    $existing->update(['auth_code' => $authCode, 'expires_at' => $expiresAt]);
                    $record = $existing->fresh(['branch']);
                } else {
                    $record = BranchAuthorization::create([
                        'user_id' => $user->id,
                        'business_id' => $businessId,
                        'branch_id' => $branchId,
                        'auth_code' => $authCode,
                        'expires_at' => $expiresAt,
                    ]);
                    $record->load('branch');
                }
            }

            $authorizations[] = [
                'branch_id' => $record->branch_id,
                'branch_name' => $record->branch->name ?? null,
                'auth_code' => $record->auth_code,
                'expires_at' => $record->expires_at->toIso8601String(),
            ];
        }

        return response()->json([
            'message' => 'Authorization codes generated',
            'authorizations' => $authorizations,
            'count' => count($authorizations),
            'expires_in_minutes' => 15,
        ]);
    }
}
