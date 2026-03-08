# Include BranchProducts with product in getEntityChanges (with optional branch_id scope)

## Goal

- Add a `branch_products` case to `getEntityChanges` in [SyncController.php](app/Http/Controllers/SyncController.php) so pull responses include BranchProduct changes with each record's `product` relation eager-loaded.
- Accept an optional `branch_id` on the pull request; when provided, return only BranchProducts for that branch (and scope to it in `getEntityChanges`).

## Current behavior

- Pull (around 246) uses `entities ?? ['products', 'customers', 'branch_products']` and calls `getEntityChanges($entity, $businessId, $lastSyncAt, $deviceId, $limit)`.
- `getEntityChanges()` (517–559) only has cases for `products` and `customers`. No `branch_products` case and no `branch_id` support.

## Changes

### 1. Pull request: accept and validate `branch_id`

In the **pull** method of [SyncController.php](app/Http/Controllers/SyncController.php):

- Add to the validator rules: `'branch_id' => 'nullable|exists:branches,id'`.
- After resolving `$businessId`, if `$request->branch_id` is present, verify the branch belongs to the business and the user has access, e.g.:
  - Ensure the branch’s `business_id` equals `$businessId`.
  - Call `$this->userHasBranchAccess($user, $businessId, $request->branch_id)` and return 403 if false.
- When calling `getEntityChanges`, pass the optional branch id, e.g.  
  `$this->getEntityChanges($entity, $businessId, $lastSyncAt, $deviceId, $limit, $request->branch_id)`.

### 2. getEntityChanges: new signature and `branch_products` case

In **getEntityChanges** in [SyncController.php](app/Http/Controllers/SyncController.php):

- Add an optional 6th parameter: `$branchId = null`.
- Add a **`case 'branch_products':`** that:
  - **Scope:**
    - If `$branchId` is provided: query BranchProducts with `where('branch_id', $branchId)`. Optionally ensure the branch belongs to the business (e.g. `whereHas('branch', fn ($q) => $q->where('business_id', $businessId))`) to avoid leaking data.
    - If `$branchId` is null: scope by business via `whereHas('branch', fn ($q) => $q->where('business_id', $businessId))`.
  - **Created:** `created_at > $since`, `limit($limit)`.
  - **Updated:** `updated_at > $since`, `created_at <= $since`, `limit($limit)`.
  - **Eager load:** Use `->with('product')` on both queries so each BranchProduct in the response includes its `product`.
  - **Deleted:** leave as empty array (same as products/customers).

No changes to bootstrap or push; only the pull endpoint and `getEntityChanges` are affected.

## Result

- Pull requests that include `branch_products` in `entities` receive `changes.branch_products.created` and `changes.branch_products.updated` with BranchProduct objects and nested `product`.
- When `branch_id` is sent on the pull request (and valid for the user/business), only BranchProducts for that branch are returned; otherwise BranchProducts for all branches of the business are returned.
