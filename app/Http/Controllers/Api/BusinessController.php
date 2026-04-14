<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class BusinessController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $businesses = $user->businesses()
            ->with('branches')
            ->withCount('products')
            ->wherePivot('is_active', true)
            ->get()
            ->map(function (Business $business) {
                return [
                    'id' => $business->id,
                    'uuid' => $business->uuid,
                    'name' => $business->name,
                    'slug' => $business->slug,
                    'legal_name' => $business->legal_name,
                    'email' => $business->email,
                    'phone' => $business->phone,
                    'address' => $business->address,
                    'city' => $business->city,
                    'tax_registration_number' => $business->tax_registration_number,
                    'currency' => $business->currency,
                    'time_zone' => $business->time_zone,
                    'owner_id' => $business->owner_id,
                    'branch_id' => $business->pivot->branch_id ?? null,
                    'is_active' => $business->is_active,
                    'products_count' => (int) ($business->products_count ?? 0),
                    'created_at' => $business->created_at?->toIso8601String(),
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
            'slug' => $data['slug'] ?? Str::slug($data['name']).'-'.Str::random(6),
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'country' => $data['country'] ?? null,
            // Default to Naira for new installs unless explicitly set.
            'currency' => $data['currency'] ?? 'NGN',
            'time_zone' => $data['time_zone'] ?? null,
            'tax_registration_number' => $data['tax_registration_number'] ?? null,
            'default_tax_rate' => $data['default_tax_rate'] ?? 0,
            'settings' => array_merge(
                ['currency_symbol' => '₦'],
                is_array($data['settings'] ?? null) ? ($data['settings'] ?? []) : []
            ),
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

        // Create default roles for the business
        $roles = $this->createDefaultRoles($business);

        // Assign the Owner role to the business creator
        if (isset($roles['Owner'])) {
            DB::table('model_has_roles')->insert([
                'role_id' => $roles['Owner']->id,
                'model_type' => User::class,
                'model_id' => $user->id,
                'business_id' => $business->id,
            ]);
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
        }

        return response()->json([
            'message' => 'Business created',
            'data' => [
                'business' => $business->fresh(),
                'branch' => $branch,
                'roles' => collect($roles)->map(fn ($role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => $role->permissions->pluck('name'),
                ]),
            ],
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        $user = $request->user();

        $business = $user->businesses()
            ->with(['branches'])
            ->withCount('products')
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
                'legal_name' => $business->legal_name,
                'slug' => $business->slug,
                'email' => $business->email,
                'phone' => $business->phone,
                'address' => $business->address,
                'city' => $business->city,
                'state' => $business->state,
                'postal_code' => $business->postal_code,
                'country' => $business->country,
                'currency' => $business->currency,
                'time_zone' => $business->time_zone,
                'tax_registration_number' => $business->tax_registration_number,
                'default_tax_rate' => $business->default_tax_rate,
                'settings' => $business->settings,
                'owner_id' => $business->owner_id,
                'is_active' => $business->is_active,
                'products_count' => (int) ($business->products_count ?? 0),
                'created_at' => $business->created_at?->toIso8601String(),
                'updated_at' => $business->updated_at?->toIso8601String(),
                'role' => $business->pivot->role ?? null,
                'branch_id' => $business->pivot->branch_id ?? null,
                'branches' => $business->branches->map(function (Branch $branch) {
                    return [
                        'id' => $branch->id,
                        'uuid' => $branch->uuid,
                        'name' => $branch->name,
                        'code' => $branch->code,
                        'email' => $branch->email,
                        'phone' => $branch->phone,
                        'address' => $branch->address,
                        'city' => $branch->city,
                        'state' => $branch->state,
                        'is_main' => $branch->is_main,
                        'is_active' => $branch->is_active,
                    ];
                }),
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
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'unique:businesses,slug,'.$business->id],
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

    /**
     * Create the 4 default roles (Owner, Manager, Supervisor, Cashier) for a new business.
     *
     * @return array<string, Role>
     */
    private function createDefaultRoles(Business $business): array
    {
        app()[PermissionRegistrar::class]->setPermissionsTeamId($business->id);

        $roleTemplates = [
            'Owner' => Permission::where('guard_name', 'api')->pluck('name')->toArray(),
            'Manager' => [
                'view categories',
                'create categories',
                'edit categories',
                'view products',
                'create products',
                'edit products',
                'manage branch products',
                'update product price',
                'update base selling price',
                'view inventory',
                'manage inventory',
                'view batches',
                'manage batches',
                'view sales',
                'create sales',
                'manage sales',
                'view customers',
                'create customers',
                'edit customers',
                'view payment methods',
                'manage payment methods',
                'view user shift',
                'view all shifts',
                'create shift',
                'close shift',
                'manage shifts',
                'request quick sale',
                'approve quick sale',
                'request refund',
                'approve refund',
                'adjust inventory',
                'view-branches',
                'manage-branches',
                'manage server sync',
                'sync data',
                'create-sales',
                'view-sales',
                'edit-sales',
                'refund-sales',
                'create-products',
                'view-products',
                'edit-products',
                'set branch product selling price',
                'manage-inventory',
                'view-inventory',
                'adjust-inventory',
                'create-customers',
                'view-customers',
                'edit-customers',
                'view-reports',
                'export-reports',
                'manage-users',
                'manage-settings',
                'manage-roles',
                'open-register',
                'close-register',
                'manage-cash',
                'manage-pin-codes',
                'request stock transfer',
                'approve stock transfer',
                'accept stock transfer',
                'write off stock',
                'view analytics',
                'view financial reports',
                'view branch analytics',
                'export analytics',
                'request shelf store move',
                'approve shelf store move',
                'override sale price',
                'set user password',
            ],
            'Supervisor' => [
                'view products', 'create products', 'edit products',
                'manage branch products', 'manage inventory', 'view inventory',
                'view categories', 'create categories', 'edit categories',
                'view sales', 'create sales', 'view customers', 'create customers', 'edit customers',
                'view analytics', 'view reports',
                'manage shifts', 'view all shifts', 'view user shift', 'close shift',
                'request refund', 'request quick sale', 'manage transfers', 'use-pin-login',
            ],
            'Cashier' => [
                'view products', 'view inventory', 'view categories',
                'view sales', 'create sales', 'view customers', 'create customers',
                'view user shift', 'close shift',
                'request refund', 'request quick sale', 'use-pin-login',
            ],
        ];

        $roles = [];

        foreach ($roleTemplates as $roleName => $permissionNames) {
            foreach ($permissionNames as $permissionName) {
                Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'api',
                ]);
            }

            $role = Role::query()->create([
                'name' => $roleName,
                'guard_name' => 'api',
                'business_id' => $business->id,
            ]);

            $permissions = Permission::whereIn('name', $permissionNames)
                ->where('guard_name', 'api')
                ->get();

            $role->syncPermissions($permissions);

            $roles[$roleName] = $role;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $roles;
    }
}
