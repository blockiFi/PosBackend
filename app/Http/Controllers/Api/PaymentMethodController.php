<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    /**
     * List payment methods
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view payment methods')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = PaymentMethod::forBusiness($businessId);

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $paymentMethods = $query->ordered()->get();

        return response()->json($paymentMethods);
    }

    /**
     * Create a new payment method
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage payment methods')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:cash,card,mobile_money,bank_transfer,cheque,other',
            'description' => 'nullable|string',
            'account_details' => 'nullable|array',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $paymentMethod = PaymentMethod::create([
            'business_id' => $businessId,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'description' => $validated['description'] ?? null,
            'account_details' => $validated['account_details'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'message' => 'Payment method created successfully',
            'payment_method' => $paymentMethod,
        ], 201);
    }

    /**
     * View a specific payment method
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view payment methods')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $paymentMethod = PaymentMethod::forBusiness($businessId)->findOrFail($id);

        return response()->json($paymentMethod);
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage payment methods')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:cash,card,mobile_money,bank_transfer,cheque,other',
            'description' => 'nullable|string',
            'account_details' => 'nullable|array',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $paymentMethod = PaymentMethod::forBusiness($businessId)->findOrFail($id);
        $paymentMethod->update($validated);

        return response()->json([
            'message' => 'Payment method updated successfully',
            'payment_method' => $paymentMethod,
        ]);
    }

    /**
     * Delete a payment method
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage payment methods')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $paymentMethod = PaymentMethod::forBusiness($businessId)->findOrFail($id);
        $paymentMethod->delete();

        return response()->json(['message' => 'Payment method deleted successfully']);
    }
}
