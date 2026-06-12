<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\Branch;
use App\Models\GoodsReceivedNote;
use App\Models\GoodsReceivedNoteLine;
use App\Models\Supplier;
use App\Services\GoodsReceivingService;
use App\Support\BusinessQuantityPolicy;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GoodsReceivedNoteController extends Controller
{
    use HasBranchAccess;

    public function __construct(
        private readonly GoodsReceivingService $service,
    ) {}

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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view grn')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $branchId = $request->input('branch_id');
        $status = $request->input('status');

        $query = GoodsReceivedNote::query()
            ->where('business_id', $businessId)
            ->with(['supplier'])
            ->orderByDesc('id');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }
        if ($status) {
            $query->where('status', $status);
        }

        return response()->json(['data' => $query->paginate(50)]);
    }

    /**
     * Analytics: receipts grouped by supplier (last 30 days).
     */
    public function receiptsBySupplier(Request $request)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view inventory') && ! $user->hasPermissionTo('view grn')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $days = (int) ($request->input('days') ?? 30);
        $since = now()->subDays(max(1, min(365, $days)));

        $rows = DB::table('goods_received_notes as g')
            ->join('suppliers as s', 's.id', '=', 'g.supplier_id')
            ->join('goods_received_note_lines as l', 'l.goods_received_note_id', '=', 'g.id')
            ->where('g.business_id', $businessId)
            ->where('g.status', 'posted')
            ->where('g.posted_at', '>=', $since)
            ->selectRaw('g.supplier_id, s.name as supplier_name, COUNT(DISTINCT g.id) as grn_count, SUM(COALESCE(l.line_total, (COALESCE(l.quantity_accepted,0) * COALESCE(l.unit_cost,0)))) as total_cost')
            ->groupBy('g.supplier_id', 's.name')
            ->orderByDesc('total_cost')
            ->limit(20)
            ->get();

        return response()->json(['data' => $rows]);
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('create grn')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'branch_id' => ['required', 'integer', 'exists:branches,id,business_id,'.$businessId],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id,business_id,'.$businessId],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id,business_id,'.$businessId],
            'received_at' => ['nullable', 'date'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:255'],
            'supplier_invoice_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'subtotal' => ['nullable', 'numeric'],
            'tax_amount' => ['nullable', 'numeric'],
            'freight' => ['nullable', 'numeric'],
            'other_charges' => ['nullable', 'numeric'],
            'total_amount' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'client_uuid' => ['nullable', 'uuid'],
            'meta_data' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        // Verify branch access
        $branchId = (int) $validator->validated()['branch_id'];
        if (! $this->userHasBranchAccess($user, (int) $businessId, $branchId)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $grn = $this->service->createDraft(array_merge($validator->validated(), [
            'business_id' => (int) $businessId,
            'received_by' => $user->id,
        ]));

        return response()->json(['grn' => $grn->load('supplier')], 201);
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view grn')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $grn = GoodsReceivedNote::where('business_id', $businessId)
            ->where('id', $id)
            ->with(['supplier', 'lines.product', 'lines.branchProduct'])
            ->first();

        if (! $grn) {
            return response()->json(['message' => 'GRN not found'], 404);
        }

        if (! $this->userHasBranchAccess($user, (int) $businessId, (int) $grn->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        return response()->json(['grn' => $grn]);
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('create grn')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $grn = GoodsReceivedNote::where('business_id', $businessId)->where('id', $id)->first();
        if (! $grn) {
            return response()->json(['message' => 'GRN not found'], 404);
        }
        if ($grn->status !== 'draft') {
            return response()->json(['message' => 'Only draft GRNs can be edited'], 422);
        }

        if (! $this->userHasBranchAccess($user, (int) $businessId, (int) $grn->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id,business_id,'.$businessId],
            'received_at' => ['nullable', 'date'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:255'],
            'supplier_invoice_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'subtotal' => ['nullable', 'numeric'],
            'tax_amount' => ['nullable', 'numeric'],
            'freight' => ['nullable', 'numeric'],
            'other_charges' => ['nullable', 'numeric'],
            'total_amount' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string'],
            'meta_data' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $grn->update($validator->validated());

        return response()->json(['grn' => $grn->fresh()->load('supplier')]);
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('create grn')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $grn = GoodsReceivedNote::where('business_id', $businessId)->where('id', $id)->first();
        if (! $grn) {
            return response()->json(['message' => 'GRN not found'], 404);
        }

        if (! in_array($grn->status, ['draft', 'rejected', 'cancelled'], true)) {
            return response()->json(['message' => 'Only draft, rejected, or cancelled GRNs can be deleted'], 422);
        }

        $grn->delete();

        return response()->json(['message' => 'GRN deleted']);
    }

    public function addLine(Request $request, $id)
    {
        return $this->upsertLine($request, $id, null);
    }

    public function updateLine(Request $request, $id, $lineId)
    {
        return $this->upsertLine($request, $id, $lineId);
    }

    private function upsertLine(Request $request, $id, $lineId = null)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('create grn')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $grn = GoodsReceivedNote::where('business_id', $businessId)->where('id', $id)->first();
        if (! $grn) {
            return response()->json(['message' => 'GRN not found'], 404);
        }
        if (! $this->userHasBranchAccess($user, (int) $businessId, (int) $grn->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $line = null;
        if ($lineId !== null) {
            $line = GoodsReceivedNoteLine::where('goods_received_note_id', $grn->id)->where('id', $lineId)->first();
            if (! $line) {
                return response()->json(['message' => 'Line not found'], 404);
            }
        }

        $stockQtyRules = BusinessQuantityPolicy::stockQuantityRules($business);

        $data = $request->all();
        $validator = Validator::make($data, [
            'product_id' => ['required', 'integer', 'exists:products,id,business_id,'.$businessId],
            'branch_product_id' => ['required', 'integer', 'exists:branch_products,id,branch_id,'.$grn->branch_id],
            'quantity_received' => $stockQtyRules,
            'quantity_accepted' => ['required', 'numeric', 'min:0'],
            'quantity_rejected' => ['nullable', 'numeric', 'min:0'],
            'rejection_reason' => ['nullable', 'string'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0'],
            'line_total' => ['nullable', 'numeric', 'min:0'],
            'batch_number' => ['nullable', 'string', 'max:255'],
            'lot_number' => ['nullable', 'string', 'max:255'],
            'manufacturing_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after:manufacturing_date'],
            'storage_location' => ['nullable', 'in:shelf,store'],
            'notes' => ['nullable', 'string'],
            'meta_data' => ['nullable', 'array'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $v = $validator->validated();
        $v['quantity_received'] = BusinessQuantityPolicy::normalizeForBusiness($business, (float) $v['quantity_received']);
        $v['quantity_accepted'] = BusinessQuantityPolicy::normalizeForBusiness($business, (float) $v['quantity_accepted']);
        if (isset($v['quantity_rejected'])) {
            $v['quantity_rejected'] = BusinessQuantityPolicy::normalizeForBusiness($business, (float) $v['quantity_rejected']);
        }
        $qtyReceived = $v['quantity_received'];
        $qtyAccepted = $v['quantity_accepted'];
        $qtyRejected = (float) ($v['quantity_rejected'] ?? 0);
        if ($qtyAccepted + $qtyRejected - $qtyReceived > Quantity::EPSILON) {
            return response()->json(['message' => 'Accepted + rejected cannot exceed received'], 422);
        }

        $saved = $this->service->addOrUpdateLine($grn, $v, $line);

        return response()->json(['line' => $saved->fresh()]);
    }

    public function deleteLine(Request $request, $id, $lineId)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('create grn')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $grn = GoodsReceivedNote::where('business_id', $businessId)->where('id', $id)->first();
        if (! $grn) {
            return response()->json(['message' => 'GRN not found'], 404);
        }

        $line = GoodsReceivedNoteLine::where('goods_received_note_id', $grn->id)->where('id', $lineId)->first();
        if (! $line) {
            return response()->json(['message' => 'Line not found'], 404);
        }

        $this->service->removeLine($grn, $line);

        return response()->json(['message' => 'Line deleted']);
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('create grn')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $grn = GoodsReceivedNote::where('business_id', $businessId)->where('id', $id)->first();
        if (! $grn) {
            return response()->json(['message' => 'GRN not found'], 404);
        }
        if (! $this->userHasBranchAccess($user, (int) $businessId, (int) $grn->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        try {
            $submitted = $this->service->submit($grn, $user->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['grn' => $submitted]);
    }

    public function approve(Request $request, $id)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('approve grn')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $grn = GoodsReceivedNote::where('business_id', $businessId)->where('id', $id)->with(['lines.branchProduct.product', 'supplier'])->first();
        if (! $grn) {
            return response()->json(['message' => 'GRN not found'], 404);
        }
        if (! $this->userHasBranchAccess($user, (int) $businessId, (int) $grn->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        try {
            $posted = $this->service->approveAndPost($grn, $user->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['grn' => $posted]);
    }

    public function reject(Request $request, $id)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('approve grn')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $grn = GoodsReceivedNote::where('business_id', $businessId)->where('id', $id)->first();
        if (! $grn) {
            return response()->json(['message' => 'GRN not found'], 404);
        }
        if (! $this->userHasBranchAccess($user, (int) $businessId, (int) $grn->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'min:2'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        try {
            $rejected = $this->service->reject($grn, $user->id, (string) $validator->validated()['reason']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['grn' => $rejected]);
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('create grn')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $grn = GoodsReceivedNote::where('business_id', $businessId)->where('id', $id)->first();
        if (! $grn) {
            return response()->json(['message' => 'GRN not found'], 404);
        }

        try {
            $cancelled = $this->service->cancel($grn, $user->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['grn' => $cancelled]);
    }
}
