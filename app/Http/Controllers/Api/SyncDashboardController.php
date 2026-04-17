<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\ChangeLog;
use App\Models\DeviceRegistration;
use App\Models\SyncSession;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SyncDashboardController extends Controller
{
    use HasBranchAccess;

    public function summary(Request $request)
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
        if (! $user->hasPermissionTo('sync data')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $permittedBranches = $this->getPermittedBranches($user, (int) $businessId);

        $devicesQuery = DeviceRegistration::query()
            ->forBusiness($businessId);
        if ($permittedBranches->isNotEmpty()) {
            $devicesQuery->where(function ($q) use ($permittedBranches) {
                $q->whereIn('branch_id', $permittedBranches)->orWhereNull('branch_id');
            });
        }

        $totalDevicesCount = (clone $devicesQuery)->count();
        $onlineDevicesCount = (clone $devicesQuery)->online(5)->count();

        $lastSession = SyncSession::query()
            ->where('business_id', $businessId)
            ->when(
                $permittedBranches->isNotEmpty(),
                function ($q) use ($permittedBranches) {
                    $q->whereHas('device', function ($dq) use ($permittedBranches) {
                        $dq->whereIn('branch_id', $permittedBranches)->orWhereNull('branch_id');
                    });
                }
            )
            ->orderByDesc('completed_at')
            ->orderByDesc('started_at')
            ->first();

        $pendingChanges = ChangeLog::query()
            ->where('business_id', $businessId)
            ->unsynced()
            ->count();

        $unresolvedConflicts = SyncSession::query()
            ->where('business_id', $businessId)
            ->when(
                $permittedBranches->isNotEmpty(),
                function ($q) use ($permittedBranches) {
                    $q->whereHas('device', function ($dq) use ($permittedBranches) {
                        $dq->whereIn('branch_id', $permittedBranches)->orWhereNull('branch_id');
                    });
                }
            )
            ->selectRaw('COALESCE(SUM(GREATEST(conflicts_detected - conflicts_resolved, 0)), 0) as unresolved')
            ->value('unresolved');

        return response()->json([
            'online_devices_count' => $onlineDevicesCount,
            'total_devices_count' => $totalDevicesCount,
            'last_sync_at' => $lastSession?->completed_at?->toIso8601String(),
            'last_sync_status' => $lastSession?->status,
            'pending_changes' => $pendingChanges,
            'unresolved_conflicts' => (int) $unresolvedConflicts,
            'server_timestamp' => now()->toIso8601String(),
        ]);
    }

    public function sessions(Request $request)
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
        if (! $user->hasPermissionTo('sync data')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $permittedBranches = $this->getPermittedBranches($user, (int) $businessId);

        $query = SyncSession::query()
            ->where('business_id', $businessId)
            ->with([
                'device:id,device_id,device_name,branch_id',
                'user:id,name',
            ])
            ->when(
                $permittedBranches->isNotEmpty(),
                function ($q) use ($permittedBranches) {
                    $q->whereHas('device', function ($dq) use ($permittedBranches) {
                        $dq->whereIn('branch_id', $permittedBranches)->orWhereNull('branch_id');
                    });
                }
            )
            ->orderByDesc('started_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('direction')) {
            $query->where('direction', $request->string('direction')->toString());
        }

        if ($request->filled('device_id')) {
            $deviceId = (int) $request->input('device_id');
            $query->where('device_id', $deviceId);
        }

        if ($request->filled('from')) {
            $from = Carbon::parse($request->input('from'))->startOfDay();
            $query->where('started_at', '>=', $from);
        }

        if ($request->filled('to')) {
            $to = Carbon::parse($request->input('to'))->endOfDay();
            $query->where('started_at', '<=', $to);
        }

        $perPage = (int) ($request->input('per_page') ?? 25);
        $perPage = max(1, min($perPage, 100));

        $page = $query->paginate($perPage);

        $page->getCollection()->transform(function (SyncSession $s) {
            $durationMs = null;
            if ($s->started_at && $s->completed_at) {
                $durationMs = (int) max(0, $s->completed_at->diffInMilliseconds($s->started_at));
            }

            return [
                'id' => $s->id,
                'session_id' => $s->session_id,
                'direction' => $s->direction,
                'status' => $s->status,
                'started_at' => $s->started_at?->toIso8601String(),
                'completed_at' => $s->completed_at?->toIso8601String(),
                'records_pushed' => $s->records_pushed,
                'records_pulled' => $s->records_pulled,
                'conflicts_detected' => $s->conflicts_detected,
                'conflicts_resolved' => $s->conflicts_resolved,
                'errors_count' => $s->errors_count,
                'duration_ms' => $durationMs,
                'device' => $s->device ? [
                    'id' => $s->device->id,
                    'device_id' => $s->device->device_id,
                    'device_name' => $s->device->device_name,
                    'branch_id' => $s->device->branch_id,
                ] : null,
                'user' => $s->user ? [
                    'id' => $s->user->id,
                    'name' => $s->user->name,
                ] : null,
            ];
        });

        return response()->json($page);
    }

    public function conflicts(Request $request)
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
        if (! $user->hasPermissionTo('sync data')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $permittedBranches = $this->getPermittedBranches($user, (int) $businessId);

        $sessions = SyncSession::query()
            ->where('business_id', $businessId)
            ->whereRaw('conflicts_detected > conflicts_resolved')
            ->with([
                'device:id,device_id,device_name,branch_id',
                'user:id,name',
            ])
            ->when(
                $permittedBranches->isNotEmpty(),
                function ($q) use ($permittedBranches) {
                    $q->whereHas('device', function ($dq) use ($permittedBranches) {
                        $dq->whereIn('branch_id', $permittedBranches)->orWhereNull('branch_id');
                    });
                }
            )
            ->orderByDesc('started_at')
            ->limit(100)
            ->get()
            ->map(function (SyncSession $s) {
                return [
                    'id' => $s->id,
                    'session_id' => $s->session_id,
                    'direction' => $s->direction,
                    'status' => $s->status,
                    'started_at' => $s->started_at?->toIso8601String(),
                    'completed_at' => $s->completed_at?->toIso8601String(),
                    'conflicts_detected' => $s->conflicts_detected,
                    'conflicts_resolved' => $s->conflicts_resolved,
                    'errors_count' => $s->errors_count,
                    'summary' => $s->summary,
                    'metadata' => $s->metadata,
                    'device' => $s->device ? [
                        'id' => $s->device->id,
                        'device_id' => $s->device->device_id,
                        'device_name' => $s->device->device_name,
                        'branch_id' => $s->device->branch_id,
                    ] : null,
                    'user' => $s->user ? [
                        'id' => $s->user->id,
                        'name' => $s->user->name,
                    ] : null,
                ];
            });

        return response()->json([
            'data' => $sessions,
            'server_timestamp' => now()->toIso8601String(),
        ]);
    }
}

