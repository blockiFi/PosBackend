# PIN Login Permission Guide

## Overview

The PIN login feature now requires users to have specific permissions:
- **`use-pin-login`**: Required to log in using a PIN code
- **`manage-pin-codes`**: Required to set, update, or remove PIN codes (for self or others)

This adds security layers to ensure only authorized users can utilize the fast PIN login method and manage PIN codes.

## How It Works

### PIN Login Permission

1. **Permission Creation**: The `use-pin-login` permission is automatically created when running the `RolePermissionSeeder`.

2. **Permission Check**: When a user attempts to log in with their PIN code:
   - The system first validates that the PIN exists
   - Then checks if the user has the `use-pin-login` permission in ANY of their associated businesses
   - If the permission is found in at least one business, login succeeds
   - If not, a 403 Forbidden response is returned

### PIN Management Permission

1. **Permission Creation**: The `manage-pin-codes` permission is automatically created when running the `RolePermissionSeeder`.

2. **Permission Check**: When a user attempts to set or remove a PIN code:
   - The system checks if the user has the `manage-pin-codes` permission in ANY of their associated businesses
   - Users must have this permission even to manage their own PIN codes
   - This allows administrators to control who can create/modify PIN codes

3. **Business Context**: Since this application uses team-based (business-scoped) permissions, both permissions must be assigned within a business context.

## Setup Instructions

### 1. Run the Permission Seeder

```bash
php artisan db:seed --class=RolePermissionSeeder
```

This creates the `use-pin-login` permission along with other application permissions.

### 2. Assign Permissions to Roles

You can assign the permissions to specific roles within a business. For example, to allow cashiers to use PIN login and managers to manage PIN codes:

```php
use Spatie\Permission\Models\Role;

// Set the business context
setPermissionsTeamId($businessId);

// Get or create the cashier role
$cashierRole = Role::where('name', 'cashier')
    ->where('guard_name', 'api')
    ->first();

// Cashiers can use PIN login
$cashierRole->givePermissionTo('use-pin-login');

// Assign the role to the user
$user->assignRole($cashierRole);

// Get or create the manager role
$managerRole = Role::where('name', 'manager')
    ->where('guard_name', 'api')
    ->first();

// Managers can use PIN login AND manage PIN codes
$managerRole->givePermissionTo(['use-pin-login', 'manage-pin-codes']);

// Assign the role to the user
$user->assignRole($managerRole);
```

### 3. Testing

The test file `tests/Feature/PinLoginTest.php` includes comprehensive tests for the permission-based PIN login and management:

**PIN Login Tests:**
- `test_user_can_login_with_valid_pin`: Verifies users WITH permission can log in
- `test_user_without_permission_cannot_login_with_pin`: Verifies users WITHOUT permission cannot log in
- `test_user_with_permission_can_login_with_pin`: Additional verification of successful permission-based login
- `test_revoking_permission_prevents_pin_login`: Verifies removing permission prevents login

**PIN Management Tests:**
- `test_authenticated_user_can_set_pin`: Verifies users with permission can set PINs
- `test_user_without_permission_cannot_set_pin`: Verifies users without permission cannot set PINs
- `test_authenticated_user_can_remove_pin`: Verifies users with permission can remove PINs
- `test_user_without_permission_cannot_remove_pin`: Verifies users without permission cannot remove PINs

Run the tests:

```bash
php artisan test --filter=PinLoginTest
```

## API Usage

### PIN Login Endpoint

**POST** `/api/pin-login`

**Request Body:**
```json
{
  "pin_code": "123456"
}
```

**Success Response** (200):
```json
{
  "message": "Login successful",
  "token": "your-auth-token-here",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  }
}
```

**Error Responses:**

- **401 Unauthorized** - Invalid PIN code
```json
{
  "message": "Invalid PIN code"
}
```

- **403 Forbidden** - User doesn't have permission
```json
{
  "message": "You do not have permission to use PIN login"
}
```

- **422 Validation Error** - Invalid PIN format
```json
{
  "message": "Validation error",
  "errors": {
    "pin_code": ["The pin code field must be 6 digits."]
  }
}
```

## Permission Management via API

Users with the `manage-roles` permission can assign/revoke the `use-pin-login` permission through the role management endpoints.

## Security Considerations

1. **Business Isolation**: The permission is scoped to businesses, ensuring proper access control
2. **Multiple Business Support**: Users with the permission in any business can use PIN login
3. **Centralized Control**: Administrators can easily grant or revoke PIN login access per role/user
4. **Validation**: PIN codes must still be 6 numeric digits and unique per user

## Implementation Details

- **Permission Name**: `use-pin-login`
- **Guard**: `api`
- **Scope**: Business-level (team-based permission)
- **Controller**: `AuthenticationController@pinLogin`
- **Seeder**: `RolePermissionSeeder`
