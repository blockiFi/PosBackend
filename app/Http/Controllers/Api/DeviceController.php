<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\DeviceRegistration;
use Illuminate\Http\Request;

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
}

