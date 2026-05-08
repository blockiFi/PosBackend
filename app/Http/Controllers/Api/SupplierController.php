<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierProductPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

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
        $hasPermission = $business->owner_id === $user->id
            || $user->hasPermissionTo('manage suppliers')
            || $user->hasPermissionTo('create grn')
            || $user->hasPermissionTo('view grn');

        if (! $hasPermission) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $q = trim((string) $request->input('q', ''));
        $active = $request->input('is_active');

        $query = Supplier::query()->where('business_id', $businessId);
        if ($q !== '') {
            $query->where(function ($qq) use ($q) {
                $qq->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%");
            });
        }
        if ($active !== null && $active !== '') {
            $query->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json([
            'data' => $query->orderBy('name')->paginate(50),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage suppliers')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'code' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'default_currency' => ['nullable', 'string', 'size:3'],
            'default_payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'tax_id' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'meta_data' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $supplier = Supplier::create(array_merge($validator->validated(), [
            'business_id' => (int) $businessId,
        ]));

        return response()->json(['supplier' => $supplier], 201);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

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
        $hasPermission = $business->owner_id === $user->id
            || $user->hasPermissionTo('manage suppliers')
            || $user->hasPermissionTo('create grn')
            || $user->hasPermissionTo('view grn');
        if (! $hasPermission) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $supplier = Supplier::where('business_id', $businessId)->where('id', $id)->first();
        if (! $supplier) {
            return response()->json(['message' => 'Supplier not found'], 404);
        }

        return response()->json(['supplier' => $supplier]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage suppliers')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $supplier = Supplier::where('business_id', $businessId)->where('id', $id)->first();
        if (! $supplier) {
            return response()->json(['message' => 'Supplier not found'], 404);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'code' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'default_currency' => ['nullable', 'string', 'size:3'],
            'default_payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'tax_id' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'meta_data' => ['nullable', 'array'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $supplier->update($validator->validated());

        return response()->json(['supplier' => $supplier->fresh()]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage suppliers')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $supplier = Supplier::where('business_id', $businessId)->where('id', $id)->first();
        if (! $supplier) {
            return response()->json(['message' => 'Supplier not found'], 404);
        }

        $supplier->delete();

        return response()->json(['message' => 'Supplier deleted']);
    }

    public function prices(Request $request, $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

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
        $hasPermission = $business->owner_id === $user->id
            || $user->hasPermissionTo('manage suppliers')
            || $user->hasPermissionTo('view inventory')
            || $user->hasPermissionTo('view grn');

        if (! $hasPermission) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $supplier = Supplier::where('business_id', $businessId)->where('id', $id)->first();
        if (! $supplier) {
            return response()->json(['message' => 'Supplier not found'], 404);
        }

        $rows = SupplierProductPrice::where('business_id', $businessId)
            ->where('supplier_id', $supplier->id)
            ->with('product:id,name,sku')
            ->orderByDesc('last_received_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $rows]);
    }
}
