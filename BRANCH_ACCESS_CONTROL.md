# Branch-Level Access Control

## Overview

The POS system now implements comprehensive branch-level access control. Users can be assigned roles that are specific to certain branches, allowing granular permission management across multi-branch businesses.

## How It Works

### 1. Role Assignment Levels

Users can have roles assigned at two levels:

- **Business-wide roles**: Roles with no `branch_id` - grants access to all branches in the business
- **Branch-specific roles**: Roles with a specific `branch_id` - grants access only to that branch

### 2. Database Structure

The `model_has_roles` table includes a `branch_id` column that determines the scope of role assignment:

```sql
CREATE TABLE model_has_roles (
    role_id BIGINT UNSIGNED NOT NULL,
    model_type VARCHAR(255) NOT NULL,
    model_id BIGINT UNSIGNED NOT NULL,
    business_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,  -- NULL = business-wide, value = branch-specific
    ...
);
```

### 3. Helper Methods (User Model)

Three helper methods are available for branch access verification:

#### `hasPermissionInBranch(string $permission, int $businessId, ?int $branchId)`
Checks if a user has a specific permission for a branch.

```php
if ($user->hasPermissionInBranch('edit products', $businessId, $branchId)) {
    // User can edit products in this branch
}
```

#### `getPermissionsInBranch(int $businessId, ?int $branchId)`
Gets all permissions for a user in a specific branch.

```php
$permissions = $user->getPermissionsInBranch($businessId, $branchId);
// Returns: Collection(['view products', 'edit products', ...])
```

#### `getBranchesInBusiness(int $businessId, ?string $roleName = null)`
Gets all branches where the user has access (or a specific role).

```php
$branches = $user->getBranchesInBusiness($businessId);
// Returns: Collection([1, 3, 5]) - branch IDs
```

### 4. Controller Implementation

All controllers with branch-related operations now verify branch access using a helper method:

#### `userHasBranchAccess($user, int $businessId, int $branchId)`

This method:
1. Gets all branches the user has access to
2. If user has no branch-specific assignments, checks for business-wide roles
3. Returns `true` if user has access, `false` otherwise

**Logic:**
- Users with business-wide roles (no branch_id) → access to ALL branches
- Users with branch-specific roles → access ONLY to assigned branches

## Controllers with Branch Access Control

### 1. ProductController

Branch access is verified for:

- **Filtering by branch** (`index` method):
  ```php
  GET /api/products?branch_id=1
  ```
  Returns 403 if user doesn't have access to the specified branch.

- **Adding product to branch** (`addToBranch` method):
  ```php
  POST /api/products/{id}/branches
  ```
  Verifies user can manage the target branch.

- **Removing product from branch** (`removeFromBranch` method):
  ```php
  DELETE /api/products/{id}/branches
  ```
  Verifies user can manage the target branch.

### 2. BranchController

Branch access is enforced for:

- **Listing branches** (`index` method):
  ```php
  GET /api/branches
  ```
  Users with branch-specific roles only see their assigned branches.
  Users with business-wide roles see all branches.

- **Viewing branch details** (`show` method):
  ```php
  GET /api/branches/{id}
  ```
  Returns 403 if user doesn't have access to the branch.

**Note:** Creating, updating, and deleting branches requires business ownership (existing restriction).

## Usage Examples

### Example 1: Branch Manager

A user with "Branch Manager" role assigned to Branch ID 1:

```php
// Assign role to specific branch
DB::table('model_has_roles')->insert([
    'role_id' => $branchManagerRole->id,
    'model_type' => User::class,
    'model_id' => $user->id,
    'business_id' => 1,
    'branch_id' => 1,  // Only Branch 1
]);
```

**Allowed:**
- ✅ View/edit products in Branch 1
- ✅ Manage inventory in Branch 1
- ✅ View Branch 1 details

**Denied:**
- ❌ Access Branch 2 or other branches
- ❌ View all branches in business

### Example 2: Multi-Branch Manager

A user managing multiple branches:

```php
// Assign to Branch 1
DB::table('model_has_roles')->insert([
    'role_id' => $branchManagerRole->id,
    'model_type' => User::class,
    'model_id' => $user->id,
    'business_id' => 1,
    'branch_id' => 1,
]);

// Also assign to Branch 3
DB::table('model_has_roles')->insert([
    'role_id' => $branchManagerRole->id,
    'model_type' => User::class,
    'model_id' => $user->id,
    'business_id' => 1,
    'branch_id' => 3,
]);
```

**Allowed:**
- ✅ Access Branch 1 and Branch 3
- ✅ Manage products in both branches

**Denied:**
- ❌ Access Branch 2 or other branches

### Example 3: Regional Manager (Business-wide)

A user with business-wide access:

```php
// Assign role without branch_id
DB::table('model_has_roles')->insert([
    'role_id' => $regionalManagerRole->id,
    'model_type' => User::class,
    'model_id' => $user->id,
    'business_id' => 1,
    'branch_id' => null,  // NULL = all branches
]);
```

**Allowed:**
- ✅ Access ALL branches in the business
- ✅ View all branches
- ✅ Manage products in any branch

## Testing

Comprehensive tests verify branch access control:

### ProductRoutesTest

- `test_user_cannot_add_product_to_branch_without_access`
- `test_user_can_add_product_to_accessible_branch`
- `test_user_cannot_remove_product_from_branch_without_access`
- `test_user_cannot_filter_products_by_inaccessible_branch`
- `test_business_wide_role_can_access_all_branches`

### Test Results

```
Tests:    112 passed (360 assertions)
```

All existing tests continue to pass, ensuring backward compatibility.

## API Responses

### Success (200/201)
User has appropriate access.

### Forbidden (403)
```json
{
    "message": "You do not have access to this branch"
}
```

### Not Found (404)
Branch doesn't exist or belongs to different business.

## Security Considerations

1. **Defense in Depth**: Branch access is verified even if business access is confirmed
2. **Explicit Deny**: Users without branch assignments cannot access any branch operations
3. **Business Context**: All checks verify business ownership first
4. **Consistent Enforcement**: Same logic applied across all controllers
5. **Granular Permissions**: Combines role permissions with branch access

## Migration from Previous System

No database migration needed - the `branch_id` column already exists in `model_has_roles`.

**Existing roles continue to work:**
- Roles without `branch_id` grant business-wide access (backward compatible)
- New branch-specific roles can be created as needed

## Best Practices

1. **Use business-wide roles for** admins, owners, regional managers
2. **Use branch-specific roles for** store managers, cashiers, inventory clerks
3. **Always verify branch access** when implementing new branch-related features
4. **Test both scenarios**: branch-specific and business-wide access
5. **Provide clear error messages** when access is denied

## Future Enhancements

Potential improvements:

- Branch transfer functionality (move user assignments between branches)
- Temporary branch access (time-limited assignments)
- Branch groups (assign role to multiple branches at once)
- Branch access audit logging
- UI for managing branch assignments
