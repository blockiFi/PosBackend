<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeviceGroupRequest;
use App\Http\Requests\UpdateDeviceGroupRequest;
use App\Http\Traits\HasBranchAccess;
use App\Models\DeviceGroup;
use App\Models\DeviceRegistration;
use App\Models\Sale;
use App\Models\SalesShift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceGroupController extends Controller
{
    use HasBranchAccess;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        $canView = $business->owner_id === $user->id || $user->hasPermissionTo('view device groups');
        if (! $canView) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = DeviceGroup::query()->forBusiness($businessId);

        // Branch filtering + access
        if ($request->filled('branch_id')) {
            $branchId = (int) $request->input('branch_id');
            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json(['message' => 'Unauthorized access to this branch'], 403);
            }
            $query->where('branch_id', $branchId);
        } else {
            $accessibleBranches = $user->getBranchesInBusiness($businessId);
            if ($accessibleBranches->isNotEmpty()) {
                $ids = $accessibleBranches->pluck('id')->all();
                $query->where(function ($q) use ($ids) {
                    $q->whereNull('branch_id')->orWhereIn('branch_id', $ids);
                });
            }
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $groups = $query
            ->withCount('devices')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $groups]);
    }

    /**
     * Aggregate sales by group for reporting.
     */
    public function report(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        $canView = $business->owner_id === $user->id || $user->hasPermissionTo('view device groups');
        if (! $canView) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'group_id' => ['nullable', 'integer', 'exists:device_groups,id'],
            'device_id' => ['nullable', 'string', 'max:50'],
            'shift_id' => ['nullable', 'integer', 'exists:sales_shifts,id'],
        ]);

        if (! empty($validated['branch_id'])) {
            if (! $this->userHasBranchAccess($user, $businessId, (int) $validated['branch_id'])) {
                return response()->json(['message' => 'Unauthorized access to this branch'], 403);
            }
        }

        $query = Sale::query()
            ->where('sales.business_id', $businessId)
            ->where('sales.status', 'completed')
            ->whereNull('sales.deleted_at')
            ->join('sales_shifts as shifts', function ($join) use ($businessId) {
                $join->on('shifts.id', '=', 'sales.shift_id')
                    ->where('shifts.business_id', '=', $businessId)
                    ->whereNull('shifts.deleted_at')
                    ->whereNotNull('shifts.group_id');
            })
            ->join('device_groups', 'device_groups.id', '=', 'shifts.group_id')
            ->select([
                'shifts.group_id',
                'device_groups.name as group_name',
                'device_groups.code as group_code',
                DB::raw('COUNT(*) as transactions_count'),
                DB::raw('COALESCE(SUM(sales.total_amount),0) as total_revenue'),
            ])
            ->groupBy('shifts.group_id', 'device_groups.name', 'device_groups.code');

        if (! empty($validated['start_date']) && ! empty($validated['end_date'])) {
            $query->whereBetween('sales.sale_date', [$validated['start_date'], $validated['end_date']]);
        }

        if (! empty($validated['branch_id'])) {
            $query->where('sales.branch_id', (int) $validated['branch_id']);
        }

        if (! empty($validated['group_id'])) {
            $query->where('shifts.group_id', (int) $validated['group_id']);
        }

        if (! empty($validated['device_id'])) {
            $query->where('shifts.device_id', $validated['device_id']);
        }

        if (! empty($validated['shift_id'])) {
            $query->where('sales.shift_id', (int) $validated['shift_id']);
        }

        $rows = $query->orderByDesc('total_revenue')->get();

        return response()->json([
            'data' => $rows,
            'filters' => [
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'branch_id' => $validated['branch_id'] ?? null,
                'group_id' => $validated['group_id'] ?? null,
                'device_id' => $validated['device_id'] ?? null,
                'shift_id' => $validated['shift_id'] ?? null,
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDeviceGroupRequest $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        $canManage = $business->owner_id === $user->id || $user->hasPermissionTo('manage device groups');
        if (! $canManage) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validated();

        if (! empty($validated['branch_id'])) {
            if (! $this->userHasBranchAccess($user, $businessId, (int) $validated['branch_id'])) {
                return response()->json(['message' => 'Unauthorized access to this branch'], 403);
            }
        }

        $group = DeviceGroup::create([
            'business_id' => $businessId,
            'branch_id' => $validated['branch_id'] ?? null,
            'name' => $validated['name'],
            'code' => $validated['code'],
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['data' => $group], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        $canView = $business->owner_id === $user->id || $user->hasPermissionTo('view device groups');
        if (! $canView) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $group = DeviceGroup::query()
            ->forBusiness($businessId)
            ->withCount('devices')
            ->findOrFail($id);

        if ($group->branch_id !== null && ! $this->userHasBranchAccess($user, $businessId, (int) $group->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        $activeShiftsCount = SalesShift::query()
            ->forBusiness($businessId)
            ->where('group_id', $group->id)
            ->active()
            ->count();

        return response()->json([
            'data' => array_merge($group->toArray(), [
                'active_shifts_count' => $activeShiftsCount,
            ]),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDeviceGroupRequest $request, string $id)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        $canManage = $business->owner_id === $user->id || $user->hasPermissionTo('manage device groups');
        if (! $canManage) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $group = DeviceGroup::query()->forBusiness($businessId)->findOrFail($id);

        if ($group->branch_id !== null && ! $this->userHasBranchAccess($user, $businessId, (int) $group->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        $validated = $request->validated();
        if (array_key_exists('branch_id', $validated) && $validated['branch_id'] !== null) {
            if (! $this->userHasBranchAccess($user, $businessId, (int) $validated['branch_id'])) {
                return response()->json(['message' => 'Unauthorized access to this branch'], 403);
            }
        }

        $group->fill($validated);
        $group->save();

        return response()->json(['data' => $group->fresh()]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        $canManage = $business->owner_id === $user->id || $user->hasPermissionTo('manage device groups');
        if (! $canManage) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $group = DeviceGroup::query()->forBusiness($businessId)->findOrFail($id);

        if ($group->branch_id !== null && ! $this->userHasBranchAccess($user, $businessId, (int) $group->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        $group->delete();

        return response()->json(['message' => 'Device group deleted']);
    }

    /**
     * Assign a device to this group.
     */
    public function assignDevice(Request $request, string $id)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        $canManage = $business->owner_id === $user->id || $user->hasPermissionTo('assign device to group');
        if (! $canManage) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:50'],
        ]);

        $group = DeviceGroup::query()->forBusiness($businessId)->findOrFail($id);
        if ($group->branch_id !== null && ! $this->userHasBranchAccess($user, $businessId, (int) $group->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        $device = DeviceRegistration::query()
            ->where('business_id', $businessId)
            ->where('device_id', $validated['device_id'])
            ->first();

        if (! $device) {
            return response()->json(['message' => 'Device not found for this business'], 404);
        }

        if ($device->branch_id !== null && ! $this->userHasBranchAccess($user, $businessId, (int) $device->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this device branch'], 403);
        }

        $device->group_id = $group->id;
        $device->save();

        return response()->json(['data' => $device->fresh()]);
    }

    /**
     * Remove a device from this group (sets device.group_id null).
     */
    public function removeDevice(Request $request, string $id)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        $canManage = $business->owner_id === $user->id || $user->hasPermissionTo('assign device to group');
        if (! $canManage) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:50'],
        ]);

        $group = DeviceGroup::query()->forBusiness($businessId)->findOrFail($id);
        if ($group->branch_id !== null && ! $this->userHasBranchAccess($user, $businessId, (int) $group->branch_id)) {
            return response()->json(['message' => 'Unauthorized access to this branch'], 403);
        }

        $device = DeviceRegistration::query()
            ->where('business_id', $businessId)
            ->where('device_id', $validated['device_id'])
            ->where('group_id', $group->id)
            ->first();

        if (! $device) {
            return response()->json(['message' => 'Device not found in this group'], 404);
        }

        $device->group_id = null;
        $device->save();

        return response()->json(['data' => $device->fresh()]);
    }
}
