<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BusinessController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $businesses = $user->businesses()
            ->with('branches')
            ->wherePivot('is_active', true)
            ->get()
            ->map(function (Business $business) {
                return [
                    'id' => $business->id,
                    'uuid' => $business->uuid,
                    'name' => $business->name,
                    'slug' => $business->slug,
                    'currency' => $business->currency,
                    'time_zone' => $business->time_zone,
                    'branch_id' => $business->pivot->branch_id,
                    'is_active' => $business->is_active,
                    'branches' => $business->branches->map(function (Branch $branch) {
                        return [
                            'id' => $branch->id,
                            'uuid' => $branch->uuid,
                            'name' => $branch->name,
                            'code' => $branch->code,
                            'is_main' => $branch->is_main,
                            'is_active' => $branch->is_active,
                        ];
                    }),
                ];
            });

        return response()->json(['data' => $businesses]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->all();

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:businesses,slug'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:150'],
            'state' => ['nullable', 'string', 'max:150'],
            'postal_code' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'size:2'],
            'currency' => ['nullable', 'string', 'size:3'],
            'time_zone' => ['nullable', 'string', 'max:100'],
            'tax_registration_number' => ['nullable', 'string', 'max:150'],
            'default_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settings' => ['nullable', 'array'],
            'main_branch_code' => ['nullable', 'string', 'max:32'],
            'main_branch_name' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $business = Business::create([
            'uuid' => Str::uuid(),
            'owner_id' => $user->id,
            'name' => $data['name'],
            'legal_name' => $data['legal_name'] ?? null,
            'slug' => $data['slug'] ?? Str::slug($data['name']) . '-' . Str::random(6),
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'country' => $data['country'] ?? null,
            'currency' => $data['currency'] ?? 'USD',
            'time_zone' => $data['time_zone'] ?? null,
            'tax_registration_number' => $data['tax_registration_number'] ?? null,
            'default_tax_rate' => $data['default_tax_rate'] ?? 0,
            'settings' => $data['settings'] ?? null,
            'is_active' => true,
        ]);

        $branch = $business->branches()->create([
            'uuid' => Str::uuid(),
            'name' => $data['main_branch_name'] ?? 'Main Branch',
            'code' => $data['main_branch_code'] ?? 'MAIN',
            'is_main' => true,
            'is_active' => true,
        ]);

        $user->businesses()->attach($business->id, [
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Business created',
            'data' => [
                'business' => $business->fresh(),
                'branch' => $branch,
            ],
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        $user = $request->user();

        $business = $user->businesses()
            ->with('branches')
            ->where('businesses.id', $id)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json([
            'data' => [
                'id' => $business->id,
                'uuid' => $business->uuid,
                'name' => $business->name,
                'slug' => $business->slug,
                'currency' => $business->currency,
                'time_zone' => $business->time_zone,
                'role' => $business->pivot->role,
                'branch_id' => $business->pivot->branch_id,
                'is_active' => $business->is_active,
                'branches' => $business->branches,
            ],
        ]);
    }

    public function update(Request $request, int $id)
    {
        $user = $request->user();
        $business = Business::where('id', $id)->first();

        if (! $business) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($business->owner_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'unique:businesses,slug,' . $business->id],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string'],
            'city' => ['sometimes', 'nullable', 'string', 'max:150'],
            'state' => ['sometimes', 'nullable', 'string', 'max:150'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'time_zone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tax_registration_number' => ['sometimes', 'nullable', 'string', 'max:150'],
            'default_tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $business->update($validator->validated());

        return response()->json([
            'message' => 'Business updated',
            'data' => $business->fresh(),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $user = $request->user();
        $business = Business::find($id);

        if (! $business) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($business->owner_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $business->delete();

        return response()->json(['message' => 'Business deleted']);
    }
}
