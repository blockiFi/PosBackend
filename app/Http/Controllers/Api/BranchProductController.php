<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\TieredPricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BranchProductController extends Controller
{
    use HasBranchAccess;

    /**
     * List all products for a specific branch
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');
        $branchId = $request->input('branch_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        if (! $branchId) {
            return response()->json([
                'message' => 'Branch ID is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view products', 'api', $businessId) && ! $user->hasPermissionTo('view inventory', 'api', $businessId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Verify branch belongs to business
        $branch = Branch::where('id', $branchId)
            ->where('business_id', $businessId)
            ->first();

        if (! $branch) {
            return response()->json(['message' => 'Branch not found'], 404);
        }

        // Verify user has access to this branch
        if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        // Get query parameters for filtering
        $isAvailable = $request->input('is_available');
        $isFeatured = $request->input('is_featured');
        $stockStatus = $request->input('stock_status'); // in_stock, low_stock, out_of_stock
        $search = $request->input('search');
        $perPage = $request->input('per_page', 15);

        $query = BranchProduct::where('branch_id', $branchId)
            ->with(['product.category', 'product.business']);

        // Apply filters
        if ($isAvailable !== null) {
            $query->where('is_available', filter_var($isAvailable, FILTER_VALIDATE_BOOLEAN));
        }

        if ($isFeatured !== null) {
            $query->where('is_featured', filter_var($isFeatured, FILTER_VALIDATE_BOOLEAN));
        }

        if ($stockStatus) {
            switch ($stockStatus) {
                case 'in_stock':
                    $query->inStock();
                    break;
                case 'low_stock':
                    $query->lowStock();
                    break;
                case 'out_of_stock':
                    $query->where('stock_quantity', '<=', 0)
                        ->where('allow_backorder', false);
                    break;
            }
        }

        if ($search) {
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $branchProducts = $query->orderBy('display_order')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Transform data
        $data = $branchProducts->map(function ($branchProduct) {
            return [
                'id' => $branchProduct->id,
                'branch_id' => $branchProduct->branch_id,
                'product_id' => $branchProduct->product_id,
                'product' => [
                    'id' => $branchProduct->product->id,
                    'name' => $branchProduct->product->name,
                    'sku' => $branchProduct->product->sku,
                    'barcode' => $branchProduct->product->barcode,
                    'image' => $branchProduct->product->image,
                    'category' => $branchProduct->product->category ? [
                        'id' => $branchProduct->product->category->id,
                        'name' => $branchProduct->product->category->name,
                    ] : null,
                ],
                'pricing' => [
                    'cost_price' => $branchProduct->cost_price,
                    'selling_price' => $branchProduct->selling_price,
                    'compare_price' => $branchProduct->compare_price,
                    'discount_amount' => $branchProduct->discount_amount,
                    'discount_type' => $branchProduct->discount_type,
                    'tax_rate' => $branchProduct->tax_rate,
                    'final_price' => $branchProduct->getFinalPrice(),
                    'price_with_tax' => $branchProduct->getPriceWithTax(),
                    'profit_margin' => $branchProduct->getProfitMargin(),
                ],
                'inventory' => [
                    'stock_quantity' => $branchProduct->stock_quantity,
                    'shelf_quantity' => $branchProduct->shelf_quantity,
                    'store_quantity' => $branchProduct->store_quantity,
                    'low_stock_threshold' => $branchProduct->low_stock_threshold,
                    'allow_backorder' => $branchProduct->allow_backorder,
                    'reorder_point' => $branchProduct->reorder_point,
                    'reorder_quantity' => $branchProduct->reorder_quantity,
                    'is_in_stock' => $branchProduct->isInStock(),
                    'is_low_stock' => $branchProduct->isLowStock(),
                    'is_out_of_stock' => $branchProduct->isOutOfStock(),
                    'needs_reorder' => $branchProduct->needsReorder(),
                    'shelf_needs_restocking' => $branchProduct->shelfNeedsRestocking(),
                    'bin_location' => $branchProduct->bin_location,
                    'shelf_location' => $branchProduct->shelf_location,
                ],
                'settings' => [
                    'is_available' => $branchProduct->is_available,
                    'is_featured' => $branchProduct->is_featured,
                    'display_order' => $branchProduct->display_order,
                ],
                'branch_meta_data' => $branchProduct->branch_meta_data,
                'created_at' => $branchProduct->created_at,
                'updated_at' => $branchProduct->updated_at,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $branchProducts->currentPage(),
                'last_page' => $branchProducts->lastPage(),
                'per_page' => $branchProducts->perPage(),
                'total' => $branchProducts->total(),
            ],
        ]);
    }

    /**
     * Add or update a product in a branch
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage branch products', 'api', $businessId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'compare_price' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', 'in:fixed,percentage'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'shelf_quantity' => ['nullable', 'integer', 'min:0'],
            'store_quantity' => ['nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'allow_backorder' => ['nullable', 'boolean'],
            'reorder_point' => ['nullable', 'integer', 'min:0'],
            'reorder_quantity' => ['nullable', 'integer', 'min:0'],
            'is_available' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer'],
            'bin_location' => ['nullable', 'string', 'max:255'],
            'shelf_location' => ['nullable', 'string', 'max:255'],
            'branch_meta_data' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify branch belongs to business
        $branch = Branch::where('id', $data['branch_id'])
            ->where('business_id', $businessId)
            ->first();

        if (! $branch) {
            return response()->json(['message' => 'Branch not found'], 404);
        }

        // Verify user has access to this branch
        if (! $this->userHasBranchAccess($user, $businessId, $data['branch_id'])) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        // Verify product belongs to business
        $product = Product::where('id', $data['product_id'])
            ->where('business_id', $businessId)
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        // Check if branch product already exists
        $branchProduct = BranchProduct::where('branch_id', $data['branch_id'])
            ->where('product_id', $data['product_id'])
            ->first();

        if ($branchProduct) {
            return response()->json([
                'message' => 'Product already exists in this branch. Use update endpoint to modify.',
            ], 422);
        }

        // Calculate total stock from shelf and store quantities
        $shelfQty = $data['shelf_quantity'] ?? 0;
        $storeQty = $data['store_quantity'] ?? 0;
        $totalStock = $shelfQty + $storeQty;

        // If stock_quantity is provided but not shelf/store, put all on shelf
        if (isset($data['stock_quantity']) && ! isset($data['shelf_quantity']) && ! isset($data['store_quantity'])) {
            $shelfQty = $data['stock_quantity'];
            $totalStock = $data['stock_quantity'];
        }

        // Create branch product
        $branchProduct = BranchProduct::create([
            'branch_id' => $data['branch_id'],
            'product_id' => $data['product_id'],
            'cost_price' => $data['cost_price'] ?? null,
            'selling_price' => $product->base_selling_price ?? null,
            'compare_price' => $data['compare_price'] ?? null,
            'discount_amount' => $data['discount_amount'] ?? null,
            'discount_type' => $data['discount_type'] ?? null,
            'tax_rate' => $data['tax_rate'] ?? null,
            'stock_quantity' => $totalStock,
            'shelf_quantity' => $shelfQty,
            'store_quantity' => $storeQty,
            'low_stock_threshold' => $data['low_stock_threshold'] ?? null,
            'allow_backorder' => $data['allow_backorder'] ?? false,
            'reorder_point' => $data['reorder_point'] ?? null,
            'reorder_quantity' => $data['reorder_quantity'] ?? null,
            'is_available' => $data['is_available'] ?? true,
            'is_featured' => $data['is_featured'] ?? false,
            'display_order' => $data['display_order'] ?? 0,
            'bin_location' => $data['bin_location'] ?? null,
            'shelf_location' => $data['shelf_location'] ?? null,
            'branch_meta_data' => $data['branch_meta_data'] ?? null,
        ]);

        $branchProduct->load('product.category');

        return response()->json([
            'message' => 'Product added to branch successfully',
            'data' => $this->transformBranchProduct($branchProduct),
        ], 201);
    }

    /**
     * Assign multiple products to a branch using default product data
     */
    public function assignMultiple(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage branch products', 'api', $businessId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify branch belongs to business
        $branch = Branch::where('id', $data['branch_id'])
            ->where('business_id', $businessId)
            ->first();

        if (! $branch) {
            return response()->json(['message' => 'Branch not found'], 404);
        }

        // Verify user has access to this branch
        if (! $this->userHasBranchAccess($user, $businessId, $data['branch_id'])) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        // Get all products that belong to the business
        $products = Product::whereIn('id', $data['product_ids'])
            ->where('business_id', $businessId)
            ->get();

        if ($products->count() !== count($data['product_ids'])) {
            return response()->json([
                'message' => 'Some products not found or do not belong to this business',
            ], 422);
        }

        // Get existing branch products to skip duplicates
        $existingBranchProducts = BranchProduct::where('branch_id', $data['branch_id'])
            ->whereIn('product_id', $data['product_ids'])
            ->pluck('product_id')
            ->toArray();

        $created = [];
        $skipped = [];

        foreach ($products as $product) {
            // Skip if already assigned
            if (in_array($product->id, $existingBranchProducts)) {
                $skipped[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'reason' => 'Product already assigned to this branch',
                ];

                continue;
            }

            // Use default product data
            $branchProduct = BranchProduct::create([
                'branch_id' => $data['branch_id'],
                'product_id' => $product->id,
                'cost_price' => $product->base_cost_price,
                'selling_price' => $product->base_selling_price,
                'compare_price' => $product->base_selling_price,
                'tax_rate' => $product->default_tax_rate,
                'stock_quantity' => 0,
                'shelf_quantity' => 0,
                'store_quantity' => 0,
                'low_stock_threshold' => $product->low_stock_threshold,
                'allow_backorder' => false,
                'is_available' => true,
                'is_featured' => false,
                'display_order' => 0,
            ]);

            $created[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'branch_product_id' => $branchProduct->id,
            ];
        }

        return response()->json([
            'message' => 'Products assigned to branch',
            'data' => [
                'branch_id' => $data['branch_id'],
                'branch_name' => $branch->name,
                'total_requested' => count($data['product_ids']),
                'created' => count($created),
                'skipped' => count($skipped),
                'created_products' => $created,
                'skipped_products' => $skipped,
            ],
        ], 201);
    }

    /**
     * Get a specific branch product
     */
    public function show(Request $request, int $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view products', 'api', $businessId) && ! $user->hasPermissionTo('view inventory', 'api', $businessId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $branchProduct = BranchProduct::with(['product.category', 'branch'])
            ->find($id);

        if (! $branchProduct) {
            return response()->json(['message' => 'Branch product not found'], 404);
        }

        // Verify branch belongs to business
        if ($branchProduct->branch->business_id != $businessId) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        // Verify user has access to this branch
        if (! $this->userHasBranchAccess($user, $businessId, $branchProduct->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        return response()->json([
            'data' => $this->transformBranchProduct($branchProduct),
        ]);
    }

    /**
     * Get tiered price for a branch product and quantity (for POS dynamic total).
     */
    public function getPrice(Request $request, int $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');
        $quantity = (float) $request->input('quantity', 1);

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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view products', 'api', $businessId) && ! $user->hasPermissionTo('view inventory', 'api', $businessId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $branchProduct = BranchProduct::with('branch')->find($id);

        if (! $branchProduct) {
            return response()->json(['message' => 'Branch product not found'], 404);
        }

        if ($branchProduct->branch->business_id != $businessId) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        if (! $this->userHasBranchAccess($user, $businessId, $branchProduct->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        if ($quantity <= 0) {
            return response()->json(['message' => 'Quantity must be greater than 0'], 422);
        }

        $result = app(TieredPricingService::class)->getUnitPrice($branchProduct, $quantity);

        return response()->json([
            'data' => [
                'unit_price' => $result['unit_price'],
                'total' => $result['total'],
                'tier_type' => $result['tier_type'],
                'product_unit_id' => $result['product_unit_id'],
                'quantity_tier_id' => $result['quantity_tier_id'],
                'cost_per_unit' => $result['cost_per_unit'],
            ],
        ]);
    }

    /**
     * Update a branch product
     */
    public function update(Request $request, int $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage branch products', 'api', $businessId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $branchProduct = BranchProduct::with('branch')->find($id);

        if (! $branchProduct) {
            return response()->json(['message' => 'Branch product not found'], 404);
        }

        // Verify branch belongs to business
        if ($branchProduct->branch->business_id != $businessId) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        // Verify user has access to this branch
        if (! $this->userHasBranchAccess($user, $businessId, $branchProduct->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'compare_price' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', 'in:fixed,percentage'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'shelf_quantity' => ['nullable', 'integer', 'min:0'],
            'store_quantity' => ['nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'allow_backorder' => ['nullable', 'boolean'],
            'reorder_point' => ['nullable', 'integer', 'min:0'],
            'reorder_quantity' => ['nullable', 'integer', 'min:0'],
            'is_available' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer'],
            'bin_location' => ['nullable', 'string', 'max:255'],
            'shelf_location' => ['nullable', 'string', 'max:255'],
            'branch_meta_data' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Update only provided fields (selling_price is set via updateSellingPrice endpoint)
        $updateData = [];
        $fillableFields = [
            'cost_price', 'compare_price',
            'discount_amount', 'discount_type', 'tax_rate',
            'stock_quantity', 'shelf_quantity', 'store_quantity',
            'low_stock_threshold', 'allow_backorder',
            'reorder_point', 'reorder_quantity',
            'is_available', 'is_featured', 'display_order',
            'bin_location', 'shelf_location', 'branch_meta_data',
        ];

        foreach ($fillableFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        // If shelf or store quantities are updated, recalculate total stock
        if (isset($updateData['shelf_quantity']) || isset($updateData['store_quantity'])) {
            $newShelf = $updateData['shelf_quantity'] ?? $branchProduct->shelf_quantity;
            $newStore = $updateData['store_quantity'] ?? $branchProduct->store_quantity;
            $updateData['stock_quantity'] = $newShelf + $newStore;
        }

        $branchProduct->update($updateData);
        $branchProduct->load('product.category');

        return response()->json([
            'message' => 'Branch product updated successfully',
            'data' => $this->transformBranchProduct($branchProduct),
        ]);
    }

    /**
     * Update selling price of a branch product.
     * Requires 'set branch product selling price' permission.
     */
    public function updateSellingPrice(Request $request, int $id)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('set branch product selling price', 'api', $businessId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'selling_price' => ['required', 'numeric', 'min:0'],
        ]);

        $branchProduct = BranchProduct::with('branch')->find($id);

        if (! $branchProduct) {
            return response()->json(['message' => 'Branch product not found'], 404);
        }

        if ($branchProduct->branch->business_id != $businessId) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        if (! $this->userHasBranchAccess($user, $businessId, $branchProduct->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $branchProduct->selling_price = $validated['selling_price'];
        $branchProduct->save();
        $branchProduct->load('product.category');

        return response()->json([
            'message' => 'Selling price updated successfully',
            'data' => $this->transformBranchProduct($branchProduct),
        ]);
    }

    /**
     * Delete a branch product (remove product from branch)
     */
    public function destroy(Request $request, int $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage branch products', 'api', $businessId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $branchProduct = BranchProduct::with('branch')->find($id);

        if (! $branchProduct) {
            return response()->json(['message' => 'Branch product not found'], 404);
        }

        // Verify branch belongs to business
        if ($branchProduct->branch->business_id != $businessId) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        // Verify user has access to this branch
        if (! $this->userHasBranchAccess($user, $businessId, $branchProduct->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $branchProduct->delete();

        return response()->json([
            'message' => 'Product removed from branch successfully',
        ]);
    }

    /**
     * Update stock quantity for a branch product
     */
    public function updateStock(Request $request, int $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage inventory', 'api', $businessId) && ! $user->hasPermissionTo('adjust inventory', 'api', $businessId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $branchProduct = BranchProduct::with('branch')->find($id);

        if (! $branchProduct) {
            return response()->json(['message' => 'Branch product not found'], 404);
        }

        // Verify branch belongs to business
        if ($branchProduct->branch->business_id != $businessId) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        // Verify user has access to this branch
        if (! $this->userHasBranchAccess($user, $businessId, $branchProduct->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'quantity' => ['required', 'integer'],
            'operation' => ['required', 'in:add,subtract,set'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $previousQuantity = $branchProduct->stock_quantity;
        $branchProduct->updateStock($data['quantity'], $data['operation']);
        $branchProduct->load('product.category');

        return response()->json([
            'message' => 'Stock updated successfully',
            'data' => [
                'previous_quantity' => $previousQuantity,
                'new_quantity' => $branchProduct->stock_quantity,
                'operation' => $data['operation'],
                'branch_product' => $this->transformBranchProduct($branchProduct),
            ],
        ]);
    }

    /**
     * Get stock summary for a branch
     */
    public function stockSummary(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');
        $branchId = $request->input('branch_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        if (! $branchId) {
            return response()->json([
                'message' => 'Branch ID is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view inventory', 'api', $businessId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Verify branch belongs to business
        $branch = Branch::where('id', $branchId)
            ->where('business_id', $businessId)
            ->first();

        if (! $branch) {
            return response()->json(['message' => 'Branch not found'], 404);
        }

        // Verify user has access to this branch
        if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $totalProducts = BranchProduct::where('branch_id', $branchId)->count();
        $inStockProducts = BranchProduct::where('branch_id', $branchId)
            ->inStock()
            ->count();
        $lowStockProducts = BranchProduct::where('branch_id', $branchId)
            ->lowStock()
            ->count();
        $outOfStockProducts = BranchProduct::where('branch_id', $branchId)
            ->where('stock_quantity', '<=', 0)
            ->where('allow_backorder', false)
            ->count();
        $needsReorderProducts = BranchProduct::where('branch_id', $branchId)
            ->whereNotNull('reorder_point')
            ->whereColumn('stock_quantity', '<=', 'reorder_point')
            ->count();

        $totalInventoryValue = BranchProduct::where('branch_id', $branchId)
            ->get()
            ->sum(function ($bp) {
                return $bp->getEffectiveCostPrice() * $bp->stock_quantity;
            });

        $totalRetailValue = BranchProduct::where('branch_id', $branchId)
            ->get()
            ->sum(function ($bp) {
                return $bp->getEffectiveSellingPrice() * $bp->stock_quantity;
            });

        return response()->json([
            'data' => [
                'branch_id' => $branchId,
                'branch_name' => $branch->name,
                'summary' => [
                    'total_products' => $totalProducts,
                    'in_stock' => $inStockProducts,
                    'low_stock' => $lowStockProducts,
                    'out_of_stock' => $outOfStockProducts,
                    'needs_reorder' => $needsReorderProducts,
                ],
                'valuation' => [
                    'total_inventory_value' => round($totalInventoryValue, 2),
                    'total_retail_value' => round($totalRetailValue, 2),
                    'potential_profit' => round($totalRetailValue - $totalInventoryValue, 2),
                ],
            ],
        ]);
    }

    /**
     * Bulk update branch products
     */
    public function bulkUpdate(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage branch products', 'api', $businessId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'updates' => ['required', 'array'],
            'updates.*.id' => ['required', 'integer', 'exists:branch_products,id'],
            'updates.*.data' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updated = [];
        $failed = [];

        foreach ($data['updates'] as $update) {
            $branchProduct = BranchProduct::with('branch')->find($update['id']);

            if (! $branchProduct || $branchProduct->branch->business_id != $businessId) {
                $failed[] = [
                    'id' => $update['id'],
                    'reason' => 'Not found or access denied',
                ];

                continue;
            }

            // Verify user has access to this branch
            if (! $this->userHasBranchAccess($user, $businessId, $branchProduct->branch_id)) {
                $failed[] = [
                    'id' => $update['id'],
                    'reason' => 'You do not have access to this branch',
                ];

                continue;
            }

            try {
                $branchProduct->update($update['data']);
                $updated[] = $update['id'];
            } catch (\Exception $e) {
                $failed[] = [
                    'id' => $update['id'],
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => 'Bulk update completed',
            'data' => [
                'updated' => $updated,
                'failed' => $failed,
                'total_attempted' => count($data['updates']),
                'successful' => count($updated),
                'failed_count' => count($failed),
            ],
        ]);
    }

    /**
     * Get branch products by category ID (includes all child and descendant categories)
     */
    public function getByCategory(Request $request)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');
        $categoryId = $request->input('category_id');
        $branchId = $request->input('branch_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        if (! $categoryId) {
            return response()->json([
                'message' => 'Category ID is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view products', 'api', $businessId) && ! $user->hasPermissionTo('view inventory', 'api', $businessId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Get the category and verify it belongs to the business
        $category = \App\Models\ProductCategory::where('id', $categoryId)
            ->where('business_id', $businessId)
            ->first();

        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        // Get all descendant category IDs recursively
        $categoryIds = $this->getAllDescendantCategoryIds($category);

        // Include the parent category itself
        array_unshift($categoryIds, $category->id);

        // Query parameters for filtering
        $isAvailable = $request->input('is_available');
        $isFeatured = $request->input('is_featured');
        $stockStatus = $request->input('stock_status');
        $search = $request->input('search');
        $perPage = $request->input('per_page', 15);

        // Build query for branch products
        $query = BranchProduct::with(['product.category', 'product.business'])
            ->whereHas('product', function ($q) use ($categoryIds) {
                $q->whereIn('category_id', $categoryIds);
            });

        // Filter by branch if provided
        if ($branchId) {
            $branch = Branch::where('id', $branchId)
                ->where('business_id', $businessId)
                ->first();

            if (! $branch) {
                return response()->json(['message' => 'Branch not found'], 404);
            }

            // Verify user has access to this branch
            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json(['message' => 'You do not have access to this branch'], 403);
            }

            $query->where('branch_id', $branchId);
        } else {
            // Scope to user's permitted branches
            $permittedBranches = $this->getPermittedBranches($user, $businessId);
            if ($permittedBranches->isNotEmpty()) {
                $query->whereIn('branch_id', $permittedBranches);
            } else {
                // If no branch restrictions, get products from all branches in the business
                $query->whereHas('branch', function ($q) use ($businessId) {
                    $q->where('business_id', $businessId);
                });
            }
        }

        // Apply filters
        if ($isAvailable !== null) {
            $query->where('is_available', filter_var($isAvailable, FILTER_VALIDATE_BOOLEAN));
        }

        if ($isFeatured !== null) {
            $query->where('is_featured', filter_var($isFeatured, FILTER_VALIDATE_BOOLEAN));
        }

        if ($stockStatus) {
            switch ($stockStatus) {
                case 'in_stock':
                    $query->inStock();
                    break;
                case 'low_stock':
                    $query->lowStock();
                    break;
                case 'out_of_stock':
                    $query->where('stock_quantity', '<=', 0)
                        ->where('allow_backorder', false);
                    break;
            }
        }

        if ($search) {
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $branchProducts = $query->orderBy('display_order')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Transform data
        $data = $branchProducts->map(function ($branchProduct) {
            return $this->transformBranchProduct($branchProduct);
        });

        return response()->json([
            'message' => 'Branch products retrieved successfully',
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'breadcrumb' => $category->getBreadcrumb(),
            ],
            'included_categories' => count($categoryIds),
            'data' => $data,
            'meta' => [
                'current_page' => $branchProducts->currentPage(),
                'last_page' => $branchProducts->lastPage(),
                'per_page' => $branchProducts->perPage(),
                'total' => $branchProducts->total(),
            ],
        ]);
    }

    /**
     * Recursively get all descendant category IDs
     */
    private function getAllDescendantCategoryIds(\App\Models\ProductCategory $category): array
    {
        $categoryIds = [];

        $children = $category->children;

        foreach ($children as $child) {
            $categoryIds[] = $child->id;

            // Recursively get descendants of this child
            $childDescendants = $this->getAllDescendantCategoryIds($child);
            $categoryIds = array_merge($categoryIds, $childDescendants);
        }

        return $categoryIds;
    }

    /**
     * Move stock from store to shelf
     */
    public function moveToShelf(Request $request, int $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission (direct move: owner, manage/adjust inventory, or approve shelf store move)
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id
            && ! $user->hasPermissionTo('manage inventory', 'api', $businessId)
            && ! $user->hasPermissionTo('adjust inventory', 'api', $businessId)
            && ! $user->hasPermissionTo('approve shelf store move', 'api', $businessId)) {
            return response()->json(['message' => 'Unauthorized. Use shelf-store-move-requests to request a move for approval.'], 403);
        }

        $branchProduct = BranchProduct::with('branch')->find($id);

        if (! $branchProduct) {
            return response()->json(['message' => 'Branch product not found'], 404);
        }

        // Verify branch belongs to business
        if ($branchProduct->branch->business_id != $businessId) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        // Verify user has access to this branch
        if (! $this->userHasBranchAccess($user, $businessId, $branchProduct->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($data['quantity'] > $branchProduct->store_quantity) {
            return response()->json([
                'message' => 'Insufficient quantity in store',
                'available_in_store' => $branchProduct->store_quantity,
            ], 422);
        }

        $previousShelf = $branchProduct->shelf_quantity;
        $previousStore = $branchProduct->store_quantity;

        $branchProduct->moveToShelf($data['quantity']);
        $branchProduct->load('product.category');

        return response()->json([
            'message' => 'Stock moved to shelf successfully',
            'data' => [
                'quantity_moved' => $data['quantity'],
                'previous_shelf_quantity' => $previousShelf,
                'new_shelf_quantity' => $branchProduct->shelf_quantity,
                'previous_store_quantity' => $previousStore,
                'new_store_quantity' => $branchProduct->store_quantity,
                'branch_product' => $this->transformBranchProduct($branchProduct),
            ],
        ]);
    }

    /**
     * Move stock from shelf to store
     */
    public function moveToStore(Request $request, int $id)
    {
        $user = $request->user();
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

        if (! $businessId) {
            return response()->json([
                'message' => 'Business context is required',
            ], 400);
        }

        // Verify user has access to this business
        $business = $user->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->first();

        if (! $business) {
            return response()->json(['message' => 'Business not found or access denied'], 404);
        }

        // Set permission context and check permission (direct move: owner, manage/adjust inventory, or approve shelf store move)
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id
            && ! $user->hasPermissionTo('manage inventory', 'api', $businessId)
            && ! $user->hasPermissionTo('adjust inventory', 'api', $businessId)
            && ! $user->hasPermissionTo('approve shelf store move', 'api', $businessId)) {
            return response()->json(['message' => 'Unauthorized. Use shelf-store-move-requests to request a move for approval.'], 403);
        }

        $branchProduct = BranchProduct::with('branch')->find($id);

        if (! $branchProduct) {
            return response()->json(['message' => 'Branch product not found'], 404);
        }

        // Verify branch belongs to business
        if ($branchProduct->branch->business_id != $businessId) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        // Verify user has access to this branch
        if (! $this->userHasBranchAccess($user, $businessId, $branchProduct->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($data['quantity'] > $branchProduct->shelf_quantity) {
            return response()->json([
                'message' => 'Insufficient quantity on shelf',
                'available_on_shelf' => $branchProduct->shelf_quantity,
            ], 422);
        }

        $previousShelf = $branchProduct->shelf_quantity;
        $previousStore = $branchProduct->store_quantity;

        $branchProduct->moveToStore($data['quantity']);
        $branchProduct->load('product.category');

        return response()->json([
            'message' => 'Stock moved to store successfully',
            'data' => [
                'quantity_moved' => $data['quantity'],
                'previous_shelf_quantity' => $previousShelf,
                'new_shelf_quantity' => $branchProduct->shelf_quantity,
                'previous_store_quantity' => $previousStore,
                'new_store_quantity' => $branchProduct->store_quantity,
                'branch_product' => $this->transformBranchProduct($branchProduct),
            ],
        ]);
    }

    /**
     * List unit prices for a branch product.
     */
    public function indexUnitPrices(Request $request, int $id)
    {
        $branchProduct = $this->getBranchProductForRequest($request, $id, 'view products');
        if ($branchProduct instanceof \Illuminate\Http\JsonResponse) {
            return $branchProduct;
        }

        $prices = $branchProduct->unitPrices()->with('productUnit')->get();

        return response()->json(['data' => $prices]);
    }

    /**
     * Create a unit price for a branch product.
     */
    public function storeUnitPrice(Request $request, int $id)
    {
        $branchProduct = $this->getBranchProductForRequest($request, $id, 'manage branch products');
        if ($branchProduct instanceof \Illuminate\Http\JsonResponse) {
            return $branchProduct;
        }

        $validated = $request->validate([
            'product_unit_id' => ['required', 'integer', 'exists:product_units,id'],
            'selling_price' => ['required', 'numeric', 'min:0'],
        ]);

        if ($branchProduct->product_id !== ProductUnit::findOrFail($validated['product_unit_id'])->product_id) {
            return response()->json(['message' => 'Product unit does not belong to this product'], 422);
        }

        $unitPrice = $branchProduct->unitPrices()->create($validated);

        return response()->json(['message' => 'Unit price created', 'data' => $unitPrice->load('productUnit')], 201);
    }

    /**
     * Update a branch product unit price.
     */
    public function updateUnitPrice(Request $request, int $id, int $unitPriceId)
    {
        $branchProduct = $this->getBranchProductForRequest($request, $id, 'manage branch products');
        if ($branchProduct instanceof \Illuminate\Http\JsonResponse) {
            return $branchProduct;
        }

        $unitPrice = $branchProduct->unitPrices()->find($unitPriceId);
        if (! $unitPrice) {
            return response()->json(['message' => 'Unit price not found'], 404);
        }

        $validated = $request->validate([
            'selling_price' => ['required', 'numeric', 'min:0'],
        ]);

        $unitPrice->update($validated);

        return response()->json(['message' => 'Unit price updated', 'data' => $unitPrice->fresh()->load('productUnit')]);
    }

    /**
     * Delete a branch product unit price.
     */
    public function destroyUnitPrice(Request $request, int $id, int $unitPriceId)
    {
        $branchProduct = $this->getBranchProductForRequest($request, $id, 'manage branch products');
        if ($branchProduct instanceof \Illuminate\Http\JsonResponse) {
            return $branchProduct;
        }

        $unitPrice = $branchProduct->unitPrices()->find($unitPriceId);
        if (! $unitPrice) {
            return response()->json(['message' => 'Unit price not found'], 404);
        }

        $unitPrice->delete();

        return response()->json(['message' => 'Unit price deleted']);
    }

    /**
     * List quantity tiers for a branch product.
     */
    public function indexQuantityTiers(Request $request, int $id)
    {
        $branchProduct = $this->getBranchProductForRequest($request, $id, 'view products');
        if ($branchProduct instanceof \Illuminate\Http\JsonResponse) {
            return $branchProduct;
        }

        $tiers = $branchProduct->quantityTiers()->orderBy('min_quantity')->get();

        return response()->json(['data' => $tiers]);
    }

    /**
     * Create a quantity tier for a branch product.
     */
    public function storeQuantityTier(Request $request, int $id)
    {
        $branchProduct = $this->getBranchProductForRequest($request, $id, 'manage branch products');
        if ($branchProduct instanceof \Illuminate\Http\JsonResponse) {
            return $branchProduct;
        }

        $validated = $request->validate([
            'min_quantity' => ['required', 'integer', 'min:0'],
            'max_quantity' => ['nullable', 'integer', 'min:0'],
            'price_per_unit' => ['required', 'numeric', 'min:0'],
        ]);
        if (isset($validated['max_quantity']) && $validated['max_quantity'] < $validated['min_quantity']) {
            return response()->json(['message' => 'max_quantity must be greater than or equal to min_quantity'], 422);
        }

        $tier = $branchProduct->quantityTiers()->create($validated);

        return response()->json(['message' => 'Quantity tier created', 'data' => $tier], 201);
    }

    /**
     * Update a quantity tier.
     */
    public function updateQuantityTier(Request $request, int $id, int $tierId)
    {
        $branchProduct = $this->getBranchProductForRequest($request, $id, 'manage branch products');
        if ($branchProduct instanceof \Illuminate\Http\JsonResponse) {
            return $branchProduct;
        }

        $tier = $branchProduct->quantityTiers()->find($tierId);
        if (! $tier) {
            return response()->json(['message' => 'Quantity tier not found'], 404);
        }

        $validated = $request->validate([
            'min_quantity' => ['sometimes', 'required', 'integer', 'min:0'],
            'max_quantity' => ['nullable', 'integer', 'min:0'],
            'price_per_unit' => ['sometimes', 'required', 'numeric', 'min:0'],
        ]);

        $tier->update($validated);

        return response()->json(['message' => 'Quantity tier updated', 'data' => $tier->fresh()]);
    }

    /**
     * Delete a quantity tier.
     */
    public function destroyQuantityTier(Request $request, int $id, int $tierId)
    {
        $branchProduct = $this->getBranchProductForRequest($request, $id, 'manage branch products');
        if ($branchProduct instanceof \Illuminate\Http\JsonResponse) {
            return $branchProduct;
        }

        $tier = $branchProduct->quantityTiers()->find($tierId);
        if (! $tier) {
            return response()->json(['message' => 'Quantity tier not found'], 404);
        }

        $tier->delete();

        return response()->json(['message' => 'Quantity tier deleted']);
    }

    /**
     * Get branch product for request; return JSON error or BranchProduct.
     */
    private function getBranchProductForRequest(Request $request, int $branchProductId, string $permission): BranchProduct|\Illuminate\Http\JsonResponse
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo($permission)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $branchProduct = BranchProduct::with('branch')->find($branchProductId);
        if (! $branchProduct) {
            return response()->json(['message' => 'Branch product not found'], 404);
        }

        if ($branchProduct->branch->business_id != $businessId) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        if (! $this->userHasBranchAccess($user, $businessId, $branchProduct->branch_id)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        return $branchProduct;
    }

    /**
     * Transform branch product for response
     */
    private function transformBranchProduct(BranchProduct $branchProduct): array
    {
        return [
            'id' => $branchProduct->id,
            'branch_id' => $branchProduct->branch_id,
            'product_id' => $branchProduct->product_id,
            'product' => [
                'id' => $branchProduct->product->id,
                'name' => $branchProduct->product->name,
                'sku' => $branchProduct->product->sku,
                'barcode' => $branchProduct->product->barcode,
                'image' => $branchProduct->product->image,
                'category' => $branchProduct->product->category ? [
                    'id' => $branchProduct->product->category->id,
                    'name' => $branchProduct->product->category->name,
                ] : null,
            ],
            'pricing' => [
                'cost_price' => $branchProduct->cost_price,
                'selling_price' => $branchProduct->selling_price,
                'compare_price' => $branchProduct->compare_price,
                'discount_amount' => $branchProduct->discount_amount,
                'discount_type' => $branchProduct->discount_type,
                'tax_rate' => $branchProduct->tax_rate,
                'final_price' => $branchProduct->getFinalPrice(),
                'price_with_tax' => $branchProduct->getPriceWithTax(),
                'profit_margin' => $branchProduct->getProfitMargin(),
            ],
            'inventory' => [
                'stock_quantity' => $branchProduct->stock_quantity,
                'shelf_quantity' => $branchProduct->shelf_quantity,
                'store_quantity' => $branchProduct->store_quantity,
                'low_stock_threshold' => $branchProduct->low_stock_threshold,
                'allow_backorder' => $branchProduct->allow_backorder,
                'reorder_point' => $branchProduct->reorder_point,
                'reorder_quantity' => $branchProduct->reorder_quantity,
                'is_in_stock' => $branchProduct->isInStock(),
                'is_low_stock' => $branchProduct->isLowStock(),
                'is_out_of_stock' => $branchProduct->isOutOfStock(),
                'needs_reorder' => $branchProduct->needsReorder(),
                'shelf_needs_restocking' => $branchProduct->shelfNeedsRestocking(),
                'bin_location' => $branchProduct->bin_location,
                'shelf_location' => $branchProduct->shelf_location,
            ],
            'settings' => [
                'is_available' => $branchProduct->is_available,
                'is_featured' => $branchProduct->is_featured,
                'display_order' => $branchProduct->display_order,
            ],
            'branch_meta_data' => $branchProduct->branch_meta_data,
            'created_at' => $branchProduct->created_at,
            'updated_at' => $branchProduct->updated_at,
        ];
    }
}
