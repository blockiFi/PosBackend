<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SeedDataRequest;
use App\Http\Traits\HasBranchAccess;
use App\Models\Branch;
use App\Services\SeedFromFileService;
use Illuminate\Http\JsonResponse;

class SeedController extends Controller
{
    use HasBranchAccess;

    public function store(SeedDataRequest $request): JsonResponse
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

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
        if (! $user->hasPermissionTo('create products')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $branchId = (int) $request->input('branch_id');
        $branch = Branch::where('id', $branchId)->where('business_id', $businessId)->first();
        if (! $branch) {
            return response()->json([
                'message' => 'Branch not found or does not belong to business.',
            ], 422);
        }
        if (! $this->userHasBranchAccess($user, (int) $businessId, $branchId)) {
            return response()->json([
                'message' => 'You do not have access to this branch.',
            ], 403);
        }

        $mapping = $request->input('mapping', []);
        if (is_string($mapping)) {
            $mapping = json_decode($mapping, true) ?: [];
        }

        $delete = filter_var($request->input('delete', false), FILTER_VALIDATE_BOOLEAN);

        $result = app(SeedFromFileService::class)->run(
            $request->file('file'),
            $request->input('entity'),
            $mapping,
            $request->input('unique_key'),
            (int) $businessId,
            $branchId,
            $delete
        );

        return response()->json([
            'message' => 'Seed completed.',
            'created' => $result['created'],
            'updated' => $result['updated'],
            'deleted' => $result['deleted'],
            'failed' => $result['failed'],
            'errors' => $result['errors'],
        ], 200);
    }
}
