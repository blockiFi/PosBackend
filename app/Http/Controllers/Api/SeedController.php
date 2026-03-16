<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SeedDataRequest;
use App\Http\Traits\HasBranchAccess;
use App\Jobs\ProcessSeedImport;
use App\Models\Branch;
use App\Models\SeedImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

        $uploadedFile = $request->file('file');
        $storedPath = Storage::disk('local')->putFile('seed-imports', $uploadedFile);

        $import = SeedImport::create([
            'uuid' => (string) Str::uuid(),
            'business_id' => (int) $businessId,
            'branch_id' => $branchId,
            'user_id' => $user->id,
            'entity' => $request->input('entity'),
            'status' => 'pending',
            'file_path' => $storedPath,
            'mapping' => $mapping,
            'unique_key' => $request->input('unique_key'),
            'delete' => $delete,
        ]);

        ProcessSeedImport::dispatch($import);

        return response()->json([
            'message' => 'Seed import queued.',
            'id' => $import->id,
            'uuid' => $import->uuid,
            'status' => $import->status,
        ], 202);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        $import = SeedImport::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (! $import) {
            return response()->json(['message' => 'Seed import not found.'], 404);
        }

        return response()->json([
            'id' => $import->id,
            'uuid' => $import->uuid,
            'status' => $import->status,
            'entity' => $import->entity,
            'total_rows' => $import->total_rows,
            'created' => $import->created,
            'updated' => $import->updated,
            'deleted' => $import->deleted,
            'failed' => $import->failed,
            'errors' => $import->errors,
            'started_at' => $import->started_at?->toIso8601String(),
            'completed_at' => $import->completed_at?->toIso8601String(),
        ]);
    }
}
