<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Support\BusinessQuantityPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PurchaseOrderController extends Controller
{
    use HasBranchAccess;

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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage purchase orders')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $branchId = $request->input('branch_id');
        $status = $request->input('status');
        $supplierId = $request->input('supplier_id');

        $query = PurchaseOrder::query()
            ->where('business_id', $businessId)
            ->with(['supplier'])
            ->withCount('lines')
            ->addSelect([
                'computed_total_amount' => PurchaseOrderLine::query()
                    ->selectRaw('SUM(COALESCE(line_total, (COALESCE(quantity_ordered,0) * COALESCE(unit_cost,0))))')
                    ->whereColumn('purchase_order_id', 'purchase_orders.id'),
            ])
            ->orderByDesc('id');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }
        if ($status) {
            $query->where('status', $status);
        }
        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        return response()->json(['data' => $query->paginate(50)]);
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage purchase orders')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $stockQtyRules = BusinessQuantityPolicy::stockQuantityRules($business);

        $data = $request->all();
        $validator = Validator::make($data, [
            'branch_id' => ['required', 'integer', 'exists:branches,id,business_id,'.$businessId],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id,business_id,'.$businessId],
            'po_number' => ['nullable', 'string', 'max:255'],
            'expected_at' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'subtotal' => ['nullable', 'numeric'],
            'tax_amount' => ['nullable', 'numeric'],
            'total_amount' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id,business_id,'.$businessId],
            'lines.*.branch_product_id' => ['required', 'integer', 'exists:branch_products,id,branch_id,'.$data['branch_id'] ?? 0],
            'lines.*.quantity_ordered' => $stockQtyRules,
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_total' => ['nullable', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $v = $validator->validated();
        if (! $this->userHasBranchAccess($user, (int) $businessId, (int) $v['branch_id'])) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $poNumber = $v['po_number'] ?? null;
        if (! $poNumber) {
            $date = now()->format('Ymd');
            $poNumber = 'PO-'.$date.'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        }

        $po = PurchaseOrder::create([
            'business_id' => (int) $businessId,
            'branch_id' => (int) $v['branch_id'],
            'supplier_id' => (int) $v['supplier_id'],
            'po_number' => $poNumber,
            'status' => 'draft',
            'expected_at' => $v['expected_at'] ?? null,
            'currency' => $v['currency'] ?? null,
            'subtotal' => $v['subtotal'] ?? null,
            'tax_amount' => $v['tax_amount'] ?? null,
            'total_amount' => $v['total_amount'] ?? null,
            'created_by' => $user->id,
            'notes' => $v['notes'] ?? null,
        ]);

        foreach ($v['lines'] as $line) {
            PurchaseOrderLine::create([
                'purchase_order_id' => $po->id,
                'product_id' => (int) $line['product_id'],
                'branch_product_id' => (int) $line['branch_product_id'],
                'quantity_ordered' => BusinessQuantityPolicy::normalizeForBusiness($business, (float) $line['quantity_ordered']),
                'quantity_received' => 0,
                'unit_cost' => $line['unit_cost'] ?? null,
                'tax_rate' => $line['tax_rate'] ?? null,
                'line_total' => $line['line_total'] ?? null,
                'notes' => $line['notes'] ?? null,
            ]);
        }

        return response()->json(['purchase_order' => $po->load(['supplier', 'lines'])], 201);
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage purchase orders')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $po = PurchaseOrder::where('business_id', $businessId)->where('id', $id)->with(['supplier', 'lines.product'])->first();
        if (! $po) {
            return response()->json(['message' => 'Purchase order not found'], 404);
        }

        if (! $this->userHasBranchAccess($user, (int) $businessId, (int) $po->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        return response()->json(['purchase_order' => $po]);
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage purchase orders')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $po = PurchaseOrder::where('business_id', $businessId)->where('id', $id)->first();
        if (! $po) {
            return response()->json(['message' => 'Purchase order not found'], 404);
        }

        if ($po->status !== 'draft') {
            return response()->json(['message' => 'Only draft purchase orders can be edited'], 422);
        }

        $validator = Validator::make($request->all(), [
            'expected_at' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $po->update($validator->validated());

        return response()->json(['purchase_order' => $po->fresh()->load(['supplier', 'lines'])]);
    }

    public function submit(Request $request, $id)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage purchase orders')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $po = PurchaseOrder::where('business_id', $businessId)->where('id', $id)->first();
        if (! $po) {
            return response()->json(['message' => 'Purchase order not found'], 404);
        }

        if ($po->status !== 'draft') {
            return response()->json(['message' => 'Only draft purchase orders can be submitted'], 422);
        }

        $po->update(['status' => 'sent', 'sent_at' => now()]);

        return response()->json(['purchase_order' => $po->fresh()->load(['supplier', 'lines'])]);
    }

    public function cancel(Request $request, $id)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage purchase orders')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $po = PurchaseOrder::where('business_id', $businessId)->where('id', $id)->first();
        if (! $po) {
            return response()->json(['message' => 'Purchase order not found'], 404);
        }

        $po->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return response()->json(['purchase_order' => $po->fresh()->load(['supplier', 'lines'])]);
    }

    public function receivable(Request $request, $id)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage purchase orders')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $po = PurchaseOrder::where('business_id', $businessId)->where('id', $id)->with(['lines.product'])->first();
        if (! $po) {
            return response()->json(['message' => 'Purchase order not found'], 404);
        }

        if (! $this->userHasBranchAccess($user, (int) $businessId, (int) $po->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $rows = $po->lines->map(function ($l) {
            $ordered = (float) ($l->quantity_ordered ?? 0);
            $received = (float) ($l->quantity_received ?? 0);
            $remaining = max(0, $ordered - $received);

            return [
                'id' => $l->id,
                'product_id' => $l->product_id,
                'branch_product_id' => $l->branch_product_id,
                'product' => $l->product ? ['id' => $l->product->id, 'name' => $l->product->name, 'sku' => $l->product->sku] : null,
                'quantity_ordered' => $ordered,
                'quantity_received' => $received,
                'quantity_remaining' => $remaining,
                'unit_cost' => $l->unit_cost,
                'tax_rate' => $l->tax_rate,
                'line_total' => $l->line_total,
            ];
        });

        return response()->json([
            'purchase_order_id' => $po->id,
            'po_number' => $po->po_number,
            'lines' => $rows,
        ]);
    }

    /**
     * Analytics: top variance items (ordered vs received).
     */
    public function topVarianceItems(Request $request)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view inventory') && ! $user->hasPermissionTo('manage purchase orders')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $limit = (int) ($request->input('limit') ?? 10);
        $limit = max(1, min(50, $limit));

        $rows = DB::table('purchase_order_lines as l')
            ->join('purchase_orders as p', 'p.id', '=', 'l.purchase_order_id')
            ->join('products as pr', 'pr.id', '=', 'l.product_id')
            ->where('p.business_id', $businessId)
            ->whereNotIn('p.status', ['cancelled', 'received'])
            ->selectRaw('l.product_id, pr.name as product_name, SUM(l.quantity_ordered) as qty_ordered, SUM(l.quantity_received) as qty_received, (SUM(l.quantity_ordered) - SUM(l.quantity_received)) as variance')
            ->groupBy('l.product_id', 'pr.name')
            ->havingRaw('(SUM(l.quantity_ordered) - SUM(l.quantity_received)) > 0')
            ->orderByDesc('variance')
            ->limit($limit)
            ->get();

        return response()->json(['data' => $rows]);
    }
}
