# POS Backend - Complete Postman Collection Documentation

This document provides detailed information about all API endpoints, validation rules, request structures, and response formats for building a comprehensive Postman collection.

**Collection structure:** The generated `POS_Backend_API_Complete.postman_collection.json` includes a description on every folder (e.g. Authentication, Businesses, Business Users) summarizing what the group covers, and each request has a detailed description (method, purpose, query/body, permissions, and X-Business-Id where required). Use the collection's built-in descriptions in Postman for quick reference.

## Table of Contents
1. [Global Headers](#global-headers)
2. [Authentication](#authentication)
3. [Business Management](#business-management)
4. [Branch Management](#branch-management)
5. [User Management](#user-management)
6. [Role & Permission Management](#role--permission-management)
7. [Product Categories](#product-categories)
8. [Products](#products)
9. [Branch Products](#branch-products)
10. [Inventory](#inventory)
11. [Customers](#customers)
12. [Payment Methods](#payment-methods)
13. [Sales](#sales)
14. [Sales Shifts](#sales-shifts)
15. [Batches](#batches)
16. [Analytics](#analytics)
17. [Stock Transfer Requests](#stock-transfer-requests)
17b. [Shelf/Store Move Requests](#shelfstore-move-requests)
18. [Stock Write-offs](#stock-write-offs)
19. [Refund Requests](#refund-requests)
20. [Quick Sales](#quick-sales)
21. [Sync Operations](#sync-operations)
22. [Server Sync](#server-sync)

---

## Global Headers

All authenticated requests should include:

```
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json
X-Business-Id: {business_id}        (optional, for business context)
X-Device-Id: {device_id}            (optional, for device tracking)
```

---

## 1. Authentication

### 1.1 Register User
**Endpoint:** `POST /api/register`

**Request Body:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

**Validation Rules:**
- `name`: required, string, max:255
- `email`: required, string, email, max:255, unique:users,email
- `password`: required, string, min:8, confirmed

**Response (201):**
```json
{
  "message": "Registration successful",
  "token": "1|abcdefgh...",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  }
}
```

### 1.2 Login
**Endpoint:** `POST /api/login`

**Request Body:**
```json
{
  "email": "john@example.com",
  "password": "password123"
}
```

**Validation Rules:**
- `email`: required, string, email
- `password`: required, string

**Response (200):**
```json
{
  "message": "Login successful",
  "token": "1|abcdefgh...",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  }
}
```

### 1.3 PIN Login
**Endpoint:** `POST /api/pin-login`

**Request Body:**
```json
{
  "pin_code": "123456"
}
```

**Validation Rules:**
- `pin_code`: required, string, size:6, regex:/^[0-9]{6}$/

**Response (200):**
```json
{
  "message": "Login successful",
  "token": "1|abcdefgh...",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  }
}
```

**Notes:**
- User must have 'use-pin-login' permission in at least one business

### 1.4 Set PIN
**Endpoint:** `POST /api/set-pin`

**Headers:** `Authorization: Bearer {token}`

**Request Body:**
```json
{
  "user_id": 1,
  "pin_code": "123456",
  "password": "password123"
}
```

**Validation Rules:**
- `user_id`: required, integer, exists:users,id
- `pin_code`: required, string, size:6, regex:/^[0-9]{6}$/
- `password`: required (when setting own PIN), string

**Response (200):**
```json
{
  "message": "PIN code set successfully"
}
```

**Notes:**
- Password required only when setting your own PIN
- Requires 'manage-pin-codes' permission to set others' PINs
- PIN must be unique

### 1.5 Remove PIN
**Endpoint:** `POST /api/remove-pin`

**Headers:** `Authorization: Bearer {token}`

**Request Body:**
```json
{
  "user_id": 1,
  "password": "password123"
}
```

**Validation Rules:**
- `user_id`: required, integer, exists:users,id
- `password`: required, string

**Response (200):**
```json
{
  "message": "PIN code removed successfully"
}
```

**Notes:**
- Requires 'manage-pin-codes' permission
- Password verification required

---

## 2. Business Management

### 2.1 List Businesses
**Endpoint:** `GET /api/businesses`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:** None

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "name": "My Store",
      "slug": "my-store-abc123",
      "currency": "USD",
      "time_zone": "UTC",
      "branch_id": 1,
      "is_active": true,
      "branches": [
        {
          "id": 1,
          "uuid": "550e8400-e29b-41d4-a716-446655440001",
          "name": "Main Branch",
          "code": "MAIN",
          "is_main": true,
          "is_active": true
        }
      ]
    }
  ]
}
```

### 2.2 Create Business
**Endpoint:** `POST /api/businesses`

**Headers:** `Authorization: Bearer {token}`

**Request Body:**
```json
{
  "name": "My New Store",
  "legal_name": "My Store LLC",
  "slug": "my-new-store",
  "email": "store@example.com",
  "phone": "+1234567890",
  "address": "123 Main St",
  "city": "New York",
  "state": "NY",
  "postal_code": "10001",
  "country": "US",
  "currency": "USD",
  "time_zone": "America/New_York",
  "tax_registration_number": "TAX123456",
  "default_tax_rate": 8.5,
  "settings": {},
  "main_branch_code": "MAIN",
  "main_branch_name": "Main Branch"
}
```

**Validation Rules:**
- `name`: required, string, max:255
- `legal_name`: nullable, string, max:255
- `slug`: nullable, string, max:255, unique:businesses,slug
- `email`: nullable, email, max:255
- `phone`: nullable, string, max:50
- `address`: nullable, string
- `city`: nullable, string, max:150
- `state`: nullable, string, max:150
- `postal_code`: nullable, string, max:50
- `country`: nullable, string, size:2
- `currency`: nullable, string, size:3
- `time_zone`: nullable, string, max:100
- `tax_registration_number`: nullable, string, max:150
- `default_tax_rate`: nullable, numeric, min:0, max:100
- `settings`: nullable, array
- `main_branch_code`: nullable, string, max:32
- `main_branch_name`: nullable, string, max:255

**Response (201):**
```json
{
  "message": "Business created",
  "data": {
    "business": { /* business object */ },
    "branch": { /* main branch object */ }
  }
}
```

### 2.3 Get Business Details
**Endpoint:** `GET /api/businesses/{id}`

**Headers:** `Authorization: Bearer {token}`

**Response (200):**
```json
{
  "data": {
    "id": 1,
    "uuid": "550e8400-e29b-41d4-a716-446655440000",
    "name": "My Store",
    "slug": "my-store-abc123",
    "currency": "USD",
    "time_zone": "UTC",
    "role": "owner",
    "branch_id": 1,
    "is_active": true,
    "branches": [ /* array of branches */ ]
  }
}
```

### 2.4 Update Business
**Endpoint:** `PUT /api/businesses/{id}`

**Headers:** `Authorization: Bearer {token}`

**Request Body:**
```json
{
  "name": "Updated Store Name",
  "email": "newemail@example.com",
  "default_tax_rate": 9.0,
  "is_active": true
}
```

**Validation Rules:**
- All fields from create, prefixed with `sometimes`
- Only business owner can update

**Response (200):**
```json
{
  "message": "Business updated",
  "data": { /* updated business object */ }
}
```

### 2.5 Delete Business
**Endpoint:** `DELETE /api/businesses/{id}`

**Headers:** `Authorization: Bearer {token}`

**Response (200):**
```json
{
  "message": "Business deleted"
}
```

**Notes:**
- Only business owner can delete
- Soft delete

---

## 3. Branch Management

### 3.1 List Branches
**Endpoint:** `GET /api/branches`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required (business context)

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "uuid": "550e8400-e29b-41d4-a716-446655440001",
      "name": "Main Branch",
      "code": "MAIN",
      "is_main": true,
      "email": "main@example.com",
      "phone": "+1234567890",
      "address": "123 Main St",
      "city": "New York",
      "state": "NY",
      "postal_code": "10001",
      "country": "US",
      "time_zone": "America/New_York",
      "tax_rate": 8.5,
      "settings": {},
      "is_active": true
    }
  ]
}
```

### 3.2 Create Branch
**Endpoint:** `POST /api/branches`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "name": "Downtown Branch",
  "code": "DOWNTOWN",
  "email": "downtown@example.com",
  "phone": "+1234567890",
  "address": "456 Market St",
  "city": "New York",
  "state": "NY",
  "postal_code": "10002",
  "country": "US",
  "time_zone": "America/New_York",
  "tax_rate": 8.5,
  "settings": {},
  "is_main": false,
  "is_active": true
}
```

**Validation Rules:**
- `name`: required, string, max:255
- `code`: required, string, max:32, unique within business
- `email`: nullable, email, max:255
- `phone`: nullable, string, max:50
- `address`: nullable, string
- `city`: nullable, string, max:150
- `state`: nullable, string, max:150
- `postal_code`: nullable, string, max:50
- `country`: nullable, string, size:2
- `time_zone`: nullable, string, max:100
- `tax_rate`: nullable, numeric, min:0, max:100
- `settings`: nullable, array
- `is_main`: nullable, boolean
- `is_active`: nullable, boolean

**Response (201):**
```json
{
  "message": "Branch created",
  "data": { /* branch object */ }
}
```

**Notes:**
- Only business owner can create branches
- If `is_main` is true, other main branches will be unset

### 3.3 Get Branch Details
**Endpoint:** `GET /api/branches/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Response (200):**
```json
{
  "data": { /* branch object with all fields */ }
}
```

### 3.4 Update Branch
**Endpoint:** `PUT /api/branches/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "name": "Updated Branch Name",
  "tax_rate": 9.0,
  "is_active": true
}
```

**Validation Rules:**
- Same as create, all prefixed with `sometimes`

**Response (200):**
```json
{
  "message": "Branch updated",
  "data": { /* updated branch object */ }
}
```

**Notes:**
- Only business owner can update

### 3.5 Delete Branch
**Endpoint:** `DELETE /api/branches/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Response (200):**
```json
{
  "message": "Branch deleted"
}
```

**Notes:**
- Only business owner can delete
- Cannot delete main branch if it's the only branch

---

## 4. User Management

### 4.1 List Users in Business
**Endpoint:** `GET /api/business-users`

**Headers:** `Authorization: Bearer {token}`, `X-Business-Id: {business_id}`

**Query Parameters:**
- `X-Business-Id` (or `current_business_id`): required
- `branch_id`: optional – filter to users who have a role in this branch or a business-wide role; branch must belong to the business and requester must have branch access

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com",
      "is_active": true,
      "joined_at": "2024-01-01T00:00:00.000000Z",
      "roles": [
        {
          "id": 1,
          "name": "Manager"
        }
      ]
    }
  ]
}
```

### 4.2 Add User to Business
**Endpoint:** `POST /api/user-business`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "email": "newuser@example.com",
  "name": "New User",
  "is_active": true
}
```

**Validation Rules:**
- `email`: required, email
- `name`: required, string, max:255
- `is_active`: sometimes, boolean

**Response (201):**
```json
{
  "message": "User added to business",
  "data": {
    "user": {
      "id": 2,
      "name": "New User",
      "email": "newuser@example.com"
    },
    "business": {
      "id": 1,
      "name": "My Store"
    },
    "is_active": true,
    "is_new_user": true
  }
}
```

**Notes:**
- Only business owner can add users
- Creates new user if email doesn't exist
- New users get random password and must reset

### 4.3 Get User Details
**Endpoint:** `GET /api/user-business/{userId}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Response (200):**
```json
{
  "data": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "is_active": true,
    "is_owner": false,
    "joined_at": "2024-01-01T00:00:00.000000Z",
    "updated_at": "2024-01-01T00:00:00.000000Z",
    "roles": [ /* array of roles */ ],
    "permissions": [ /* array of permission names */ ]
  }
}
```

### 4.4 Update User Status
**Endpoint:** `PUT /api/user-business/{userId}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "is_active": false
}
```

**Validation Rules:**
- `is_active`: required, boolean

**Response (200):**
```json
{
  "message": "User status updated",
  "data": {
    "user": { /* user object */ },
    "is_active": false
  }
}
```

**Notes:**
- Only business owner can update
- Cannot deactivate business owner

### 4.5 Remove User from Business
**Endpoint:** `DELETE /api/user-business/{userId}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Response (200):**
```json
{
  "message": "User removed from business"
}
```

**Notes:**
- Only business owner can remove users
- Cannot remove business owner
- Removes all role assignments

---

## 5. Role & Permission Management

### 5.1 List Permissions
**Endpoint:** `GET /api/permissions`

**Headers:** `Authorization: Bearer {token}`

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "view products"
    },
    {
      "id": 2,
      "name": "create products"
    }
  ]
}
```

### 5.2 List Roles
**Endpoint:** `GET /api/roles`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Manager",
      "permissions": ["view products", "create products", "edit products"],
      "users_count": 5
    }
  ]
}
```

### 5.3 Create Role
**Endpoint:** `POST /api/roles`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "name": "Cashier",
  "permissions": [
    "view products",
    "create sales",
    "view sales"
  ]
}
```

**Validation Rules:**
- `name`: required, string, max:255, unique within business
- `permissions`: nullable, array
- `permissions.*`: string, exists:permissions,name,guard_name,api

**Response (201):**
```json
{
  "message": "Role created",
  "data": {
    "id": 2,
    "name": "Cashier",
    "permissions": ["view products", "create sales", "view sales"]
  }
}
```

**Notes:**
- Only business owner can create roles

### 5.4 Get Role Details
**Endpoint:** `GET /api/roles/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Response (200):**
```json
{
  "data": {
    "id": 1,
    "name": "Manager",
    "permissions": [ /* array of permission names */ ],
    "users": [
      {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com"
      }
    ]
  }
}
```

### 5.5 Update Role
**Endpoint:** `PUT /api/roles/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "name": "Senior Cashier",
  "permissions": [
    "view products",
    "create sales",
    "view sales",
    "void sales"
  ]
}
```

**Validation Rules:**
- `name`: sometimes, string, max:255, unique within business
- `permissions`: sometimes, nullable, array
- `permissions.*`: string, exists:permissions,name,guard_name,api

**Response (200):**
```json
{
  "message": "Role updated",
  "data": { /* updated role object */ }
}
```

### 5.6 Delete Role
**Endpoint:** `DELETE /api/roles/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Response (200):**
```json
{
  "message": "Role deleted"
}
```

**Notes:**
- Only business owner can delete

---

## 6. Product Categories

### 6.1 List Categories
**Endpoint:** `GET /api/product-categories`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required
- `flat`: boolean (default: false) - flat list vs hierarchical
- `parent_id`: integer - filter by parent category
- `active_only`: boolean (default: false)
- `with_products`: boolean (default: false)

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "name": "Electronics",
      "slug": "electronics",
      "description": "Electronic products",
      "parent_id": null,
      "image": "http://example.com/images/electronics.jpg",
      "sort_order": 0,
      "is_active": true,
      "meta_data": {},
      "children": [ /* nested categories if hierarchical */ ]
    }
  ]
}
```

**Notes:**
- Requires 'view categories' permission

### 6.2 Create Category
**Endpoint:** `POST /api/product-categories`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "name": "Smartphones",
  "slug": "smartphones",
  "description": "Mobile phones and accessories",
  "parent_id": 1,
  "image": "http://example.com/images/smartphones.jpg",
  "sort_order": 0,
  "is_active": true,
  "meta_data": {}
}
```

**Validation Rules:**
- `name`: required, string, max:255
- `slug`: nullable, string, max:255, unique within business
- `description`: nullable, string
- `parent_id`: nullable, integer, exists:product_categories,id (same business)
- `image`: nullable, string
- `sort_order`: nullable, integer
- `is_active`: nullable, boolean
- `meta_data`: nullable, array

**Response (201):**
```json
{
  "message": "Category created successfully",
  "data": { /* category object */ }
}
```

**Notes:**
- Requires 'create categories' permission
- Slug auto-generated if not provided

### 6.3 Get Category Details
**Endpoint:** `GET /api/product-categories/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required
- `with_products`: boolean (default: false)
- `with_children`: boolean (default: false)

**Response (200):**
```json
{
  "data": { /* category object with optional products/children */ }
}
```

### 6.4 Update Category
**Endpoint:** `PUT /api/product-categories/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "name": "Updated Category Name",
  "description": "Updated description",
  "is_active": false
}
```

**Validation Rules:**
- Same as create, all prefixed with `sometimes`
- Prevents circular references in parent_id

**Response (200):**
```json
{
  "message": "Category updated successfully",
  "data": { /* updated category object */ }
}
```

**Notes:**
- Requires 'edit categories' permission

### 6.5 Delete Category
**Endpoint:** `DELETE /api/product-categories/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Response (200):**
```json
{
  "message": "Category deleted successfully"
}
```

**Notes:**
- Requires 'delete categories' permission

---

## 7. Products

### 7.1 List Products
**Endpoint:** `GET /api/products`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required
- `category_id`: integer - filter by category
- `active_only`: boolean - filter active products
- `search`: string - search by name, SKU, or barcode
- `branch_id`: integer - filter by branch availability
- `per_page`: integer (default: 15)

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "name": "Product Name",
      "sku": "PROD001",
      "barcode": "1234567890123",
      "category": {
        "id": 1,
        "name": "Electronics"
      },
      "description": "Product description",
      "image": "http://example.com/images/product.jpg",
      "base_cost_price": 50.00,
      "base_selling_price": 75.00,
      "is_taxable": true,
      "default_tax_rate": 8.5,
      "unit_of_measure": "pcs",
      "weight": 0.5,
      "weight_unit": "kg",
      "stock_tracking": "simple",
      "low_stock_threshold": 10,
      "is_active": true,
      "is_available_online": false,
      "meta_data": {}
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 15,
    "total": 75
  }
}
```

**Notes:**
- Requires 'view products' permission

### 7.2 Create Product
**Endpoint:** `POST /api/products`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "name": "New Product",
  "sku": "PROD002",
  "barcode": "1234567890124",
  "category_id": 1,
  "description": "Product description",
  "image": "http://example.com/images/product.jpg",
  "base_cost_price": 50.00,
  "base_selling_price": 75.00,
  "is_taxable": true,
  "default_tax_rate": 8.5,
  "unit_of_measure": "pcs",
  "weight": 0.5,
  "weight_unit": "kg",
  "stock_tracking": "simple",
  "low_stock_threshold": 10,
  "is_active": true,
  "is_available_online": false,
  "meta_data": {}
}
```

**Validation Rules:**
- `name`: required, string, max:255
- `sku`: required, string, max:255, unique:products,sku
- `barcode`: nullable, string, max:255, unique:products,barcode
- `category_id`: nullable, integer, exists:product_categories,id (same business)
- `description`: nullable, string
- `image`: nullable, string
- `base_cost_price`: nullable, numeric, min:0
- `base_selling_price`: required, numeric, min:0
- `is_taxable`: nullable, boolean
- `default_tax_rate`: nullable, numeric, min:0, max:100
- `unit_of_measure`: nullable, string, max:50
- `weight`: nullable, numeric, min:0
- `weight_unit`: nullable, string, max:20
- `stock_tracking`: nullable, in:none,simple,variant
- `low_stock_threshold`: nullable, integer, min:0
- `is_active`: nullable, boolean
- `is_available_online`: nullable, boolean
- `meta_data`: nullable, array

**Response (201):**
```json
{
  "message": "Product created successfully",
  "data": { /* product object */ }
}
```

**Notes:**
- Requires 'create products' permission

### 7.3 Get Product Details
**Endpoint:** `GET /api/products/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required
- `branch_id`: integer (optional) - include branch-specific data

**Response (200):**
```json
{
  "data": { /* product object with full details */ }
}
```

### 7.4 Update Product
**Endpoint:** `PUT /api/products/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "name": "Updated Product Name",
  "base_selling_price": 80.00,
  "is_active": false
}
```

**Validation Rules:**
- Same as create, all prefixed with `sometimes`

**Response (200):**
```json
{
  "message": "Product updated successfully",
  "data": { /* updated product object */ }
}
```

**Notes:**
- Requires 'edit products' permission

### 7.5 Delete Product
**Endpoint:** `DELETE /api/products/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Response (200):**
```json
{
  "message": "Product deleted successfully"
}
```

**Notes:**
- Requires 'delete products' permission

### 7.6 List Product Units
**Endpoint:** `GET /api/products/{id}/units`

**Headers:** `Authorization: Bearer {token}`, `X-Business-Id: {business_id}`

**Description:** List unit definitions for a product (e.g. piece, pack of 6, carton) used for tiered pricing.

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "product_id": 1,
      "name": "Pack of 6",
      "quantity_multiplier": 6,
      "min_quantity": null,
      "display_order": 0
    }
  ]
}
```

### 7.7 Create Product Unit
**Endpoint:** `POST /api/products/{id}/units`

**Headers:** `Authorization: Bearer {token}`, `X-Business-Id: {business_id}`

**Request Body:**
```json
{
  "name": "Pack of 6",
  "quantity_multiplier": 6,
  "min_quantity": null,
  "display_order": 0
}
```

**Validation Rules:** `name` required; `quantity_multiplier` required, integer, min:1; `min_quantity` nullable, integer, min:1; `display_order` nullable, integer, min:0.

**Response (201):** `{ "message": "Unit created", "data": { /* unit object */ } }`

### 7.8 Update Product Unit
**Endpoint:** `PUT /api/products/{id}/units/{unitId}`

**Request Body:** Same fields as create (all optional).

### 7.9 Delete Product Unit
**Endpoint:** `DELETE /api/products/{id}/units/{unitId}`

**Response (200):** `{ "message": "Unit deleted" }`

---

## 8. Branch Products

### 8.1 List Branch Products
**Endpoint:** `GET /api/branch-products`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required
- `branch_id`: required
- `is_available`: boolean - filter available products
- `is_featured`: boolean - filter featured products
- `stock_status`: string - in_stock, low_stock, out_of_stock
- `search`: string - search by product name/SKU/barcode
- `per_page`: integer (default: 15)

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "branch_id": 1,
      "product_id": 1,
      "product": {
        "id": 1,
        "name": "Product Name",
        "sku": "PROD001",
        "barcode": "1234567890123",
        "image": "http://example.com/images/product.jpg",
        "category": {
          "id": 1,
          "name": "Electronics"
        }
      },
      "pricing": {
        "cost_price": 50.00,
        "selling_price": 75.00,
        "compare_price": 85.00,
        "discount_amount": 5.00,
        "discount_type": "fixed",
        "tax_rate": 8.5,
        "final_price": 70.00,
        "price_with_tax": 75.95,
        "profit_margin": 28.57
      },
      "inventory": {
        "stock_quantity": 100,
        "shelf_quantity": 20,
        "store_quantity": 80,
        "low_stock_threshold": 10,
        "allow_backorder": false,
        "reorder_point": 15,
        "reorder_quantity": 50,
        "is_in_stock": true,
        "is_low_stock": false,
        "is_out_of_stock": false,
        "needs_reorder": false,
        "shelf_needs_restocking": false,
        "bin_location": "A-1",
        "shelf_location": "S-1"
      },
      "settings": {
        "is_available": true,
        "is_featured": false,
        "display_order": 0
      },
      "branch_meta_data": {},
      "created_at": "2024-01-01T00:00:00.000000Z",
      "updated_at": "2024-01-01T00:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 15,
    "total": 75
  }
}
```

### 8.2 Add Product to Branch
**Endpoint:** `POST /api/branch-products`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "branch_id": 1,
  "product_id": 1,
  "cost_price": 50.00,
  "selling_price": 75.00,
  "compare_price": 85.00,
  "discount_amount": 5.00,
  "discount_type": "fixed",
  "tax_rate": 8.5,
  "stock_quantity": 100,
  "shelf_quantity": 20,
  "store_quantity": 80,
  "low_stock_threshold": 10,
  "allow_backorder": false,
  "reorder_point": 15,
  "reorder_quantity": 50,
  "is_available": true,
  "is_featured": false,
  "display_order": 0,
  "bin_location": "A-1",
  "shelf_location": "S-1",
  "branch_meta_data": {}
}
```

**Validation Rules:**
- `branch_id`: required, integer, exists:branches,id
- `product_id`: required, integer, exists:products,id
- `cost_price`: nullable, numeric, min:0
- `selling_price`: nullable, numeric, min:0
- `compare_price`: nullable, numeric, min:0
- `discount_amount`: nullable, numeric, min:0
- `discount_type`: nullable, in:fixed,percentage
- `tax_rate`: nullable, numeric, min:0, max:100
- `stock_quantity`: nullable, integer, min:0
- `shelf_quantity`: nullable, integer, min:0
- `store_quantity`: nullable, integer, min:0
- `low_stock_threshold`: nullable, integer, min:0
- `allow_backorder`: nullable, boolean
- `reorder_point`: nullable, integer, min:0
- `reorder_quantity`: nullable, integer, min:0
- `is_available`: nullable, boolean
- `is_featured`: nullable, boolean
- `display_order`: nullable, integer
- `bin_location`: nullable, string, max:255
- `shelf_location`: nullable, string, max:255
- `branch_meta_data`: nullable, array

**Response (201):**
```json
{
  "message": "Product added to branch successfully",
  "data": { /* branch product object */ }
}
```

**Notes:**
- Total stock = shelf_quantity + store_quantity
- If only stock_quantity provided, all goes to shelf

### 8.3 Get Branch Product Details
**Endpoint:** `GET /api/branch-products/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Response (200):**
```json
{
  "data": { /* branch product object with full details */ }
}
```

### 8.4 Update Branch Product
**Endpoint:** `PUT /api/branch-products/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "selling_price": 80.00,
  "is_available": false,
  "low_stock_threshold": 15
}
```

**Validation Rules:**
- Same as create, all prefixed with `sometimes`

**Response (200):**
```json
{
  "message": "Branch product updated successfully",
  "data": { /* updated branch product object */ }
}
```

### 8.5 Delete Branch Product
**Endpoint:** `DELETE /api/branch-products/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Response (200):**
```json
{
  "message": "Product removed from branch successfully"
}
```

### 8.6 Get Branch Product Tiered Price
**Endpoint:** `GET /api/branch-products/{id}/price`

**Query Parameters:** `quantity` (required) – quantity to compute price for.

**Description:** Returns effective unit price and total using tiered pricing: exact pack match first, then quantity range tier, then single-unit price. Used by POS for dynamic totals.

**Response (200):**
```json
{
  "data": {
    "unit_price": 83.33,
    "total": 500,
    "tier_type": "pack",
    "product_unit_id": 1,
    "quantity_tier_id": null,
    "cost_per_unit": 50
  }
}
```

### 8.7 List Branch Product Unit Prices
**Endpoint:** `GET /api/branch-products/{id}/unit-prices`

**Response (200):** `{ "data": [ { "id", "branch_product_id", "product_unit_id", "selling_price", "product_unit": { ... } } ] }`

### 8.8 Create Branch Product Unit Price
**Endpoint:** `POST /api/branch-products/{id}/unit-prices`

**Request Body:** `{ "product_unit_id": 1, "selling_price": 2500 }`

### 8.9 Update Branch Product Unit Price
**Endpoint:** `PUT /api/branch-products/{id}/unit-prices/{unitPriceId}`

**Request Body:** `{ "selling_price": 2600 }`

### 8.10 Delete Branch Product Unit Price
**Endpoint:** `DELETE /api/branch-products/{id}/unit-prices/{unitPriceId}`

### 8.11 List Branch Product Quantity Tiers
**Endpoint:** `GET /api/branch-products/{id}/quantity-tiers`

**Response (200):** `{ "data": [ { "id", "branch_product_id", "min_quantity", "max_quantity", "price_per_unit" } ] }`

### 8.12 Create Branch Product Quantity Tier
**Endpoint:** `POST /api/branch-products/{id}/quantity-tiers`

**Request Body:** `{ "min_quantity": 6, "max_quantity": 19, "price_per_unit": 450 }` (max_quantity null = no upper limit)

### 8.13 Update Branch Product Quantity Tier
**Endpoint:** `PUT /api/branch-products/{id}/quantity-tiers/{tierId}`

### 8.14 Delete Branch Product Quantity Tier
**Endpoint:** `DELETE /api/branch-products/{id}/quantity-tiers/{tierId}`

---

## 9. Inventory

### 9.1 List Inventory Transactions
**Endpoint:** `GET /api/inventory`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required
- `branch_id`: integer - filter by branch
- `product_id`: integer - filter by product
- `type`: string - purchase, sale, adjustment, transfer_out, transfer_in, return, damage, initial
- `start_date`: date - filter from date
- `end_date`: date - filter to date
- `reference_number`: string - search by reference
- `per_page`: integer (default: 15)

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "product": {
        "id": 1,
        "name": "Product Name",
        "sku": "PROD001"
      },
      "branch": {
        "id": 1,
        "name": "Main Branch"
      },
      "user": {
        "id": 1,
        "name": "John Doe"
      },
      "type": "purchase",
      "quantity": 50,
      "shelf_quantity": 10,
      "store_quantity": 40,
      "quantity_before": 100,
      "shelf_quantity_before": 20,
      "store_quantity_before": 80,
      "quantity_after": 150,
      "shelf_quantity_after": 30,
      "store_quantity_after": 120,
      "unit_cost": 50.00,
      "total_cost": 2500.00,
      "related_branch_id": null,
      "reference_number": "PO-001",
      "notes": "Purchase order #001",
      "meta_data": {},
      "created_at": "2024-01-01T00:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 15,
    "total": 75
  }
}
```

**Notes:**
- Requires 'view inventory' permission
- Filtered by accessible branches

### 9.2 Create Inventory Transaction
**Endpoint:** `POST /api/inventory`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "branch_id": 1,
  "product_id": 1,
  "type": "purchase",
  "quantity": 50,
  "shelf_quantity": 10,
  "store_quantity": 40,
  "location": "both",
  "unit_cost": 50.00,
  "reference_number": "PO-001",
  "related_branch_id": null,
  "notes": "Purchase order #001",
  "meta_data": {},
  "batch_number": "BATCH001",
  "lot_number": "LOT001",
  "manufacturing_date": "2024-01-01",
  "expiry_date": "2025-01-01",
  "supplier_name": "Supplier Name",
  "supplier_reference": "SUP-001"
}
```

**Validation Rules:**
- `branch_id`: required, integer, exists:branches,id (same business)
- `product_id`: required, integer, exists:products,id (same business)
- `type`: required, in:purchase,sale,adjustment,transfer_out,transfer_in,return,damage,initial
- `quantity`: required, integer, not_in:0
- `shelf_quantity`: nullable, integer, min:0
- `store_quantity`: nullable, integer, min:0
- `location`: nullable, in:shelf,store,both
- `unit_cost`: nullable, numeric, min:0
- `reference_number`: nullable, string, max:255
- `related_branch_id`: nullable, integer, exists:branches,id (for transfers)
- `notes`: nullable, string
- `meta_data`: nullable, array
- `batch_number`: nullable, string, max:255
- `lot_number`: nullable, string, max:255
- `manufacturing_date`: nullable, date
- `expiry_date`: nullable, date, after:manufacturing_date
- `supplier_name`: nullable, string, max:255
- `supplier_reference`: nullable, string, max:255

**Response (201):**
```json
{
  "message": "Inventory transaction created successfully",
  "data": { /* transaction object */ }
}
```

**Notes:**
- Requires 'manage inventory' or 'adjust inventory' permission (for adjustments)
- Automatic batch creation for purchases
- FEFO allocation for stock-outs
- Cannot create negative stock

### 9.3 Get Transaction Details
**Endpoint:** `GET /api/inventory/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Response (200):**
```json
{
  "data": { /* transaction object with full details */ }
}
```

---

## 10. Customers

### 10.1 List Customers
**Endpoint:** `GET /api/customers`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Query Parameters:**
- `type`: string - walk-in, regular, vip
- `is_active`: boolean
- `search`: string - search by name, code, email, phone

**Response (200):**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "customer_code": "CUST-000001",
      "name": "John Customer",
      "email": "customer@example.com",
      "phone": "+1234567890",
      "address": "123 Customer St",
      "type": "regular",
      "credit_limit": 1000.00,
      "is_active": true,
      "metadata": {},
      "created_at": "2024-01-01T00:00:00.000000Z",
      "updated_at": "2024-01-01T00:00:00.000000Z"
    }
  ],
  "first_page_url": "...",
  "from": 1,
  "last_page": 5,
  "last_page_url": "...",
  "next_page_url": "...",
  "path": "...",
  "per_page": 15,
  "prev_page_url": null,
  "to": 15,
  "total": 75
}
```

**Notes:**
- Requires 'view customers' permission

### 10.2 Create Customer
**Endpoint:** `POST /api/customers`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Request Body:**
```json
{
  "name": "Jane Customer",
  "email": "jane@example.com",
  "phone": "+1234567890",
  "address": "456 Main St",
  "type": "vip",
  "credit_limit": 5000.00,
  "metadata": {}
}
```

**Validation Rules:**
- `name`: required, string, max:255
- `email`: nullable, email, max:255
- `phone`: nullable, string, max:50
- `address`: nullable, string
- `type`: nullable, in:walk-in,regular,vip
- `credit_limit`: nullable, numeric, min:0
- `metadata`: nullable, array

**Response (201):**
```json
{
  "message": "Customer created successfully",
  "customer": { /* customer object */ }
}
```

**Notes:**
- Requires 'create customers' permission
- Customer code auto-generated

### 10.3 Get Customer Details
**Endpoint:** `GET /api/customers/{id}`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Response (200):**
```json
{
  "id": 1,
  "customer_code": "CUST-000001",
  "name": "John Customer",
  /* ... other customer fields ... */
  "sales": [ /* last 10 sales */ ]
}
```

### 10.4 Update Customer
**Endpoint:** `PUT /api/customers/{id}`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Request Body:**
```json
{
  "name": "Updated Customer Name",
  "type": "vip",
  "is_active": true
}
```

**Validation Rules:**
- Same as create, all prefixed with `sometimes`

**Response (200):**
```json
{
  "message": "Customer updated successfully",
  "customer": { /* updated customer object */ }
}
```

**Notes:**
- Requires 'edit customers' permission

### 10.5 Delete Customer
**Endpoint:** `DELETE /api/customers/{id}`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Response (200):**
```json
{
  "message": "Customer deleted successfully"
}
```

**Notes:**
- Requires 'delete customers' permission

---

## 11. Payment Methods

### 11.1 List Payment Methods
**Endpoint:** `GET /api/payment-methods`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Query Parameters:**
- `is_active`: boolean

**Response (200):**
```json
[
  {
    "id": 1,
    "name": "Cash",
    "type": "cash",
    "description": "Cash payment",
    "account_details": {},
    "is_active": true,
    "sort_order": 0,
    "created_at": "2024-01-01T00:00:00.000000Z",
    "updated_at": "2024-01-01T00:00:00.000000Z"
  }
]
```

**Notes:**
- Requires 'view payment methods' permission

### 11.2 Create Payment Method
**Endpoint:** `POST /api/payment-methods`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Request Body:**
```json
{
  "name": "Credit Card",
  "type": "card",
  "description": "Credit/Debit card payment",
  "account_details": {
    "processor": "Stripe",
    "account_id": "acc_123456"
  },
  "is_active": true,
  "sort_order": 1
}
```

**Validation Rules:**
- `name`: required, string, max:255
- `type`: required, in:cash,card,mobile_money,bank_transfer,cheque,other
- `description`: nullable, string
- `account_details`: nullable, array
- `is_active`: nullable, boolean
- `sort_order`: nullable, integer

**Response (201):**
```json
{
  "message": "Payment method created successfully",
  "payment_method": { /* payment method object */ }
}
```

**Notes:**
- Requires 'manage payment methods' permission

### 11.3 Get Payment Method Details
**Endpoint:** `GET /api/payment-methods/{id}`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Response (200):**
```json
{
  "id": 1,
  "name": "Cash",
  /* ... other fields ... */
}
```

### 11.4 Update Payment Method
**Endpoint:** `PUT /api/payment-methods/{id}`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Request Body:**
```json
{
  "name": "Updated Method Name",
  "is_active": false
}
```

**Validation Rules:**
- Same as create, all prefixed with `sometimes`

**Response (200):**
```json
{
  "message": "Payment method updated successfully",
  "payment_method": { /* updated payment method object */ }
}
```

**Notes:**
- Requires 'manage payment methods' permission

### 11.5 Delete Payment Method
**Endpoint:** `DELETE /api/payment-methods/{id}`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Response (200):**
```json
{
  "message": "Payment method deleted successfully"
}
```

**Notes:**
- Requires 'manage payment methods' permission

---

## 12. Sales

### 12.1 List Sales
**Endpoint:** `GET /api/sales`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Query Parameters:**
- `branch_id`: integer
- `customer_id`: integer
- `status`: string - pending, completed, cancelled
- `payment_status`: string - unpaid, partial, paid, overpaid
- `sale_type`: string - pos, online, delivery, wholesale
- `start_date`: date
- `end_date`: date
- `search`: string - search by sale number

**Response (200):**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "sale_number": "SAL-20240101-0001",
      "customer": {
        "id": 1,
        "name": "John Customer"
      },
      "user": {
        "id": 1,
        "name": "Cashier Name"
      },
      "branch": {
        "id": 1,
        "name": "Main Branch"
      },
      "sale_date": "2024-01-01T12:00:00.000000Z",
      "subtotal": 100.00,
      "discount_amount": 5.00,
      "tax_amount": 8.08,
      "total_amount": 103.08,
      "status": "completed",
      "payment_status": "paid",
      "sale_type": "pos",
      "notes": null,
      "items": [ /* sale items */ ],
      "created_at": "2024-01-01T12:00:00.000000Z"
    }
  ],
  "per_page": 15,
  "total": 100
}
```

**Notes:**
- Requires 'view sales' permission
- Filtered by accessible branches

### 12.2 Create Sale
**Endpoint:** `POST /api/sales`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Request Body:**
```json
{
  "branch_id": 1,
  "customer_id": 1,
  "shift_id": 1,
  "sale_type": "pos",
  "discount_amount": 5.00,
  "notes": "Customer requested special handling",
  "items": [
    {
      "product_id": 1,
      "quantity": 2,
      "unit_price": 50.00,
      "discount_percentage": 0,
      "tax_rate": 8.5
    },
    {
      "product_id": 2,
      "quantity": 1,
      "unit_price": 25.00,
      "discount_percentage": 10,
      "tax_rate": 8.5
    }
  ],
  "payments": [
    {
      "payment_method_id": 1,
      "amount": 103.08,
      "reference_number": "CASH001"
    }
  ]
}
```

**Validation Rules:**
- `branch_id`: required, exists:branches,id
- `customer_id`: nullable, exists:customers,id
- `shift_id`: nullable, exists:sales_shifts,id
- `sale_type`: nullable, in:pos,online,delivery,wholesale
- `discount_amount`: nullable, numeric, min:0
- `notes`: nullable, string
- `items`: required, array, min:1
- `items.*.product_id`: required, exists:products,id
- `items.*.quantity`: required, numeric, min:0.01
- `items.*.unit_price`: required, numeric, min:0
- `items.*.discount_percentage`: nullable, numeric, min:0, max:100
- `items.*.tax_rate`: nullable, numeric, min:0, max:100
- `payments`: nullable, array
- `payments.*.payment_method_id`: required, exists:payment_methods,id
- `payments.*.amount`: required, numeric, min:0.01
- `payments.*.reference_number`: nullable, string

**Response (201):**
```json
{
  "message": "Sale created successfully",
  "sale": { /* complete sale object with items and payments */ }
}
```

**Notes:**
- Requires 'create sales' permission
- Unit price is optional: when omitted, the server computes it from tiered pricing (exact pack → quantity range → single-unit). When provided, the user must have **override sale price** permission or the sent price is ignored and the computed price is used.
- Automatically deducts stock
- Creates inventory transactions
- Links to open shift if available
- Sale number auto-generated

### 12.3 Get Sale Details
**Endpoint:** `GET /api/sales/{id}`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Response (200):**
```json
{
  "id": 1,
  "sale_number": "SAL-20240101-0001",
  /* ... all sale fields ... */
  "items": [ /* full item details */ ],
  "payments": [ /* payment details */ ],
  "customer": { /* customer object */ },
  "branch": { /* branch object */ },
  "user": { /* user object */ }
}
```

### 12.4 Add Payment to Sale
**Endpoint:** `POST /api/sales/{id}/add-payment`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Request Body:**
```json
{
  "payment_method_id": 1,
  "amount": 50.00,
  "reference_number": "CARD123",
  "notes": "Partial payment"
}
```

**Validation Rules:**
- `payment_method_id`: required, exists:payment_methods,id
- `amount`: required, numeric, min:0.01
- `reference_number`: nullable, string
- `notes`: nullable, string

**Response (200):**
```json
{
  "message": "Payment added successfully",
  "payment": { /* payment object */ },
  "sale": { /* updated sale with all payments */ }
}
```

**Notes:**
- Requires 'manage sales' permission
- Updates payment_status automatically

### 12.5 Cancel Sale
**Endpoint:** `POST /api/sales/{id}/cancel`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Response (200):**
```json
{
  "message": "Sale cancelled successfully",
  "sale": { /* updated sale object */ }
}
```

**Notes:**
- Requires 'manage sales' permission
- Restores stock for all items
- Creates reversal inventory transactions
- Cannot cancel already cancelled sales

---

## 13. Sales Shifts

### 13.1 List Shifts
**Endpoint:** `GET /api/sales-shifts`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Query Parameters:**
- `branch_id`: integer
- `user_id`: integer
- `status`: string - open, closed
- `has_discrepancy`: boolean
- `filter`: string - today, last_7_days
- `start_date`: date
- `end_date`: date

**Response (200):**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "shift_number": "SH-20240101-0001",
      "user": {
        "id": 1,
        "name": "Cashier Name"
      },
      "branch": {
        "id": 1,
        "name": "Main Branch"
      },
      "start_time": "2024-01-01T08:00:00.000000Z",
      "end_time": "2024-01-01T17:00:00.000000Z",
      "opening_balance": 100.00,
      "expected_cash": 500.00,
      "actual_cash": 495.00,
      "variance": -5.00,
      "status": "closed",
      "opening_notes": null,
      "closing_notes": "Missing 5.00",
      "discrepancy_resolved": false,
      "statistics": {
        "total_sales": 10,
        "total_revenue": 1000.00,
        "cash_sales": 500.00,
        "card_sales": 500.00
      }
    }
  ],
  "per_page": 15,
  "total": 50
}
```

**Notes:**
- Requires 'view all shifts' or 'view user shift' permission
- Users with only 'view user shift' see their own shifts only
- Filtered by accessible branches

### 13.2 Open Shift
**Endpoint:** `POST /api/sales-shifts`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Request Body:**
```json
{
  "branch_id": 1,
  "opening_balance": 100.00,
  "opening_notes": "Starting shift with cash float"
}
```

**Validation Rules:**
- `branch_id`: required, exists:branches,id
- `opening_balance`: required, numeric, min:0
- `opening_notes`: nullable, string

**Response (201):**
```json
{
  "message": "Shift opened successfully",
  "shift": { /* shift object */ }
}
```

**Notes:**
- Requires 'create shift' permission
- User can have only ONE open shift at a time
- Branch can have multiple open shifts (one per user)
- Shift number auto-generated

### 13.3 Get Shift Details
**Endpoint:** `GET /api/sales-shifts/{id}`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Response (200):**
```json
{
  "id": 1,
  "shift_number": "SH-20240101-0001",
  /* ... all shift fields ... */
  "sales": [ /* all sales in this shift */ ],
  "statistics": {
    "total_sales": 10,
    "total_revenue": 1000.00,
    "cash_sales": 500.00,
    "card_sales": 500.00,
    "cancelled_sales": 1,
    "refunds": 0
  }
}
```

**Notes:**
- Users with only 'view user shift' can only view their own shifts

### 13.4 Close Shift
**Endpoint:** `POST /api/sales-shifts/{id}/close`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Request Body:**
```json
{
  "actual_cash": 595.00,
  "closing_notes": "Cash count complete"
}
```

**Validation Rules:**
- `actual_cash`: required, numeric, min:0
- `closing_notes`: nullable, string

**Response (200):**
```json
{
  "message": "Shift closed successfully",
  "shift": { /* updated shift with calculated variance */ }
}
```

**Notes:**
- Requires 'close shift' permission
- Only shift owner or users with 'manage shifts' can close
- Automatically calculates expected_cash and variance
- Cannot close already closed shifts

### 13.5 Get Current Shift
**Endpoint:** `GET /api/sales-shifts/current`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Response (200):**
```json
{
  "id": 1,
  "shift_number": "SH-20240101-0001",
  /* ... current open shift details ... */
}
```

**Response (404):**
```json
{
  "message": "No open shift found"
}
```

### 13.6 Resolve Shift Discrepancy
**Endpoint:** `POST /api/sales-shifts/{id}/resolve-discrepancy`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Request Body:**
```json
{
  "resolution_notes": "Discrepancy due to incorrect denomination count. Recounted and verified."
}
```

**Validation Rules:**
- `resolution_notes`: required, string, max:1000

**Response (200):**
```json
{
  "message": "Shift discrepancy marked as resolved",
  "shift": { /* updated shift object */ }
}
```

**Notes:**
- Requires 'manage shifts' permission
- Only for closed shifts with variance

### 13.7 Get Shift Sales
**Endpoint:** `GET /api/sales-shifts/{id}/sales`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Query Parameters:**
- `status`: string - voided, active
- `payment_method`: string - filter by payment method name

**Response (200):**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "sale_number": "SAL-20240101-0001",
      "total": 103.08,
      "status": "completed",
      "customer": { /* customer object */ },
      "payment_methods": [
        {
          "method": "Cash",
          "amount": 103.08,
          "reference": null
        }
      ],
      "created_at": "2024-01-01T12:00:00.000000Z"
    }
  ],
  "per_page": 20,
  "total": 50
}
```

---

## 14. Batches

### 14.1 List Batches
**Endpoint:** `GET /api/batches`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required
- `branch_id`: integer
- `product_id`: integer
- `status`: string - active, depleted, expired
- `expired`: boolean (true)
- `near_expiry`: integer (days, default: 30)
- `batch_number`: string - search
- `lot_number`: string - search
- `sort_by`: string - expiry_date, created_at, etc.
- `sort_direction`: string - asc, desc
- `per_page`: integer (default: 15)

**Response (200):**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "batch_number": "BATCH001",
      "lot_number": "LOT001",
      "product": {
        "id": 1,
        "name": "Product Name",
        "sku": "PROD001"
      },
      "branch": {
        "id": 1,
        "name": "Main Branch"
      },
      "manufacturing_date": "2024-01-01",
      "expiry_date": "2025-01-01",
      "received_quantity": 100,
      "current_quantity": 75,
      "unit_cost": 50.00,
      "supplier_name": "Supplier Inc",
      "status": "active",
      "created_at": "2024-01-01T00:00:00.000000Z"
    }
  ],
  "per_page": 15,
  "total": 50
}
```

**Notes:**
- Requires 'view batches' permission
- Filtered by accessible branches

### 14.2 Get Batches for Product
**Endpoint:** `GET /api/batches/product/{productId}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Response (200):**
```json
{
  "batches": [
    {
      "id": 1,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "batch_number": "BATCH001",
      "lot_number": "LOT001",
      "branch": {
        "id": 1,
        "name": "Main Branch"
      },
      "expiry_date": "2025-01-01",
      "manufacturing_date": "2024-01-01",
      "received_quantity": 100,
      "current_quantity": 75,
      "unit_cost": 50.00,
      "supplier_name": "Supplier Inc",
      "status": "active",
      "is_expired": false,
      "is_near_expiry": false,
      "days_until_expiry": 365
    }
  ]
}
```

**Notes:**
- Sorted by FEFO (First Expiry, First Out)

### 14.3 Get Near-Expiry Batches
**Endpoint:** `GET /api/batches/near-expiry`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required
- `days`: integer (default: 30)

**Response (200):**
```json
{
  "batches": [ /* array of batch objects */ ],
  "count": 15,
  "days_threshold": 30
}
```

### 14.4 Get Expired Batches
**Endpoint:** `GET /api/batches/expired`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Response (200):**
```json
{
  "batches": [ /* array of expired batches with stock */ ],
  "count": 5,
  "total_value": "2500.00"
}
```

### 14.5 Get Batch Details
**Endpoint:** `GET /api/batches/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Response (200):**
```json
{
  "batch": {
    "id": 1,
    "uuid": "550e8400-e29b-41d4-a716-446655440000",
    "batch_number": "BATCH001",
    /* ... all batch fields ... */
    "original_transaction": {
      "id": 1,
      "reference_number": "PO-001",
      "type": "purchase",
      "created_at": "2024-01-01T00:00:00.000000Z"
    },
    "transaction_count": 5
  }
}
```

### 14.6 Update Batch
**Endpoint:** `PUT /api/batches/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "status": "expired",
  "notes": "Batch recalled by supplier"
}
```

**Validation Rules:**
- `status`: nullable, in:active,depleted,expired,recalled
- `notes`: nullable, string

**Response (200):**
```json
{
  "message": "Batch updated successfully",
  "batch": { /* updated batch object */ }
}
```

**Notes:**
- Requires 'manage batches' permission

---

## 15. Analytics

### 15.1 Organization Analytics
**Endpoint:** `GET /api/analytics/organization`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Query Parameters:**
- `period`: string - today, week, month, year, custom
- `start_date`: date (required if period=custom)
- `end_date`: date (required if period=custom)
- `compare_previous`: boolean (default: true)

**Response (200):**
```json
{
  "period": {
    "start_date": "2024-01-01",
    "end_date": "2024-01-31",
    "days": 31
  },
  "current": {
    "total_sales": 150,
    "total_revenue": 15000.00,
    "total_cost": 9000.00,
    "total_profit": 6000.00,
    "average_order_value": 100.00,
    "profit_margin": 40.00
  },
  "previous": {
    "total_sales": 120,
    "total_revenue": 12000.00,
    /* ... */
  },
  "comparison": {
    "revenue_change": 25.00,
    "revenue_change_percentage": 25.00,
    "profit_change": 1500.00,
    "sales_count_change": 30
  },
  "branch_contributions": [
    {
      "branch_id": 1,
      "branch_name": "Main Branch",
      "revenue": 10000.00,
      "percentage": 66.67
    }
  ],
  "revenue_trend": [
    {
      "date": "2024-01-01",
      "revenue": 500.00
    }
  ]
}
```

**Notes:**
- Requires 'view analytics' permission
- Cached for 15 minutes

### 15.2 Branch Analytics
**Endpoint:** `GET /api/analytics/branch`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Query Parameters:**
- `branch_id`: integer (optional, defaults to all accessible branches)
- `period`: string - today, week, month, year, custom
- `start_date`: date (required if period=custom)
- `end_date`: date (required if period=custom)
- `compare_previous`: boolean (default: true)

**Response (200):**
```json
{
  "branches": [
    {
      "branch_id": 1,
      "branch_name": "Main Branch",
      "period": { /* period object */ },
      "current": { /* metrics */ },
      "previous": { /* metrics */ },
      "comparison": { /* comparison */ },
      "revenue_trend": [ /* daily breakdown */ ]
    }
  ]
}
```

**Notes:**
- Requires 'view analytics' permission
- Filtered by branch access

### 15.3 Product Analytics
**Endpoint:** `GET /api/analytics/products`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Query Parameters:**
- `branch_id`: integer (optional)
- `period`: string - today, week, month, year, custom
- `start_date`: date (required if period=custom)
- `end_date`: date (required if period=custom)
- `limit`: integer (default: 20, max: 100)
- `sort_by`: string - revenue, quantity, profit, margin
- `direction`: string - asc, desc

**Response (200):**
```json
{
  "period": {
    "start_date": "2024-01-01",
    "end_date": "2024-01-31"
  },
  "summary": {
    "total_products": 50,
    "total_revenue": "15000.00",
    "total_cost": "9000.00",
    "total_profit": "6000.00",
    "average_margin": "40.00"
  },
  "top_products": [
    {
      "product_id": 1,
      "product_name": "Product Name",
      "product_sku": "PROD001",
      "quantity_sold": 100,
      "revenue": "5000.00",
      "cost": "3000.00",
      "profit": "2000.00",
      "margin_percentage": "40.00",
      "transaction_count": 50,
      "contribution_percentage": "33.33"
    }
  ],
  "bottom_products": [ /* lowest performers */ ]
}
```

**Notes:**
- Requires 'view analytics' permission
- Cached for 15 minutes

### 15.4 Profit & Loss Statement
**Endpoint:** `GET /api/analytics/profit-loss`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Query Parameters:**
- `branch_id`: integer (optional)
- `period`: string - today, week, month, quarter, year, custom
- `start_date`: date (required if period=custom)
- `end_date`: date (required if period=custom)

**Response (200):**
```json
{
  "period": {
    "start_date": "2024-01-01",
    "end_date": "2024-01-31"
  },
  "revenue": {
    "gross_revenue": "15500.00",
    "discounts": "500.00",
    "net_revenue": "15000.00"
  },
  "costs": {
    "cost_of_goods_sold": "9000.00"
  },
  "profit": {
    "gross_profit": "6000.00",
    "net_profit": "6000.00"
  },
  "margins": {
    "gross_margin_percentage": "40.00",
    "net_margin_percentage": "40.00"
  },
  "metrics": {
    "total_transactions": 150,
    "average_transaction_value": "100.00"
  }
}
```

**Notes:**
- Requires 'view financial reports' permission
- Cached for 30 minutes

### 15.5 Growth Trends
**Endpoint:** `GET /api/analytics/growth-trends`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Query Parameters:**
- `branch_id`: integer (optional)
- `metric`: string - revenue, profit, sales_count, average_order_value
- `interval`: string - daily, weekly, monthly
- `periods`: integer (number of periods to show)

**Response (200):**
```json
{
  "metric": "revenue",
  "interval": "monthly",
  "data": [
    {
      "period": "2024-01",
      "value": 15000.00,
      "growth_rate": 25.00
    },
    {
      "period": "2024-02",
      "value": 18750.00,
      "growth_rate": 25.00
    }
  ]
}
```

**Notes:**
- Requires 'view analytics' permission

---

## 16. Stock Transfer Requests

### 16.1 List Transfer Requests
**Endpoint:** `GET /api/stock-transfer-requests`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required
- `branch_id`: integer
- `status`: string - pending, approved, rejected, completed
- `priority`: string - low, normal, high, urgent
- `my_requests`: boolean (default: false)
- `pending_approval`: boolean (default: false)
- `pending_confirmation`: boolean (default: false)
- `per_page`: integer (default: 15)

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "branch": {
        "id": 1,
        "name": "Main Branch"
      },
      "branch_product": {
        "id": 1,
        "product": {
          "id": 1,
          "name": "Product Name",
          "sku": "PROD001"
        }
      },
      "quantity_requested": 50,
      "quantity_approved": null,
      "quantity_transferred": null,
      "reason": "Running low on shelf stock",
      "priority": "normal",
      "status": "pending",
      "requested_by": {
        "id": 1,
        "name": "User Name"
      },
      "reviewed_by": null,
      "confirmed_by": null,
      "requested_at": "2024-01-01T12:00:00.000000Z",
      "reviewed_at": null,
      "confirmed_at": null,
      "notes": null
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 15,
    "total": 75
  }
}
```

**Notes:**
- Requires 'request stock transfer' or 'approve stock transfer' permission
- Users with only 'request' permission see their own requests

### 16.2 Create Transfer Request
**Endpoint:** `POST /api/stock-transfer-requests`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "branch_id": 1,
  "branch_product_id": 1,
  "quantity_requested": 50,
  "reason": "Shelf stock running low, need to restock from store",
  "priority": "normal"
}
```

**Validation Rules:**
- `branch_id`: required, integer, exists:branches,id (same business)
- `branch_product_id`: required, integer, exists:branch_products,id
- `quantity_requested`: required, integer, min:1
- `reason`: nullable, string, max:500
- `priority`: nullable, in:low,normal,high,urgent

**Response (201):**
```json
{
  "message": "Stock transfer request created successfully",
  "data": { /* transfer request object */ }
}
```

**Notes:**
- Requires 'request stock transfer' permission
- Validates sufficient store quantity
- Verifies branch product belongs to specified branch

### 16.3 Get Transfer Request Details
**Endpoint:** `GET /api/stock-transfer-requests/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Response (200):**
```json
{
  "data": { /* full transfer request object */ }
}
```

### 16.4 Approve Transfer Request
**Endpoint:** `POST /api/stock-transfer-requests/{id}/approve`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "notes": "Approved. Transfer 50 units from store to shelf."
}
```

**Validation Rules:**
- `notes`: nullable, string, max:500

**Response (200):**
```json
{
  "message": "Request approved successfully",
  "data": { /* updated transfer request */ }
}
```

**Notes:**
- Requires 'approve stock transfer' permission
- Cannot approve own requests
- Only pending requests can be approved

### 16.5 Reject Transfer Request
**Endpoint:** `POST /api/stock-transfer-requests/{id}/reject`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "reason": "Insufficient store stock at this time"
}
```

**Validation Rules:**
- `reason`: required, string, max:500

**Response (200):**
```json
{
  "message": "Request rejected successfully",
  "data": { /* updated transfer request */ }
}
```

**Notes:**
- Requires 'approve stock transfer' permission
- Cannot reject own requests

### 16.6 Confirm Transfer Request
**Endpoint:** `POST /api/stock-transfer-requests/{id}/confirm`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id` or `business_id`: required

**Request Body:**
```json
{
  "actual_quantity": 50,
  "notes": "Stock transferred successfully"
}
```

**Validation Rules:**
- `actual_quantity`: nullable, integer, min:1, max:(quantity_requested)
- `notes`: nullable, string, max:500

**Response (200):**
```json
{
  "message": "Transfer confirmed and completed successfully",
  "data": { /* completed transfer request */ }
}
```

**Notes:**
- Only requester or users with 'approve stock transfer' can confirm
- Only approved requests can be confirmed
- Performs actual stock transfer from store to shelf

---

## 17b. Shelf/Store Move Requests

Request-based workflow for moving stock between shelf and store. Users with **request shelf store move** create requests; users with **approve shelf store move** approve or reject. On approval, the move is performed. Direct moves (branch-products move-to-shelf / move-to-store) are available to users with **approve shelf store move** or **manage inventory** / **adjust inventory**.

### 17b.1 List Shelf/Store Move Requests
**Endpoint:** `GET /api/shelf-store-move-requests`

**Headers:** `Authorization: Bearer {token}`, `X-Business-Id: {business_id}`

**Query Parameters:**
- `branch_id`: integer
- `status`: pending, approved, rejected
- `my_requests`: boolean
- `pending_approval`: boolean
- `per_page`: integer (default: 15)

**Response (200):** Paginated list with `data` (array of move requests) and `meta`.

**Notes:** Requires request or approve shelf store move permission.

### 17b.2 Create Shelf/Store Move Request
**Endpoint:** `POST /api/shelf-store-move-requests`

**Headers:** `Authorization: Bearer {token}`, `X-Business-Id: {business_id}`

**Request Body:**
```json
{
  "branch_product_id": 1,
  "direction": "to_shelf",
  "quantity": 5,
  "reason": "Restock shelf"
}
```

**Validation Rules:**
- `branch_product_id`: required, integer, exists:branch_products,id
- `direction`: required, in:to_shelf,to_store
- `quantity`: required, integer, min:1
- `reason`: optional, string, max:500

**Response (201):** Created request with `request_number`, status `pending`, `branch_product`, `requested_by`, etc.

**Notes:** Requires **request shelf store move** permission.

### 17b.3 Get Shelf/Store Move Request
**Endpoint:** `GET /api/shelf-store-move-requests/{id}`

**Response (200):** Single request with branch, branch_product, requestedBy, reviewedBy, status.

### 17b.4 Approve Shelf/Store Move
**Endpoint:** `POST /api/shelf-store-move-requests/{id}/approve`

Performs the move (calls BranchProduct moveToShelf or moveToStore) and sets request status to `approved`.

**Response (200):** Updated request and success message.

**Notes:** Requires **approve shelf store move** permission. Request must be `pending`.

### 17b.5 Reject Shelf/Store Move
**Endpoint:** `POST /api/shelf-store-move-requests/{id}/reject`

**Request Body (optional):**
```json
{
  "reason": "Not needed at this time"
}
```

**Response (200):** Updated request with status `rejected`, `reviewed_by`, `reviewed_at`, `review_notes`.

**Notes:** Requires **approve shelf store move** permission.

---

## 17. Stock Write-offs

### 17.1 List Stock Write-offs
**Endpoint:** `GET /api/stock-writeoffs`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id`: required
- `branch_id`: integer
- `product_id`: integer
- `start_date`: date
- `end_date`: date
- `per_page`: integer (default: 15)

**Response (200):**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "product": {
        "id": 1,
        "name": "Product Name",
        "sku": "PROD001"
      },
      "branch": {
        "id": 1,
        "name": "Main Branch"
      },
      "branch_product": { /* branch product object */ },
      "sku": "PROD001",
      "quantity": 5,
      "reason": "Damaged during handling",
      "written_off_by": {
        "id": 1,
        "name": "User Name"
      },
      "written_off_at": "2024-01-01T12:00:00.000000Z",
      "created_at": "2024-01-01T12:00:00.000000Z"
    }
  ],
  "per_page": 15,
  "total": 50
}
```

**Notes:**
- Requires 'write off stock' permission
- Filtered by accessible branches

### 17.2 Write Off Stock
**Endpoint:** `POST /api/stock-writeoffs`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id`: required

**Request Body (use product_id + branch_id, or branch_product_id):**
```json
{
  "branch_id": 1,
  "product_id": 1,
  "quantity": 5,
  "source": "shelf",
  "reason": "Product damaged during transport"
}
```
Or with branch_product_id:
```json
{
  "branch_product_id": 1,
  "quantity": 5,
  "source": "shelf",
  "reason": "Product damaged during transport"
}
```

**Validation Rules:**
- `current_business_id`: required, exists:businesses,id
- `branch_id`: required when using product_id, exists:branches,id
- `product_id`: required when not using branch_product_id, exists:products,id
- `branch_product_id`: required when not using product_id, exists:branch_products,id
- `quantity`: required, integer, min:1
- `source`: required, in:shelf,store
- `reason`: required, string, max:1000

**Response (201):**
```json
{
  "message": "Stock written off successfully",
  "data": { /* write-off object */ }
}
```

**Notes:**
- Requires 'write off stock' permission
- Use product_id + branch_id, or branch_product_id to identify the product. Deducts from batches (FEFO) when product uses batch tracking.
- Deducts from shelf quantity only
- Creates inventory transaction with type 'damage'
- Validates sufficient shelf stock

### 17.3 Get Write-off Details
**Endpoint:** `GET /api/stock-writeoffs/{id}`

**Headers:** `Authorization: Bearer {token}`

**Query Parameters:**
- `current_business_id`: required

**Response (200):**
```json
{
  "data": { /* write-off object with full details */ }
}
```

---

## 18. Refund Requests

### 18.1 List Refund Requests
**Endpoint:** `GET /api/refund-requests`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Query Parameters:**
- `status`: string - pending, approved, rejected, processed
- `branch_id`: integer
- `sale_id`: integer

**Response (200):**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "sale": {
        "id": 1,
        "sale_number": "SAL-20240101-0001",
        "total_amount": 103.08,
        "customer": { /* customer object */ },
        "branch": { /* branch object */ }
      },
      "amount": 103.08,
      "reason": "Customer not satisfied with product quality",
      "status": "pending",
      "requested_by": {
        "id": 1,
        "name": "Cashier Name"
      },
      "reviewed_by": null,
      "requested_at": "2024-01-02T10:00:00.000000Z",
      "reviewed_at": null,
      "processed_at": null,
      "rejection_reason": null
    }
  ],
  "per_page": 15,
  "total": 20
}
```

**Notes:**
- Requires 'request refund' or 'approve refund' permission
- Users with only 'request refund' see their own requests
- Filtered by accessible branches

### 18.2 Create Refund Request
**Endpoint:** `POST /api/refund-requests`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Request Body (whole sale – default):**
```json
{
  "sale_id": 1,
  "reason": "Customer not satisfied with product quality. Requesting full refund."
}
```

**Request Body (partial – specific items):**
```json
{
  "sale_id": 1,
  "reason": "Customer returned 2 units only.",
  "refund_scope": "items",
  "items": [
    { "sale_item_id": 1, "quantity": 2 }
  ]
}
```

**Validation Rules:**
- `sale_id`: required, exists:sales,id
- `reason`: required, string, min:10, max:1000
- `refund_scope`: optional, in:whole_sale,items (default whole_sale)
- When `refund_scope` is `items`: `items` array required; each element must have `sale_item_id` (belongs to sale) and `quantity` (min 0.01, cannot exceed remaining refundable quantity for that line)

**Response (201):**
```json
{
  "message": "Refund request submitted successfully",
  "refund_request": { "id", "sale_id", "refund_scope", "amount", "reason", "status", "items": [ { "sale_item_id", "quantity", "saleItem": { "product": { ... } } } ], ... }
}
```

**Notes:**
- Requires 'request refund' permission
- Sale must be refundable (completed, refunded_amount < total_amount)
- Cannot create duplicate pending requests for same sale
- **Whole sale:** amount = sale total; on approval all items restored. **Items:** amount computed from selected lines; on approval only those items/quantities restored; sale refunded_amount incremented

### 18.3 Get Refund Request Details
**Endpoint:** `GET /api/refund-requests/{id}`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Response (200):**
```json
{
  "id": 1,
  "sale": {
    "id": 1,
    "sale_number": "SAL-20240101-0001",
    /* ... full sale details ... */
    "items": [ /* sale items with products */ ],
    "payments": [ /* payment details */ ]
  },
  "amount": 103.08,
  "reason": "Customer not satisfied with product quality",
  "status": "pending",
  "requested_by": { /* user object */ },
  "reviewed_by": null,
  /* ... timestamps ... */
}
```

### 18.4 Approve Refund Request
**Endpoint:** `POST /api/refund-requests/{id}/approve`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Response (200):**
```json
{
  "message": "Refund request approved and processed successfully",
  "refund_request": { /* updated refund request */ }
}
```

**Notes:**
- Requires 'approve refund' permission
- Cannot approve own requests
- Only pending requests can be approved
- **Whole sale:** Restores stock for all sale items; adds request amount to sale refunded_amount; marks sale fully refunded when total reached.
- **Items:** Restores stock only for requested line items/quantities; adds request amount to sale refunded_amount; marks sale fully refunded when refunded_amount >= total.

### 18.5 Reject Refund Request
**Endpoint:** `POST /api/refund-requests/{id}/reject`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Request Body:**
```json
{
  "rejection_reason": "Sale was made more than 30 days ago. Refund policy does not apply."
}
```

**Validation Rules:**
- `rejection_reason`: required, string, min:10, max:1000

**Response (200):**
```json
{
  "message": "Refund request rejected",
  "refund_request": { /* updated refund request */ }
}
```

**Notes:**
- Requires 'approve refund' permission
- Cannot reject own requests
- Only pending requests can be rejected

---

## 19. Quick Sales

### 19.1 List Quick Sales
**Endpoint:** `GET /api/quick-sales`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Query Parameters:**
- `status`: string - pending, approved, rejected, active, ended
- `branch_id`: integer
- `product_id`: integer

**Response (200):**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "product": {
        "id": 1,
        "name": "Product Name",
        "sku": "PROD001"
      },
      "branch": {
        "id": 1,
        "name": "Main Branch"
      },
      "reason": "Product nearing expiry date, need to clear stock",
      "expiry_date": "2024-02-01T23:59:59.000000Z",
      "discount_type": "percentage",
      "discount_value": 20.00,
      "start_time": "2024-01-15T00:00:00.000000Z",
      "end_time": "2024-01-31T23:59:59.000000Z",
      "status": "active",
      "requested_by": {
        "id": 1,
        "name": "User Name"
      },
      "approved_by": {
        "id": 2,
        "name": "Manager Name"
      },
      "ended_by": null,
      "requested_at": "2024-01-14T10:00:00.000000Z",
      "approved_at": "2024-01-14T15:00:00.000000Z",
      "ended_at": null
    }
  ],
  "per_page": 15,
  "total": 10
}
```

**Notes:**
- Requires 'request quick sale' or 'approve quick sale' permission
- Users with only 'request' permission see their own requests
- Filtered by accessible branches

### 19.2 Create Quick Sale Request
**Endpoint:** `POST /api/quick-sales`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Request Body:**
```json
{
  "product_id": 1,
  "branch_id": 1,
  "reason": "Product approaching expiry date in 30 days. Need to reduce stock quickly.",
  "expiry_date": "2024-02-01"
}
```

**Validation Rules:**
- `product_id`: required, exists:products,id
- `branch_id`: required, exists:branches,id
- `reason`: required, string, min:10, max:1000
- `expiry_date`: required, date, after:today

**Response (201):**
```json
{
  "message": "Quick sale request submitted successfully",
  "quick_sale": { /* quick sale object */ }
}
```

**Notes:**
- Requires 'request quick sale' permission
- Product must exist in the branch with stock
- Cannot create duplicate pending requests for same product/branch

### 19.3 Get Quick Sale Details
**Endpoint:** `GET /api/quick-sales/{id}`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Response (200):**
```json
{
  "id": 1,
  "product": {
    "id": 1,
    "name": "Product Name",
    "sku": "PROD001",
    "branch_products": [
      {
        "stock_quantity": 50,
        "selling_price": 100.00
      }
    ]
  },
  "branch": { /* branch object */ },
  /* ... all quick sale fields ... */
}
```

### 19.4 Approve Quick Sale
**Endpoint:** `POST /api/quick-sales/{id}/approve`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Request Body:**
```json
{
  "discount_type": "percentage",
  "discount_value": 20,
  "start_time": "2024-01-15T00:00:00Z",
  "end_time": "2024-01-31T23:59:59Z"
}
```

**Validation Rules:**
- `discount_type`: required, in:percentage,fixed
- `discount_value`: required, numeric, min:0 (max:100 for percentage)
- `start_time`: required, date, after_or_equal:now
- `end_time`: required, date, after:start_time

**Response (200):**
```json
{
  "message": "Quick sale approved successfully",
  "quick_sale": { /* updated quick sale object */ }
}
```

**Notes:**
- Requires 'approve quick sale' permission
- Cannot approve own requests
- Only pending requests can be approved
- Validates no overlapping quick sales for same product/branch
- For fixed discount, validates it's less than product price
- Auto-activates if start_time is now or past

### 19.5 Reject Quick Sale
**Endpoint:** `POST /api/quick-sales/{id}/reject`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Request Body:**
```json
{
  "rejection_reason": "Discount amount too high. Product still has sufficient shelf life."
}
```

**Validation Rules:**
- `rejection_reason`: required, string, min:10, max:1000

**Response (200):**
```json
{
  "message": "Quick sale request rejected",
  "quick_sale": { /* updated quick sale object */ }
}
```

**Notes:**
- Requires 'approve quick sale' permission
- Cannot reject own requests
- Only pending requests can be rejected

### 19.6 End Quick Sale
**Endpoint:** `POST /api/quick-sales/{id}/end`

**Headers:** `Authorization: Bearer {token}`

**Headers (Additional):**
- `X-Business-Id` or query param `current_business_id`: required

**Response (200):**
```json
{
  "message": "Quick sale ended successfully",
  "quick_sale": { /* updated quick sale object */ }
}
```

**Notes:**
- Requires 'approve quick sale' permission
- Only active or approved quick sales can be ended
- Ends the quick sale before scheduled end time

---

## 20. Sync Operations

### 20.1 Register Device
**Endpoint:** `POST /api/sync/register-device`

**Headers:** `Authorization: Bearer {token}`

**Request Body:**
```json
{
  "device_id": "DEVICE-12345",
  "device_name": "POS Terminal 1",
  "device_type": "desktop",
  "os": "Windows 11",
  "app_version": "1.0.0",
  "branch_id": 1,
  "business_id": 1,
  "capabilities": {
    "offline_mode": true,
    "barcode_scanner": true
  }
}
```

**Validation Rules:**
- `device_id`: required, string, max:50, unique:device_registrations,device_id
- `device_name`: required, string, max:100
- `device_type`: required, in:web,desktop,mobile,tablet
- `os`: nullable, string, max:50
- `app_version`: nullable, string, max:20
- `branch_id`: nullable, exists:branches,id
- `business_id`: nullable, exists:businesses,id
- `capabilities`: nullable, array

**Response (201):**
```json
{
  "device": {
    "id": 1,
    "device_id": "DEVICE-12345",
    "device_name": "POS Terminal 1",
    "device_type": "desktop",
    "status": "active",
    "last_seen_at": "2024-01-01T12:00:00.000000Z"
  },
  "sync_token": "1|abcdefgh..."
}
```

### 20.2 Bootstrap - Initial Data Download
**Endpoint:** `POST /api/sync/bootstrap`

**Headers:** 
- `Authorization: Bearer {token}`
- `X-Business-Id: {business_id}`

**Request Body:**
```json
{
  "branch_id": 1,
  "business_id": 1,
  "entities": ["products", "categories", "payment_methods", "customers", "branch_products"],
  "include_history": false
}
```

**Validation Rules:**
- `branch_id`: required, exists:branches,id
- `business_id`: nullable, exists:businesses,id
- `entities`: nullable, array
- `entities.*`: in:products,categories,payment_methods,customers,branch_products
- `include_history`: nullable, boolean

**Response (200):**
```json
{
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "server_timestamp": "2024-01-01T12:00:00.000000Z",
  "data": {
    "products": [ /* array of products */ ],
    "categories": [ /* array of categories */ ],
    "payment_methods": [ /* array of payment methods */ ],
    "customers": [ /* array of customers */ ],
    "branch_products": [ /* array of branch products */ ]
  },
  "metadata": {
    "total_records": 1250,
    "checksum": "abc123def456",
    "estimated_size_kb": 512.75
  }
}
```

### 20.3 Pull Changes from Server
**Endpoint:** `POST /api/sync/pull`

**Headers:** 
- `Authorization: Bearer {token}`
- `X-Business-Id: {business_id}`
- `X-Device-Id: {device_id}`

**Request Body:**
```json
{
  "last_sync_at": "2024-01-01T00:00:00.000000Z",
  "business_id": 1,
  "entities": ["products", "customers", "branch_products"],
  "limit": 500
}
```

**Validation Rules:**
- `last_sync_at`: required, date
- `business_id`: nullable, exists:businesses,id
- `entities`: nullable, array
- `limit`: nullable, integer, min:1, max:1000

**Response (200):**
```json
{
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "server_timestamp": "2024-01-01T12:00:00.000000Z",
  "changes": {
    "products": {
      "created": [ /* new products */ ],
      "updated": [ /* modified products */ ],
      "deleted": [ /* deleted product IDs */ ]
    },
    "customers": {
      "created": [],
      "updated": [],
      "deleted": []
    }
  },
  "has_more": false,
  "next_cursor": null
}
```

### 20.4 Push Changes to Server
**Endpoint:** `POST /api/sync/push`

**Headers:** 
- `Authorization: Bearer {token}`
- `X-Business-Id: {business_id}`
- `X-Device-Id: {device_id}`

**Request Body:**
```json
{
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "batch_id": "batch-001",
  "business_id": 1,
  "changes": {
    "sales": [
      {
        "client_uuid": "client-sale-001",
        "branch_id": 1,
        "customer_id": 1,
        "sale_date": "2024-01-01T12:00:00.000000Z",
        "total_amount": 103.08,
        "items": [ /* sale items */ ],
        "payments": [ /* payments */ ]
      }
    ],
    "customers": [
      {
        "client_uuid": "client-customer-001",
        "name": "New Customer",
        "email": "new@example.com"
      }
    ]
  }
}
```

**Validation Rules:**
- `session_id`: required, uuid
- `batch_id`: nullable, string
- `business_id`: nullable, exists:businesses,id
- `changes`: required, array

**Response (200 or 207):**
```json
{
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "completed",
  "results": {
    "sales": {
      "accepted": 5,
      "rejected": 0,
      "conflicts": 0
    },
    "customers": {
      "accepted": 2,
      "rejected": 0,
      "conflicts": 0
    }
  },
  "server_timestamp": "2024-01-01T12:00:00.000000Z"
}
```

**Notes:**
- Returns 207 (Multi-Status) if there are conflicts
- Status can be: completed, partial, failed

### 20.5 Sync Status
**Endpoint:** `GET /api/sync/status`

**Headers:** 
- `Authorization: Bearer {token}`
- `X-Device-Id: {device_id}`

**Response (200):**
```json
{
  "device": {
    "device_id": "DEVICE-12345",
    "status": "active",
    "last_sync_at": "2024-01-01T12:00:00.000000Z",
    "total_syncs": 150
  },
  "pending_changes": {
    "server_to_client": 25,
    "conflicts": 0
  },
  "last_session": {
    "session_id": "550e8400-e29b-41d4-a716-446655440000",
    "status": "completed",
    "completed_at": "2024-01-01T12:00:00.000000Z"
  },
  "server_timestamp": "2024-01-01T12:05:00.000000Z"
}
```

### 20.6 Device Heartbeat
**Endpoint:** `POST /api/sync/heartbeat`

**Headers:** 
- `Authorization: Bearer {token}`
- `X-Device-Id: {device_id}`

**Response (200):**
```json
{
  "status": "ok",
  "server_timestamp": "2024-01-01T12:00:00.000000Z",
  "has_pending_changes": true,
  "should_sync": true,
  "messages": []
}
```

**Notes:**
- Updates device last_seen_at
- Indicates if sync is needed

---

## 21. Server Sync (Server-to-Server)

### 21.1 Push to Cloud
**Endpoint:** `POST /api/server-sync/push-to-cloud`

**Headers:** 
- `Authorization: Bearer {token}`

**Request Body:**
```json
{
  "entities": ["sales", "customers"]
}
```

**Validation Rules:**
- `entities`: array
- `entities.*`: in:sales,customers,products,categories,branch_products

**Response (200):**
```json
{
  "status": "success",
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "result": {
    "accepted": 50,
    "rejected": 0
  },
  "synced_at": "2024-01-01T12:00:00.000000Z"
}
```

**Notes:**
- For local/edge servers to push to cloud
- Requires server authentication

### 21.2 Pull from Cloud
**Endpoint:** `POST /api/server-sync/pull-from-cloud`

**Headers:** 
- `Authorization: Bearer {token}`

**Request Body:**
```json
{
  "entities": ["products", "categories", "customers", "branch_products"]
}
```

**Validation Rules:**
- `entities`: array
- `entities.*`: in:sales,customers,products,categories,branch_products,payment_methods

**Response (200):**
```json
{
  "status": "success",
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "changes_applied": 75,
  "synced_at": "2024-01-01T12:00:00.000000Z"
}
```

### 21.3 Receive from Edge
**Endpoint:** `POST /api/server-sync/receive`

**Headers:** 
- `Authorization: Bearer {token}`
- `X-Server-Id: {server_id}`
- `X-Business-Id: {business_id}`

**Request Body:**
```json
{
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "server_id": "edge-server-001",
  "changes": {
    "sales": [ /* sales data */ ],
    "customers": [ /* customers data */ ]
  },
  "timestamp": "2024-01-01T12:00:00.000000Z"
}
```

**Validation Rules:**
- `session_id`: required, uuid
- `server_id`: required, string
- `changes`: required, array
- `timestamp`: required, date

**Response (200):**
```json
{
  "status": "success",
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "accepted": 50,
  "rejected": 0,
  "conflicts": []
}
```

### 21.4 Provide Changes to Edge
**Endpoint:** `POST /api/server-sync/provide-changes`

**Headers:** 
- `Authorization: Bearer {token}`
- `X-Server-Id: {server_id}`
- `X-Business-Id: {business_id}`

**Request Body:**
```json
{
  "server_id": "edge-server-001",
  "last_sync_at": "2024-01-01T00:00:00.000000Z",
  "entities": ["products", "categories", "customers"]
}
```

**Validation Rules:**
- `server_id`: required, string
- `last_sync_at`: required, date
- `entities`: array
- `entities.*`: in:sales,customers,products,categories,branch_products,payment_methods

**Response (200):**
```json
{
  "changes": {
    "products": {
      "created": [],
      "updated": [],
      "deleted": []
    },
    "categories": {
      "created": [],
      "updated": [],
      "deleted": []
    }
  },
  "total_changes": 25,
  "server_timestamp": "2024-01-01T12:00:00.000000Z"
}
```

### 21.5 Server Sync Status
**Endpoint:** `GET /api/server-sync/status`

**Headers:** 
- `Authorization: Bearer {token}`

**Response (200):**
```json
{
  "server_id": "edge-server-001",
  "mode": "edge",
  "cloud_status": "connected",
  "last_push": "2024-01-01T11:00:00.000000Z",
  "last_pull": "2024-01-01T11:30:00.000000Z",
  "recent_sessions": [ /* last 10 sessions */ ],
  "pending_changes": 15
}
```

### 21.6 Server Health Check
**Endpoint:** `GET /api/server-sync/health`

**Response (200):**
```json
{
  "status": "healthy",
  "server_id": "edge-server-001",
  "mode": "edge",
  "timestamp": "2024-01-01T12:00:00.000000Z"
}
```

---

## Common Response Codes

- `200 OK` - Successful request
- `201 Created` - Resource successfully created
- `207 Multi-Status` - Partial success (some conflicts)
- `400 Bad Request` - Invalid request data
- `401 Unauthorized` - Missing or invalid authentication
- `403 Forbidden` - Insufficient permissions
- `404 Not Found` - Resource not found
- `422 Unprocessable Entity` - Validation errors
- `500 Internal Server Error` - Server error

## Common Error Response Format

```json
{
  "message": "Validation error",
  "errors": {
    "field_name": [
      "Error message for this field"
    ]
  }
}
```

Or for simple errors:

```json
{
  "message": "Error description"
}
```

---

## Postman Environment Variables

Recommended environment variables for your Postman collection:

```
base_url: http://localhost:8000/api
auth_token: (set after login)
business_id: (set after creating/selecting business)
branch_id: (set after creating/selecting branch)
device_id: (unique identifier for device)
```

---

## Testing Flow

1. **Authentication**: Register/Login → Get token
2. **Business Setup**: Create business → Set business_id
3. **Branch Setup**: Create branch → Set branch_id
4. **Users & Roles**: Add users, create roles, assign permissions
5. **Product Setup**: Create categories → Create products → Add to branches
6. **Customers**: Create customer records
7. **Payment Methods**: Set up payment methods
8. **Sales Flow**: Open shift → Create sales → Add payments → Close shift
9. **Inventory**: Record purchases, adjustments, transfers
10. **Analytics**: View reports and analytics
11. **Sync**: Register device → Bootstrap → Pull/Push changes

---

## Notes

- All timestamps are in ISO 8601 format (UTC)
- All monetary values are decimals with 2 decimal places
- UUIDs are used for distributed data synchronization
- Business context (`X-Business-Id`) is required for most endpoints
- Permission checks are enforced on all protected endpoints
- Branch access is enforced where applicable
- Pagination is used for list endpoints (default: 15 items per page)

---

This documentation provides complete information for creating a comprehensive Postman collection for the POS backend API. Each endpoint includes validation rules, request/response structures, and important notes for proper usage.
