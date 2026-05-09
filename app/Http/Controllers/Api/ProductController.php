<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\HasBranchAccess;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use HasBranchAccess;

    /**
     * List all products for a business
     */
    public function index(Request $request)
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
        if (! $user->hasPermissionTo('view products')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = Product::where('business_id', $businessId)
            ->with(['category', 'branchProducts.branch']);

        // Filters
        if ($request->has('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->boolean('active_only', false)) {
            $query->where('is_active', true);
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($request->has('branch_id')) {
            $branchId = $request->input('branch_id');

            // Verify user has access to this branch
            if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
                return response()->json([
                    'message' => 'You do not have access to this branch',
                ], 403);
            }

            $query->whereHas('branchProducts', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            });
        }

        $perPage = $request->input('per_page', 15);
        $products = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $data = $products->map(function ($product) use ($request) {
            return $this->formatProduct($product, $request->input('branch_id'), true);
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    /**
     * Create a new product
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('create products')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:255', 'unique:products,sku'],
            'barcode' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('products', 'barcode')->where(fn ($q) => $q->where('business_id', $businessId)),
            ],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id,business_id,'.$businessId],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'string'],
            'base_cost_price' => ['nullable', 'numeric', 'min:0'],
            'base_selling_price' => ['nullable', 'numeric', 'min:0'],
            'is_taxable' => ['nullable', 'boolean'],
            'default_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'unit_of_measure' => ['nullable', 'string', 'max:50'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_unit' => ['nullable', 'string', 'max:20'],
            'stock_tracking' => ['nullable', 'in:none,simple,variant'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'is_available_online' => ['nullable', 'boolean'],
            'meta_data' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $product = Product::create([
            'uuid' => Str::uuid(),
            'business_id' => $businessId,
            'category_id' => $data['category_id'] ?? null,
            'name' => $data['name'],
            'sku' => $data['sku'],
            'barcode' => $data['barcode'] ?? null,
            'description' => $data['description'] ?? null,
            'image' => $data['image'] ?? null,
            'base_cost_price' => $data['base_cost_price'] ?? 0,
            'base_selling_price' => $data['base_selling_price'] ?? null,
            'is_taxable' => $data['is_taxable'] ?? true,
            'default_tax_rate' => $data['default_tax_rate'] ?? null,
            'unit_of_measure' => $data['unit_of_measure'] ?? null,
            'weight' => $data['weight'] ?? null,
            'weight_unit' => $data['weight_unit'] ?? null,
            'stock_tracking' => $data['stock_tracking'] ?? 'simple',
            'low_stock_threshold' => $data['low_stock_threshold'] ?? 10,
            'is_active' => $data['is_active'] ?? true,
            'is_available_online' => $data['is_available_online'] ?? false,
            'meta_data' => $data['meta_data'] ?? null,
        ]);

        return response()->json([
            'message' => 'Product created successfully',
            'data' => $this->formatProduct($product),
        ], 201);
    }

    /**
     * Show a specific product
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
        if (! $user->hasPermissionTo('view products')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $product = Product::where('id', $id)
            ->where('business_id', $businessId)
            ->with(['category', 'branchProducts.branch'])
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $branchId = $request->input('branch_id');
        if ($branchId && ! $this->userHasBranchAccess($user, $businessId, $branchId)) {
            return response()->json(['message' => 'You do not have access to this branch'], 403);
        }

        return response()->json([
            'data' => $this->formatProduct($product, $branchId, true),
        ]);
    }

    /**
     * Update a product
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('edit products')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $product = Product::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sku' => ['sometimes', 'required', 'string', 'max:255', 'unique:products,sku,'.$id],
            'barcode' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('products', 'barcode')
                    ->ignore($id)
                    ->where(fn ($q) => $q->where('business_id', $businessId)),
            ],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id,business_id,'.$businessId],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'string'],
            'base_cost_price' => ['nullable', 'numeric', 'min:0'],
            'base_selling_price' => ['nullable', 'numeric', 'min:0'],
            'is_taxable' => ['nullable', 'boolean'],
            'default_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'unit_of_measure' => ['nullable', 'string', 'max:50'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_unit' => ['nullable', 'string', 'max:20'],
            'stock_tracking' => ['nullable', 'in:none,simple,variant'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'is_available_online' => ['nullable', 'boolean'],
            'meta_data' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $product->update(array_filter($data, function ($key) {
            return in_array($key, [
                'name', 'sku', 'barcode', 'category_id', 'description', 'image',
                'base_cost_price', 'base_selling_price', 'is_taxable', 'default_tax_rate',
                'unit_of_measure', 'weight', 'weight_unit', 'stock_tracking',
                'low_stock_threshold', 'is_active', 'is_available_online', 'meta_data',
            ]);
        }, ARRAY_FILTER_USE_KEY));

        return response()->json([
            'message' => 'Product updated successfully',
            'data' => $this->formatProduct($product->fresh()),
        ]);
    }

    /**
     * Delete a product
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('delete products')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $product = Product::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully',
        ]);
    }

    /**
     * List unit definitions for a product (for tiered pricing).
     */
    public function indexUnits(Request $request, int $id)
    {
        $product = $this->getProductForBusiness($request, $id, 'view products');
        if ($product instanceof \Illuminate\Http\JsonResponse) {
            return $product;
        }

        $units = $product->units()->orderBy('display_order')->orderBy('quantity_multiplier')->get();

        return response()->json(['data' => $units]);
    }

    /**
     * Create a unit definition for a product.
     */
    public function storeUnit(Request $request, int $id)
    {
        $product = $this->getProductForBusiness($request, $id, 'manage branch products');
        if ($product instanceof \Illuminate\Http\JsonResponse) {
            return $product;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'quantity_multiplier' => ['required', 'integer', 'min:1'],
            'min_quantity' => ['nullable', 'integer', 'min:1'],
            'display_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $unit = $product->units()->create($validated);

        return response()->json(['message' => 'Unit created', 'data' => $unit], 201);
    }

    /**
     * Update a product unit.
     */
    public function updateUnit(Request $request, int $id, int $unitId)
    {
        $product = $this->getProductForBusiness($request, $id, 'manage branch products');
        if ($product instanceof \Illuminate\Http\JsonResponse) {
            return $product;
        }

        $unit = $product->units()->find($unitId);
        if (! $unit) {
            return response()->json(['message' => 'Unit not found'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'quantity_multiplier' => ['sometimes', 'required', 'integer', 'min:1'],
            'min_quantity' => ['nullable', 'integer', 'min:1'],
            'display_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $unit->update($validated);

        return response()->json(['message' => 'Unit updated', 'data' => $unit->fresh()]);
    }

    /**
     * Delete a product unit.
     */
    public function destroyUnit(Request $request, int $id, int $unitId)
    {
        $product = $this->getProductForBusiness($request, $id, 'manage branch products');
        if ($product instanceof \Illuminate\Http\JsonResponse) {
            return $product;
        }

        $unit = $product->units()->find($unitId);
        if (! $unit) {
            return response()->json(['message' => 'Unit not found'], 404);
        }

        $unit->delete();

        return response()->json(['message' => 'Unit deleted']);
    }

    /**
     * Get product for current business and permission; return JSON error response or Product.
     */
    private function getProductForBusiness(Request $request, int $productId, string $permission): Product|\Illuminate\Http\JsonResponse
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

        $product = Product::where('id', $productId)->where('business_id', $businessId)->first();

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return $product;
    }

    /**
     * Add or update product in a branch
     */
    public function addToBranch(Request $request, int $id)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage branch products')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $product = Product::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'branch_id' => ['required', 'integer', 'exists:branches,id,business_id,'.$businessId],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'compare_price' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', 'in:fixed,percentage'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
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

        // Verify user has access to this branch
        if (! $this->userHasBranchAccess($user, $businessId, $data['branch_id'])) {
            return response()->json([
                'message' => 'You do not have access to this branch',
            ], 403);
        }

        BranchProduct::updateOrCreate(
            [
                'product_id' => $product->id,
                'branch_id' => $data['branch_id'],
            ],
            [
                'cost_price' => $data['cost_price'] ?? null,
                'selling_price' => $data['selling_price'] ?? null,
                'compare_price' => $data['compare_price'] ?? null,
                'discount_amount' => $data['discount_amount'] ?? null,
                'discount_type' => $data['discount_type'] ?? null,
                'tax_rate' => $data['tax_rate'] ?? null,
                'stock_quantity' => $data['stock_quantity'] ?? 0,
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
            ]
        );

        return response()->json([
            'message' => 'Product added to branch successfully',
            'data' => $this->formatProduct($product->fresh(), $data['branch_id'], true),
        ]);
    }

    /**
     * Remove product from a branch
     */
    public function removeFromBranch(Request $request, int $id)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('manage branch products')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $product = Product::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', 'integer', 'exists:branches,id,business_id,'.$businessId],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify user has access to this branch
        if (! $this->userHasBranchAccess($user, $businessId, $request->input('branch_id'))) {
            return response()->json([
                'message' => 'You do not have access to this branch',
            ], 403);
        }

        $branchProduct = BranchProduct::where('product_id', $product->id)
            ->where('branch_id', $request->input('branch_id'))
            ->first();

        if (! $branchProduct) {
            return response()->json(['message' => 'Product not found in this branch'], 404);
        }

        $branchProduct->delete();

        return response()->json([
            'message' => 'Product removed from branch successfully',
        ]);
    }

    // /**
    //  * Update product selling price
    //  * If branch_id is provided, updates branch-specific price
    //  * Otherwise updates base selling price
    //  */
    // public function updatePrice(Request $request, int $id)
    // {
    //     $user = $request->user();
    //     $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

    //     if (! $businessId) {
    //         return response()->json([
    //             'message' => 'Business context is required',
    //         ], 400);
    //     }

    //     // Verify user has access to this business
    //     $business = $user->businesses()
    //         ->where('businesses.id', $businessId)
    //         ->wherePivot('is_active', true)
    //         ->first();

    //     if (! $business) {
    //         return response()->json(['message' => 'Business not found or access denied'], 404);
    //     }

    //     // Set permission context and check permission
    //     setPermissionsTeamId($businessId);
    //     if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('update product price')) {
    //         return response()->json(['message' => 'Unauthorized'], 403);
    //     }

    //     $product = Product::where('id', $id)
    //         ->where('business_id', $businessId)
    //         ->first();

    //     if (! $product) {
    //         return response()->json(['message' => 'Product not found'], 404);
    //     }

    //     $validator = Validator::make($request->all(), [
    //         'selling_price' => ['required', 'numeric', 'min:0'],
    //         'branch_id' => ['nullable', 'integer', 'exists:branches,id,business_id,'.$businessId],
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'message' => 'Validation error',
    //             'errors' => $validator->errors(),
    //         ], 422);
    //     }

    //     $sellingPrice = $request->input('selling_price');
    //     $branchId = $request->input('branch_id');

    //     if ($branchId) {
    //         // Verify user has access to this branch
    //         if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
    //             return response()->json([
    //                 'message' => 'You do not have access to this branch',
    //             ], 403);
    //         }

    //         // Update branch-specific price
    //         $branchProduct = BranchProduct::where('product_id', $product->id)
    //             ->where('branch_id', $branchId)
    //             ->first();

    //         if (! $branchProduct) {
    //             return response()->json(['message' => 'Product not found in this branch'], 404);
    //         }

    //         $branchProduct->selling_price = $sellingPrice;
    //         $branchProduct->save();

    //         return response()->json([
    //             'message' => 'Branch product price updated successfully',
    //             'data' => $this->formatProduct($product->fresh(), $branchId, true),
    //         ]);
    //     } else {
    //         // Update base selling price
    //         $product->base_selling_price = $sellingPrice;
    //         $product->save();

    //         return response()->json([
    //             'message' => 'Product base price updated successfully',
    //             'data' => $this->formatProduct($product->fresh(), null, true),
    //         ]);
    //     }
    // }

    // /**
    //  * Update product base selling price only.
    //  * Requires permission: update base selling price
    //  */
    // public function updateBaseSellingPrice(Request $request, int $id)
    // {
    //     $user = $request->user();
    //     $businessId = $request->header('X-Business-Id') ?? $request->input('business_id') ?? $request->input('current_business_id');

    //     if (! $businessId) {
    //         return response()->json([
    //             'message' => 'Business context is required',
    //         ], 400);
    //     }

    //     $business = $user->businesses()
    //         ->where('businesses.id', $businessId)
    //         ->wherePivot('is_active', true)
    //         ->first();

    //     if (! $business) {
    //         return response()->json(['message' => 'Business not found or access denied'], 404);
    //     }

    //     setPermissionsTeamId($businessId);
    //     if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('update base selling price')) {
    //         return response()->json(['message' => 'Unauthorized'], 403);
    //     }

    //     $product = Product::where('id', $id)
    //         ->where('business_id', $businessId)
    //         ->first();

    //     if (! $product) {
    //         return response()->json(['message' => 'Product not found'], 404);
    //     }

    //     $validator = Validator::make($request->all(), [
    //         'base_selling_price' => ['required', 'numeric', 'min:0'],
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'message' => 'Validation error',
    //             'errors' => $validator->errors(),
    //         ], 422);
    //     }

    //     $product->base_selling_price = $request->input('base_selling_price');
    //     $product->save();

    //     return response()->json([
    //         'message' => 'Base selling price updated successfully',
    //         'data' => $this->formatProduct($product->fresh(), null, true),
    //     ]);
    // }

    /**
     * Get products for a specific branch
     * Only permitted users with branch access can view products
     */
    public function getProductsByBranch(Request $request, int $branchId)
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

        // Set permission context and check 'view products' permission
        setPermissionsTeamId($businessId);
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view products')) {
            return response()->json([
                'message' => 'You do not have permission to view products',
            ], 403);
        }

        // Verify the branch exists and belongs to the business
        $branch = Branch::where('id', $branchId)
            ->where('business_id', $businessId)
            ->first();

        if (! $branch) {
            return response()->json([
                'message' => 'Branch not found or does not belong to this business',
            ], 404);
        }

        // Check if user has access to this specific branch
        if (! $this->userHasBranchAccess($user, $businessId, $branchId)) {
            return response()->json([
                'message' => 'You do not have access to this branch',
            ], 403);
        }

        // Get products for this branch
        $query = Product::where('business_id', $businessId)
            ->whereHas('branchProducts', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            })
            ->with(['category', 'branchProducts' => function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->with('branch');
            }]);

        // Apply filters
        if ($request->has('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->boolean('active_only', false)) {
            $query->where('is_active', true);
        }

        if ($request->boolean('available_only', false)) {
            $query->whereHas('branchProducts', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                    ->where('is_available', true);
            });
        }

        if ($request->boolean('in_stock_only', false)) {
            $query->whereHas('branchProducts', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                    ->where('stock_quantity', '>', 0);
            });
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        // Support starting from a specific ID
        if ($request->has('start_id')) {
            $query->where('products.id', '>=', $request->input('start_id'));
        }

        // Order: optional sort by this branch's selling_price (branch_products.selling_price)
        $sort = $request->input('sort');
        $priceAsc = in_array($sort, ['selling_price_asc', 'lowest_price', 'price_asc'], true);
        $priceDesc = in_array($sort, ['selling_price_desc', 'highest_price', 'price_desc'], true);

        if ($priceAsc) {
            $query->join('branch_products', function ($join) use ($branchId) {
                $join->on('products.id', '=', 'branch_products.product_id')
                    ->where('branch_products.branch_id', '=', $branchId)
                    ->whereNull('branch_products.deleted_at');
            })
                ->select('products.*')
                ->orderBy('branch_products.selling_price', 'asc')
                ->orderBy('products.id', 'asc');
        } elseif ($priceDesc) {
            $query->join('branch_products', function ($join) use ($branchId) {
                $join->on('products.id', '=', 'branch_products.product_id')
                    ->where('branch_products.branch_id', '=', $branchId)
                    ->whereNull('branch_products.deleted_at');
            })
                ->select('products.*')
                ->orderBy('branch_products.selling_price', 'desc')
                ->orderBy('products.id', 'asc');
        } else {
            $query->orderBy('products.id', 'asc');
        }

        // Check if unpaginated response is requested
        $paginated = $request->boolean('paginated', true);

        if (! $paginated) {
            // Return all results without pagination
            $products = $query->get();

            $data = $products->map(function ($product) use ($branchId) {
                return $this->formatProduct($product, $branchId);
            });

            return response()->json([
                'data' => $data,
                'branch' => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'code' => $branch->code,
                ],
                'meta' => [
                    'total' => $products->count(),
                    'paginated' => false,
                ],
            ]);
        }

        // Paginated response
        $perPage = $request->input('per_page', 15);
        $products = $query->paginate($perPage);

        $data = $products->map(function ($product) use ($branchId) {
            return $this->formatProduct($product, $branchId);
        });

        return response()->json([
            'data' => $data,
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
            ],
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'paginated' => true,
            ],
        ]);
    }

    /**
     * Format product for response
     */
    private function formatProduct(Product $product, ?int $branchId = null, bool $detailed = false): array
    {
        $data = [
            'id' => $product->id,
            'uuid' => $product->uuid,
            'business_id' => $product->business_id,
            'category_id' => $product->category_id,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'description' => $product->description,
            'image' => $product->image,
            'base_cost_price' => $product->base_cost_price,
            'base_selling_price' => $product->base_selling_price,
            'is_taxable' => $product->is_taxable,
            'default_tax_rate' => $product->default_tax_rate,
            'unit_of_measure' => $product->unit_of_measure,
            'weight' => $product->weight,
            'weight_unit' => $product->weight_unit,
            'stock_tracking' => $product->stock_tracking,
            'low_stock_threshold' => $product->low_stock_threshold,
            'is_active' => $product->is_active,
            'is_available_online' => $product->is_available_online,
            'meta_data' => $product->meta_data,
            'created_at' => $product->created_at,
            'updated_at' => $product->updated_at,
        ];

        if ($detailed) {
            $data['total_stock'] = $product->getTotalStock();
        }

        // Add branch-specific data if branch_id is provided
        if ($branchId) {
            // Always fetch fresh branch product data to avoid stale cache
            $branchProduct = BranchProduct::where('product_id', $product->id)
                ->where('branch_id', $branchId)
                ->first();

            if ($branchProduct) {
                $data['branch_data'] = [
                    'branch_product_id' => $branchProduct->id,
                    'branch_id' => $branchProduct->branch_id,
                    'cost_price' => $branchProduct->cost_price,
                    'selling_price' => $branchProduct->selling_price,
                    'effective_cost_price' => $branchProduct->getEffectiveCostPrice(),
                    'effective_selling_price' => $branchProduct->getEffectiveSellingPrice(),
                    'compare_price' => $branchProduct->compare_price,
                    'discount_amount' => $branchProduct->discount_amount,
                    'discount_type' => $branchProduct->discount_type,
                    'final_price' => $branchProduct->getFinalPrice(),
                    'tax_rate' => $branchProduct->tax_rate,
                    'effective_tax_rate' => $branchProduct->getEffectiveTaxRate(),
                    'price_with_tax' => $branchProduct->getPriceWithTax(),
                    'stock_quantity' => $branchProduct->stock_quantity,
                    'shelf_quantity' => $branchProduct->shelf_quantity,
                    'store_quantity' => $branchProduct->store_quantity,
                    'low_stock_threshold' => $branchProduct->low_stock_threshold,
                    'allow_backorder' => $branchProduct->allow_backorder,
                    'reorder_point' => $branchProduct->reorder_point,
                    'reorder_quantity' => $branchProduct->reorder_quantity,
                    'is_available' => $branchProduct->is_available,
                    'is_featured' => $branchProduct->is_featured,
                    'is_in_stock' => $branchProduct->isInStock(),
                    'is_low_stock' => $branchProduct->isLowStock(),
                    'is_out_of_stock' => $branchProduct->isOutOfStock(),
                    'needs_reorder' => $branchProduct->needsReorder(),
                    'bin_location' => $branchProduct->bin_location,
                    'shelf_location' => $branchProduct->shelf_location,
                    'branch_meta_data' => $branchProduct->branch_meta_data,
                ];
            }
        } elseif ($detailed) {
            // Refresh branch products relationship to get latest data
            $product->load('branchProducts.branch');

            // Include all branches data
            $data['branches'] = $product->branchProducts->map(function ($branchProduct) {
                return [
                    'branch_id' => $branchProduct->branch_id,
                    'branch_name' => $branchProduct->branch->name ?? null,
                    'cost_price' => $branchProduct->cost_price,
                    'selling_price' => $branchProduct->selling_price,
                    'effective_selling_price' => $branchProduct->getEffectiveSellingPrice(),
                    'stock_quantity' => $branchProduct->stock_quantity,
                    'shelf_quantity' => $branchProduct->shelf_quantity,
                    'store_quantity' => $branchProduct->store_quantity,
                    'is_available' => $branchProduct->is_available,
                    'is_in_stock' => $branchProduct->isInStock(),
                    'is_low_stock' => $branchProduct->isLowStock(),
                ];
            });
        }

        return $data;
    }
}
