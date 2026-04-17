<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\DeviceGroup;
use App\Models\DeviceRegistration;
use App\Models\SalesShift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeviceController extends Controller
{
    use HasBranchAccess;

    /**
     * List all registered devices for the current business.
     *
     * Optional filters:
     * - branch_id
     * - status
     * - search (device_id or device_name)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json(['message' => 'Business context is required'], 400);
        }

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);

        $canView =
            $business->owner_id === $user->id ||
            $user->hasPermissionTo('sync data') ||
            $user->hasPermissionTo('view device groups') ||
            $user->hasPermissionTo('assign device to group');

        if (! $canView) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = DeviceRegistration::query()
            ->forBusiness($businessId)
            ->with([
                'branch:id,name',
                'user:id,name',
                'group:id,name,code,branch_id',
            ])
            ->orderByDesc('last_seen_at')
            ->orderBy('device_name');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('branch_id')) {
            $branchId = (int) $request->branch_id;
            if (! $this->userHasBranchAccess($user, (int) $businessId, $branchId)) {
                return response()->json(['message' => 'You do not have access to this branch'], 403);
            }
            $query->where('branch_id', $branchId);
        } else {
            // If the user is branch-scoped, restrict to permitted branches (and devices with null branch).
            $permittedBranches = $this->getPermittedBranches($user, (int) $businessId);
            if ($permittedBranches->isNotEmpty()) {
                $query->where(function ($q) use ($permittedBranches) {
                    $q->whereIn('branch_id', $permittedBranches)
                        ->orWhereNull('branch_id');
                });
            }
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            if ($term !== '') {
                $query->where(function ($q) use ($term) {
                    $q->where('device_id', 'like', "%{$term}%")
                        ->orWhere('device_name', 'like', "%{$term}%");
                });
            }
        }

        $devices = $query->get();

        return response()->json(['data' => $devices]);
    }

    public function show(Request $request, DeviceRegistration $device)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json(['message' => 'Business context is required'], 400);
        }

        if ((int) $device->business_id !== (int) $businessId) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);

        $canView =
            $business->owner_id === $user->id ||
            $user->hasPermissionTo('sync data') ||
            $user->hasPermissionTo('view device groups') ||
            $user->hasPermissionTo('assign device to group');

        if (! $canView) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($device->branch_id && ! $this->userHasBranchAccess($user, (int) $businessId, (int) $device->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $device->load([
            'branch:id,name',
            'user:id,name',
            'group:id,name,code,branch_id',
        ]);

        return response()->json(['data' => $device]);
    }

    public function update(Request $request, DeviceRegistration $device)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json(['message' => 'Business context is required'], 400);
        }

        if ((int) $device->business_id !== (int) $businessId) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);

        $canManage =
            $business->owner_id === $user->id ||
            $user->hasPermissionTo('sync data') ||
            $user->hasPermissionTo('manage device groups');

        if (! $canManage) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'device_name' => 'required|string|max:100',
            'device_type' => 'required|in:web,desktop,mobile,tablet',
            'os' => 'nullable|string|max:50',
            'app_version' => 'nullable|string|max:20',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'group_id' => 'nullable|integer|exists:device_groups,id',
            'status' => 'required|in:active,inactive,blocked',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $branchId = $request->input('branch_id');
        if ($branchId !== null && ! $this->userHasBranchAccess($user, (int) $businessId, (int) $branchId)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $groupId = $request->input('group_id');
        if ($groupId !== null) {
            $group = DeviceGroup::query()
                ->where('id', (int) $groupId)
                ->where('business_id', (int) $businessId)
                ->first();
            if (! $group) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => ['group_id' => ['Invalid group for this business']],
                ], 422);
            }

            if ($branchId !== null && $group->branch_id !== null && (int) $group->branch_id !== (int) $branchId) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => ['group_id' => ['Group branch must match selected branch']],
                ], 422);
            }
        }

        $device->update([
            'device_name' => $request->string('device_name')->toString(),
            'device_type' => $request->string('device_type')->toString(),
            'os' => $request->filled('os') ? $request->string('os')->toString() : null,
            'app_version' => $request->filled('app_version') ? $request->string('app_version')->toString() : null,
            'branch_id' => $branchId !== null ? (int) $branchId : null,
            'group_id' => $groupId !== null ? (int) $groupId : null,
            'status' => $request->string('status')->toString(),
        ]);

        $device->load([
            'branch:id,name',
            'user:id,name',
            'group:id,name,code,branch_id',
        ]);

        return response()->json(['data' => $device]);
    }

    public function destroy(Request $request, DeviceRegistration $device)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        if (! $businessId) {
            return response()->json(['message' => 'Business context is required'], 400);
        }

        if ((int) $device->business_id !== (int) $businessId) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);

        $canManage =
            $business->owner_id === $user->id ||
            $user->hasPermissionTo('sync data') ||
            $user->hasPermissionTo('manage device groups');

        if (! $canManage) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($device->branch_id && ! $this->userHasBranchAccess($user, (int) $businessId, (int) $device->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $hasActiveShift = SalesShift::query()
            ->where('business_id', (int) $businessId)
            ->where('device_id', $device->device_id)
            ->active()
            ->exists();

        if ($hasActiveShift) {
            return response()->json([
                'message' => 'Device has an active shift and cannot be deleted',
            ], 409);
        }

        $device->delete();

        return response()->noContent();
    }
}

