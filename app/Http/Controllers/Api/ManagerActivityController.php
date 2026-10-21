<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\ManagerActivity;
use App\Support\ReportingTimezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ManagerActivityController extends Controller
{
    /**
     * List manager and storekeeper stock and product changes for the business owner.
     */
    public function index(Request $request): JsonResponse
    {
        $businessId = (int) $request->input('current_business_id');
        $business = Business::find($businessId);

        if (! $business || (int) $business->owner_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Only the business owner can see manager activity'], 403);
        }

        $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'role' => ['nullable', 'string', 'in:Manager,Storekeeper'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $timezone = ReportingTimezone::forBusiness($businessId);

        $query = ManagerActivity::query()
            ->with(['user:id,name,email', 'branch:id,name'])
            ->where('business_id', $businessId)
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->input('role')))
            ->when($request->filled('start_date'), fn ($q) => $q->where(
                'created_at', '>=', Carbon::parse($request->input('start_date'), $timezone)->startOfDay()->utc()
            ))
            ->when($request->filled('end_date'), fn ($q) => $q->where(
                'created_at', '<=', Carbon::parse($request->input('end_date'), $timezone)->endOfDay()->utc()
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $page = $query->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (ManagerActivity $a) => [
                'id' => $a->id,
                'created_at' => $a->created_at?->toIso8601String(),
                'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
                'branch' => $a->branch ? ['id' => $a->branch->id, 'name' => $a->branch->name] : null,
                'action' => $a->action,
                'description' => $a->description,
                'changes' => $a->changes,
                'role' => $a->role,
                'pin_ok' => $a->pin_ok,
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
