<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProductCategoryController extends Controller
{
    /**
     * List all categories for a business (with optional hierarchical structure)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view categories', 'api')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Get query parameters
        $flat = $request->boolean('flat', false);
        $parentId = $request->input('parent_id');
        $activeOnly = $request->boolean('active_only', false);
        $withProducts = $request->boolean('with_products', false);

        $query = ProductCategory::where('business_id', $businessId);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        if ($flat) {
            // Return flat list
            if ($parentId !== null) {
                $query->where('parent_id', $parentId);
            }

            $categories = $query->orderBy('sort_order')->orderBy('name')->get();

            $data = $categories->map(function ($category) use ($withProducts) {
                return $this->formatCategory($category, $withProducts);
            });
        } else {
            // Return hierarchical structure (root categories with children)
            $categories = $query->whereNull('parent_id')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            $data = $categories->map(function ($category) use ($withProducts) {
                return $this->formatCategoryWithChildren($category, $withProducts);
            });
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Create a new category
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('create categories')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:product_categories,slug,NULL,id,business_id,'.$businessId],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:product_categories,id,business_id,'.$businessId],
            'image' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'meta_data' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Auto-generate slug if not provided
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);

            // Ensure unique slug
            $baseSlug = $data['slug'];
            $counter = 1;
            while (ProductCategory::where('business_id', $businessId)
                ->where('slug', $data['slug'])
                ->exists()) {
                $data['slug'] = $baseSlug.'-'.$counter;
                $counter++;
            }
        }

        // Validate parent doesn't create circular reference
        if (! empty($data['parent_id'])) {
            $parent = ProductCategory::find($data['parent_id']);
            if (! $parent || $parent->business_id != $businessId) {
                return response()->json([
                    'message' => 'Invalid parent category',
                ], 422);
            }
        }

        $category = ProductCategory::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'image' => $data['image'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
            'meta_data' => $data['meta_data'] ?? null,
        ]);

        return response()->json([
            'message' => 'Category created successfully',
            'data' => $this->formatCategory($category, false),
        ], 201);
    }

    /**
     * Show a specific category
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view categories', 'api')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $category = ProductCategory::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        $withProducts = $request->boolean('with_products', false);
        $withChildren = $request->boolean('with_children', false);

        if ($withChildren) {
            $data = $this->formatCategoryWithChildren($category, $withProducts);
        } else {
            $data = $this->formatCategory($category, $withProducts);
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Update a category
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('edit categories')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $category = ProductCategory::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'unique:product_categories,slug,'.$id.',id,business_id,'.$businessId],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:product_categories,id,business_id,'.$businessId],
            'image' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'meta_data' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Validate parent doesn't create circular reference
        if (isset($data['parent_id'])) {
            if ($data['parent_id'] == $id) {
                return response()->json([
                    'message' => 'A category cannot be its own parent',
                ], 422);
            }

            if ($data['parent_id']) {
                $parent = ProductCategory::find($data['parent_id']);
                if (! $parent || $parent->business_id != $businessId) {
                    return response()->json([
                        'message' => 'Invalid parent category',
                    ], 422);
                }

                // Check if parent is a descendant of current category
                $descendants = $category->descendants()->pluck('id')->toArray();
                if (in_array($data['parent_id'], $descendants)) {
                    return response()->json([
                        'message' => 'Cannot set a descendant as parent (circular reference)',
                    ], 422);
                }
            }
        }

        // Auto-generate slug if name changed and slug not provided
        if (isset($data['name']) && ! isset($data['slug'])) {
            $newSlug = Str::slug($data['name']);
            if ($newSlug !== $category->slug) {
                $baseSlug = $newSlug;
                $counter = 1;
                while (ProductCategory::where('business_id', $businessId)
                    ->where('slug', $newSlug)
                    ->where('id', '!=', $id)
                    ->exists()) {
                    $newSlug = $baseSlug.'-'.$counter;
                    $counter++;
                }
                $data['slug'] = $newSlug;
            }
        }

        $category->update(array_filter($data, function ($key) {
            return in_array($key, [
                'name', 'slug', 'description', 'parent_id',
                'image', 'sort_order', 'is_active', 'meta_data',
            ]);
        }, ARRAY_FILTER_USE_KEY));

        return response()->json([
            'message' => 'Category updated successfully',
            'data' => $this->formatCategory($category->fresh(), false),
        ]);
    }

    /**
     * Delete a category
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('delete categories')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $category = ProductCategory::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        // Check if category has products
        $productsCount = $category->products()->count();
        if ($productsCount > 0) {
            return response()->json([
                'message' => 'Cannot delete category with products. Please reassign or delete products first.',
                'products_count' => $productsCount,
            ], 422);
        }

        // Check if category has children
        $childrenCount = $category->children()->count();
        if ($childrenCount > 0) {
            return response()->json([
                'message' => 'Cannot delete category with subcategories. Please delete subcategories first.',
                'children_count' => $childrenCount,
            ], 422);
        }

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully',
        ]);
    }

    /**
     * Get breadcrumb trail for a category
     */
    public function breadcrumb(Request $request, int $id)
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
        if ($business->owner_id !== $user->id && ! $user->hasPermissionTo('view categories', 'api')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $category = ProductCategory::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        $breadcrumb = $category->getBreadcrumb();

        return response()->json(['data' => $breadcrumb]);
    }

    /**
     * Format category for response
     */
    private function formatCategory(ProductCategory $category, bool $withProducts = false): array
    {
        $data = [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'parent_id' => $category->parent_id,
            'image' => $category->image,
            'sort_order' => $category->sort_order,
            'is_active' => $category->is_active,
            'depth' => $category->getDepth(),
            'has_children' => $category->hasChildren(),
            'products_count' => $category->products()->count(),
            'meta_data' => $category->meta_data,
            'created_at' => $category->created_at,
            'updated_at' => $category->updated_at,
        ];

        if ($withProducts) {
            $data['products'] = $category->products()
                ->where('is_active', true)
                ->get()
                ->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'uuid' => $product->uuid,
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'base_selling_price' => $product->base_selling_price,
                        'image' => $product->image,
                        'is_active' => $product->is_active,
                    ];
                });
        }

        return $data;
    }

    /**
     * Format category with children recursively
     */
    private function formatCategoryWithChildren(ProductCategory $category, bool $withProducts = false): array
    {
        $data = $this->formatCategory($category, $withProducts);

        $children = $category->children()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $data['children'] = $children->map(function ($child) use ($withProducts) {
            return $this->formatCategoryWithChildren($child, $withProducts);
        });

        return $data;
    }
}
