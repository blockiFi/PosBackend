<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * List customers with filtering and pagination
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view customers')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = Customer::forBusiness($businessId);

        if ($request->filled('type')) {
            $query->ofType($request->type);
        }

        if ($request->filled('is_active')) {
            if ($request->boolean('is_active')) {
                $query->active();
            } else {
                $query->where('is_active', false);
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('customer_code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $customers = $query->orderBy('name')->paginate(15);

        return response()->json($customers);
    }

    /**
     * Create a new customer
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('create customers')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'type' => 'nullable|in:walk-in,regular,vip',
            'credit_limit' => 'nullable|numeric|min:0',
            'metadata' => 'nullable|array',
        ]);

        // Generate customer code
        $customerCode = $this->generateCustomerCode($businessId);

        $customer = Customer::create([
            'business_id' => $businessId,
            'customer_code' => $customerCode,
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'type' => $validated['type'] ?? 'walk-in',
            'credit_limit' => $validated['credit_limit'] ?? 0,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return response()->json([
            'message' => 'Customer created successfully',
            'customer' => $customer,
        ], 201);
    }

    /**
     * View a specific customer
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view customers')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $customer = Customer::forBusiness($businessId)
            ->with(['sales' => function ($query) {
                $query->latest()->limit(10);
            }])
            ->findOrFail($id);

        return response()->json($customer);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('edit customers')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'type' => 'nullable|in:walk-in,regular,vip',
            'credit_limit' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        $customer = Customer::forBusiness($businessId)->findOrFail($id);
        $customer->update($validated);

        return response()->json([
            'message' => 'Customer updated successfully',
            'customer' => $customer,
        ]);
    }

    /**
     * Delete a customer
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $businessId = $request->current_business_id;

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('delete customers')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $customer = Customer::forBusiness($businessId)->findOrFail($id);
        $customer->delete();

        return response()->json(['message' => 'Customer deleted successfully']);
    }

    /**
     * Generate unique customer code
     */
    private function generateCustomerCode($businessId): string
    {
        $prefix = 'CUST';
        $lastCustomer = Customer::forBusiness($businessId)
            ->orderBy('id', 'desc')
            ->first();

        $sequence = $lastCustomer ? (intval(substr($lastCustomer->customer_code, -6)) + 1) : 1;

        return sprintf('%s-%06d', $prefix, $sequence);
    }
}
