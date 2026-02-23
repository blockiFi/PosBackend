# POS Backend API Documentation

## Table of Contents

1. [Overview](#overview)
2. [Authentication](#authentication)
3. [Global Concepts](#global-concepts)
4. [API Modules](#api-modules)
   - [Authentication Module](#authentication-module)
   - [Business Module](#business-module)
   - [Branch Module](#branch-module)
   - [User Management Module](#user-management-module)
   - [Roles & Permissions Module](#roles--permissions-module)
   - [Product Category Module](#product-category-module)
   - [Product Module](#product-module)
   - [Branch Product Module](#branch-product-module)
   - [Inventory Module](#inventory-module)
   - [Batch Management Module](#batch-management-module)
   - [Customer Module](#customer-module)
   - [Payment Method Module](#payment-method-module)
   - [Sales Module](#sales-module)
   - [Sales Shift Module](#sales-shift-module)
   - [Quick Sale Module](#quick-sale-module)
   - [Refund Request Module](#refund-request-module)
   - [Stock Transfer Module](#stock-transfer-module)
   - [Shelf/Store Move Requests](#shelfstore-move-requests)
   - [Stock Write-off Module](#stock-write-off-module)
   - [Analytics Module](#analytics-module)
   - [Offline Synchronization Module](#offline-synchronization-module)
   - [Server-to-Server Synchronization Module](#server-to-server-synchronization-module)
5. [Error Handling](#error-handling)
6. [Appendix](#appendix)
   - [G. Complete API Route Reference](#g-complete-api-route-reference)

---

## Overview

**Base URL:** `http://your-domain.com/api`

**API Version:** v1

**Authentication:** Laravel Sanctum (Bearer Token)

**Content Type:** `application/json`

**Multi-Tenancy:** Business-scoped via `X-Business-Id` header or query parameter

### Key Features
- RESTful API design
- Business-level multi-tenancy
- Permission-based access control (RBAC)
- FEFO (First Expiry First Out) inventory system
- Shift-based cashier accountability
- Workflow approvals (refunds, quick sales, stock transfers)
- Comprehensive analytics

---

## Authentication

### Authentication Flow

All protected endpoints require a Bearer token obtained from login.

```http
Authorization: Bearer {token}
```

### Public Endpoints

#### 1. Register User

**POST** `/register`

Creates a new user account. Accepts JSON or multipart/form-data. Optional profile image: send as multipart with `profile_image` (image file, max 2MB).

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `name` | string | ✅ Yes | ❌ No | max:255 | User's full name |
| `email` | string | ✅ Yes | ❌ No | email, unique:users, max:255 | Valid email address |
| `password` | string | ✅ Yes | ❌ No | min:8, confirmed | Minimum 8 characters |
| `password_confirmation` | string | ✅ Yes | ❌ No | must match password | Password confirmation |
| `profile_image` | file | ❌ No | ✅ Yes | image, max:2048 | Optional profile photo (multipart) |

**Request Example (JSON):**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "SecurePassword123",
  "password_confirmation": "SecurePassword123"
}
```

**Response:** `201 Created`
```json
{
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "profile_image": "profile_images/abc123.jpg",
    "profile_image_url": "http://your-domain.com/storage/profile_images/abc123.jpg"
  },
  "token": "1|laravel_sanctum_token_here",
  "token_type": "Bearer"
}
```

---

#### 2. Login

**POST** `/login`

Authenticates user and returns access token.

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `email` | string | ✅ Yes | ❌ No | email, exists:users,email | Registered email address |
| `password` | string | ✅ Yes | ❌ No | min:8 | User's password |

**Request Example:**
```json
{
  "email": "john@example.com",
  "password": "SecurePassword123"
}
```

**Response:** `200 OK`
```json
{
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "has_pin": false,
    "businesses": [
      {
        "id": 1,
        "name": "Acme Store",
        "type": "retail"
      }
    ]
  },
  "token": "1|laravel_sanctum_token_here"
}
```

**Error Response:** `401 Unauthorized`
```json
{
  "message": "Invalid credentials"
}
```

---

#### 3. PIN Login

**POST** `/pin-login`

**Public.** Fast login with 6-digit PIN. User must have a PIN set and `use-pin-login` permission.

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `pin_code` | string | ✅ Yes | ❌ No | size:6, regex:/^[0-9]{6}$/ | Exactly 6 digits |

**Request Example:**
```json
{
  "pin_code": "123456"
}
```

**Response:** `200 OK`
```json
{
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "businesses": []
  },
  "token": "1|laravel_sanctum_token_here",
  "token_type": "Bearer"
}
```

---

### Protected Authentication Endpoints

#### 4. Get Current User

**GET** `/user`

Returns the authenticated user (includes `profile_image`, `profile_image_url`). Requires Bearer token.

**Headers:**
```
Authorization: Bearer {token}
```

**Response:** `200 OK` — User model JSON.

---

#### 5. Update Profile

**PUT** `/user`

Update the authenticated user's profile. Optional: `name`, `profile_image` (multipart file, image, max 2MB). When uploading a new profile image, the previous one is replaced.

**Headers:**
```
Authorization: Bearer {token}
```

**Request:** JSON and/or multipart. For profile image use `multipart/form-data` with `profile_image` file.

| Field | Type | Required | Validation | Description |
|-------|------|----------|------------|-------------|
| name | string | No | max:255 | Display name |
| profile_image | file | No | image, max:2048 | Profile photo |

**Response:** `200 OK` — Updated user object with `profile_image`, `profile_image_url`.

---

#### 6. Set PIN

**POST** `/pin/set`

Set or update a user's PIN. If setting your own PIN, `password` is required. If setting another user's PIN, requires `manage-pin-codes` permission.

**Headers:**
```
Authorization: Bearer {token}
```

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `user_id` | integer | ✅ Yes | ❌ No | exists:users,id | Target user ID |
| `pin_code` | string | ✅ Yes | ❌ No | size:6, regex:/^[0-9]{6}$/ | Exactly 6 digits |
| `password` | string | Conditional | ❌ No | required when setting own PIN | Current password (when user_id = self) |

**Request Example (own PIN):**
```json
{
  "user_id": 1,
  "pin_code": "123456",
  "password": "password123"
}
```

**Response:** `200 OK`
```json
{
  "message": "PIN code set successfully"
}
```

---

#### 7. Remove PIN

**POST** `/pin/remove`

Remove PIN from a user. Requires `manage-pin-codes` permission (or own PIN with password).

**Headers:**
```
Authorization: Bearer {token}
```

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `user_id` | integer | ✅ Yes | ❌ No | exists:users,id | Target user ID |
| `password` | string | Conditional | ❌ No | required when removing own PIN | Current password (when user_id = self) |

**Response:** `200 OK`
```json
{
  "message": "PIN removed successfully"
}
```

---

#### 8. Get Business Details With Branch Authorization

**POST** `/business-details-with-branch-auth`

Returns business and branch when the provided branch authorization code is valid and not expired. The business is derived from the code; no business_id is required. Used to resolve branch context from a short-lived code (e.g. displayed at a branch).

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `auth_code` | string | ✅ Yes | Branch authorization code (e.g. 6-digit). Business and branch are resolved from this code. |

**Response:** `200 OK`
```json
{
  "message": "Business details with branch authorization",
  "business": { "id": 1, "name": "Acme", ... },
  "branch": { "id": 1, "name": "Main Branch", ... }
}
```

**Errors:** `422` validation (auth_code required); `401` invalid or expired auth code.

---

## Global Concepts

### Business Context

Most endpoints require a business context. Provide it via header:

```http
X-Business-Id: 1
```

Or query parameter:
```
?business_id=1
```

### Pagination

List endpoints return paginated results:

**Query Parameters:**
- `page` - Page number (default: 1)
- `per_page` - Items per page (default: 15, max: 100)

**Response Structure:**
```json
{
  "data": [...],
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "per_page": 15,
    "to": 15,
    "total": 73
  },
  "links": {
    "first": "http://api.com/endpoint?page=1",
    "last": "http://api.com/endpoint?page=5",
    "prev": null,
    "next": "http://api.com/endpoint?page=2"
  }
}
```

### Filtering & Search

Many list endpoints support filtering:

**Common Query Parameters:**
- `search` - Search across relevant fields
- `status` - Filter by status
- `branch_id` - Filter by branch
- `start_date` - Date range start (Y-m-d)
- `end_date` - Date range end (Y-m-d)

### Permissions

Endpoints are protected by permissions. Common permissions:

- `view business`, `edit business`, `delete business`
- `view branch`, `create branch`, `edit branch`, `delete branch`
- `view products`, `create products`, `edit products`, `delete products`
- `view sales`, `create sales`, `cancel sales`
- `view all shifts`, `view user shift`, `create shift`, `close shift`
- `approve quick sale`, `approve refund`, `approve stock transfer`
- `view analytics`, `view all branches`
- `use-pin-login`, `manage-pin-codes`

---

## API Modules

## Authentication Module

All authentication endpoints covered in [Authentication](#authentication) section above.

---

## Business Module

Manage business organizations (multi-tenant).

### 1. List Businesses

**GET** `/businesses`

Returns businesses the authenticated user belongs to.

**Headers:**
```
Authorization: Bearer {token}
```

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 1,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "name": "Acme Store",
      "slug": "acme-store-xyz123",
      "legal_name": "Acme Store LLC",
      "currency": "USD",
      "time_zone": "America/New_York",
      "email": "contact@acme.com",
      "phone": "+1234567890",
      "address": "123 Main St",
      "tax_registration_number": "TAX-12345",
      "default_tax_rate": 15.00,
      "branch_id": 1,
      "is_active": true,
      "branches": [
        {
          "id": 1,
          "uuid": "650e8400-e29b-41d4-a716-446655440001",
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

---

### 2. Create Business

**POST** `/businesses`

**Headers:**
```
Authorization: Bearer {token}
```

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `name` | string | ✅ Yes | ❌ No | max:255 | Business name |
| `legal_name` | string | ❌ No | ✅ Yes | max:255 | Legal registered name |
| `slug` | string | ❌ No | ✅ Yes | unique:businesses,slug | URL-friendly identifier (auto-generated from name if omitted) |
| `email` | string | ❌ No | ✅ Yes | email, max:255 | Contact email |
| `phone` | string | ❌ No | ✅ Yes | max:50 | Contact phone number |
| `address` | string | ❌ No | ✅ Yes | - | Physical address |
| `city` | string | ❌ No | ✅ Yes | max:150 | City |
| `state` | string | ❌ No | ✅ Yes | max:150 | State/Province |
| `postal_code` | string | ❌ No | ✅ Yes | max:50 | Postal/ZIP code |
| `country` | string | ❌ No | ✅ Yes | size:2 | Country code (e.g., 'US') |
| `currency` | string | ❌ No | ✅ Yes | size:3 | Currency code (default: USD) |
| `time_zone` | string | ❌ No | ✅ Yes | max:100 | Timezone (e.g., 'America/New_York') |
| `tax_registration_number` | string | ❌ No | ✅ Yes | max:150 | Tax ID/VAT number |
| `default_tax_rate` | decimal | ❌ No | ✅ Yes | numeric, min:0, max:100 | Default tax rate (percentage) |
| `settings` | object | ❌ No | ✅ Yes | - | Additional settings |
| `main_branch_name` | string | ❌ No | ✅ Yes | max:255 | Name for auto-created main branch (default: Main Branch) |
| `main_branch_code` | string | ❌ No | ✅ Yes | max:32 | Code for auto-created main branch (default: MAIN) |

**Request Example:**
```json
{
  "name": "New Retail Store",
  "legal_name": "New Retail Store LLC",
  "slug": "new-retail-store",
  "email": "info@newstore.com",
  "phone": "+1234567890",
  "address": "456 Commerce Ave",
  "city": "New York",
  "state": "NY",
  "postal_code": "10001",
  "country": "US",
  "currency": "USD",
  "time_zone": "America/New_York",
  "tax_registration_number": "TAX-67890",
  "default_tax_rate": 15.00,
  "settings": {},
  "main_branch_name": "Headquarters",
  "main_branch_code": "HQ"
}
```

**Response:** `201 Created`
```json
{
  "data": {
    "id": 2,
    "name": "New Retail Store",
    "type": "retail",
    "email": "info@newstore.com",
    "phone": "+1234567890",
    "address": "456 Commerce Ave",
    "settings": {
      "tax_rate": 0.15,
      "currency": "USD"
    },
    "created_at": "2026-02-08T10:00:00.000000Z"
  }
}
```

---

### 3. Get Business Details

**GET** `/businesses/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {id}
```

**Response:** `200 OK`
```json
{
  "data": {
    "id": 1,
    "name": "Acme Store",
    "type": "retail",
    "email": "contact@acme.com",
    "phone": "+1234567890",
    "address": "123 Main St",
    "settings": {
      "tax_rate": 0.15,
      "currency": "USD"
    },
    "branches": [
      {
        "id": 1,
        "name": "Main Branch",
        "address": "123 Main St"
      }
    ],
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

---

### 4. Update Business

**PUT** `/businesses/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {id}
```

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `name` | string | ❌ No | ❌ No | max:255 | Business name |
| `legal_name` | string | ❌ No | ✅ Yes | max:255 | Legal registered name |
| `email` | string | ❌ No | ❌ No | email | Contact email |
| `phone` | string | ❌ No | ✅ Yes | max:20 | Contact phone |
| `address` | string | ❌ No | ✅ Yes | max:500 | Physical address |
| `city` | string | ❌ No | ✅ Yes | max:100 | City |
| `state` | string | ❌ No | ✅ Yes | max:100 | State/Province |
| `postal_code` | string | ❌ No | ✅ Yes | max:20 | Postal code |
| `country` | string | ❌ No | ✅ Yes | max:2 | Country code |
| `currency` | string | ❌ No | ❌ No | size:3 | Currency code |
| `time_zone` | string | ❌ No | ❌ No | valid timezone | Timezone |
| `tax_registration_number` | string | ❌ No | ✅ Yes | max:50 | Tax ID |
| `default_tax_rate` | decimal | ❌ No | ✅ Yes | numeric, min:0, max:100 | Default tax rate |
| `settings` | object | ❌ No | ✅ Yes | json | Additional settings |
| `is_active` | boolean | ❌ No | ❌ No | boolean | Active status |

**Request Example:**
```json
{
  "name": "Acme Store Updated",
  "email": "new@acme.com",
  "default_tax_rate": 18.00,
  "settings": {
    "tax_rate": 0.18
  }
}
```

**Response:** `200 OK`

---

### 5. Delete Business

**DELETE** `/businesses/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {id}
```

**Response:** `204 No Content`

---

## Branch Module

Manage business locations.

### 1. List Branches

**GET** `/branches`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `search` - Search by name, address
- `is_active` - Filter active/inactive (true/false)

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 1,
      "uuid": "650e8400-e29b-41d4-a716-446655440001",
      "name": "Main Branch",
      "code": "MAIN",
      "is_main": true,
      "email": "main@acme.com",
      "phone": "+1234567890",
      "address": "123 Main St",
      "city": "New York",
      "state": "NY",
      "postal_code": "10001",
      "country": "US",
      "time_zone": "America/New_York",
      "tax_rate": 15.00,
      "settings": null,
      "is_active": true
    }
  ]
}
```

---

### 2. Create Branch

**POST** `/branches`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** Business Owner Only (only business owners can create branches)

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `name` | string | ✅ Yes | ❌ No | max:255 | Branch name |
| `code` | string | ❌ No | ✅ Yes | max:50, unique per business | Branch code/identifier |
| `email` | string | ❌ No | ✅ Yes | email, max:255 | Branch email |
| `phone` | string | ❌ No | ✅ Yes | max:20 | Branch phone |
| `address` | string | ❌ No | ✅ Yes | max:500 | Physical address |
| `city` | string | ❌ No | ✅ Yes | max:100 | City |
| `state` | string | ❌ No | ✅ Yes | max:100 | State/Province |
| `postal_code` | string | ❌ No | ✅ Yes | max:20 | Postal code |
| `country` | string | ❌ No | ✅ Yes | max:2 | Country code |
| `time_zone` | string | ❌ No | ✅ Yes | valid timezone | Timezone |
| `tax_rate` | decimal | ❌ No | ✅ Yes | numeric, min:0, max:100 | Branch-specific tax rate |
| `is_main` | boolean | ❌ No | ❌ No | boolean | Is main branch (default: false) |
| `is_active` | boolean | ❌ No | ❌ No | boolean | Active status (default: true) |
| `settings` | object | ❌ No | ✅ Yes | json | Additional settings |

**Request Example:**
```json
{
  "name": "Downtown Branch",
  "code": "DOWN",
  "email": "downtown@acme.com",
  "phone": "+1987654321",
  "address": "789 Downtown Ave",
  "city": "New York",
  "state": "NY",
  "postal_code": "10002",
  "country": "US",
  "time_zone": "America/New_York",
  "tax_rate": 15.00,
  "is_main": false,
  "is_active": true
}
```

**Response:** `201 Created`

---

### 3. Get Branch Details

**GET** `/branches/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`

---

### 4. Update Branch

**PUT** `/branches/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `edit_branch`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `name` | string | ❌ No | ❌ No | max:255 | Branch name |
| `code` | string | ❌ No | ✅ Yes | max:50 | Branch code |
| `email` | string | ❌ No | ✅ Yes | email | Branch email |
| `phone` | string | ❌ No | ✅ Yes | max:20 | Branch phone |
| `address` | string | ❌ No | ✅ Yes | max:500 | Address |
| `city` | string | ❌ No | ✅ Yes | max:100 | City |
| `state` | string | ❌ No | ✅ Yes | max:100 | State |
| `postal_code` | string | ❌ No | ✅ Yes | max:20 | Postal code |
| `country` | string | ❌ No | ✅ Yes | max:2 | Country code |
| `time_zone` | string | ❌ No | ✅ Yes | valid timezone | Timezone |
| `tax_rate` | decimal | ❌ No | ✅ Yes | numeric, min:0, max:100 | Tax rate |
| `is_active` | boolean | ❌ No | ❌ No | boolean | Active status |
| `settings` | object | ❌ No | ✅ Yes | json | Settings |

**Request Example:**
```json
{
  "name": "Downtown Branch - Updated",
  "is_active": true,
  "tax_rate": 16.50
}
```

**Response:** `200 OK`

---

### 5. Delete Branch

**DELETE** `/branches/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `delete_branch`

**Response:** `204 No Content`

---

### 6. Generate Branch Authorization Codes

**POST** `/branches/generate-auth-codes`

Generates (or reuses) a short-lived authorization code for **every branch the user has permission to access** in the current business. No request body; uses `X-Business-Id`. Codes expire in 2 minutes. If a branch already has a non-expired code, it is returned unchanged; if expired or missing, a new code is generated.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage-branches` or business owner.

**Response:** `200 OK`
```json
{
  "message": "Authorization codes generated",
  "authorizations": [
    {
      "branch_id": 1,
      "branch_name": "Main Branch",
      "auth_code": "847291",
      "expires_at": "2026-02-22T14:32:00.000000Z"
    }
  ],
  "count": 2,
  "expires_in_minutes": 2
}
```

Use these codes with **Get Business Details With Branch Authorization** (`POST /business-details-with-branch-auth`) to resolve business and branch by code.

---

## User Management Module

Manage users within a business.

### 1. List Business Users

**GET** `/business-users`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `branch_id` | integer | No | Filter to users who have a role in this branch (or business-wide). Branch must belong to the business; requester must have access to the branch. |

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com",
      "is_active": true,
      "joined_at": "2026-01-01T00:00:00.000000Z",
      "roles": [
        { "id": 1, "name": "Cashier" }
      ]
    }
  ]
}
```

---

### 2. Add User to Business

**POST** `/business-users`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** Owner only (only business owners can add users).

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| email | string | ✅ Yes | ❌ No | email | Email (existing user or new user to create) |
| name | string | ✅ Yes | ❌ No | max:255 | User's full name |
| is_active | boolean | ❌ No | ❌ No | boolean | Whether user is active in business (default: true) |
| role_ids | array | ❌ No | ✅ Yes | array of integers, exists:roles,id | Role IDs to assign (must belong to this business) |
| profile_image | file | ❌ No | ✅ Yes | image, max:2048 | Optional profile photo when creating a new user (multipart) |

**Request Example:**
```json
{
  "email": "newuser@example.com",
  "name": "Jane Smith",
  "is_active": true,
  "role_ids": [1, 2]
}
```

**Response:** `201 Created`

When the user is assigned the **Cashier** role and does not already have a PIN, a 6-digit PIN is automatically generated and returned in `data.pin_code`. Share this with the cashier for PIN login. When a new user is created, `data.password` is also returned (share for first login).

---

### 3. Get User Details

**GET** `/business-users/{userId}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`

---

### 4. Update User

**PUT** `/business-users/{userId}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage_users`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| branch_id | integer | ❌ No | ❌ No | exists:branches,id | New default branch assignment |
| is_active | boolean | ❌ No | ❌ No | - | Whether user is active in this business |

**Note:** All fields are optional for update. Only include fields you want to change.

**Request Example:**
```json
{
  "branch_id": 2,
  "is_active": true
}
```

**Response:** `200 OK`

---

### 5. Remove User from Business

**DELETE** `/business-users/{userId}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage_users`

**Response:** `204 No Content`

---

## Roles & Permissions Module

Manage roles and permissions (RBAC).

### 1. List All Permissions

**GET** `/permissions`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 1,
      "name": "view_product",
      "display_name": "View Products",
      "category": "product",
      "description": "Can view product listings"
    },
    {
      "id": 2,
      "name": "create_sale",
      "display_name": "Create Sales",
      "category": "sales",
      "description": "Can create new sales"
    }
  ]
}
```

---

### 2. List Roles

**GET** `/roles`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 1,
      "name": "Cashier",
      "business_id": 1,
      "guard_name": "web",
      "permissions": [
        {
          "id": 1,
          "name": "view_product"
        },
        {
          "id": 2,
          "name": "create_sale"
        }
      ],
      "users_count": 3
    }
  ]
}
```

---

### 3. Create Role

**POST** `/roles`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage-roles` (or business owner)

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| name | string | ✅ Yes | ❌ No | max:255, unique per business | Role name |
| permissions | array | ❌ No | ✅ Yes | array, exists:permissions,name,guard_name,api | Array of permission names to assign to role |

**Request Example:**
```json
{
  "name": "Store Manager",
  "permissions": ["view_product", "create_product", "view_sale", "create_sale", "close_shift"]
}
```

**Response:** `201 Created`

---

### 4. Get Role Details

**GET** `/roles/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`

---

### 5. Update Role

**PUT** `/roles/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage-roles`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| name | string | ❌ No | ❌ No | max:255, unique per business | New role name |
| permissions | array | ❌ No | ❌ No | array, exists:permissions,name,guard_name,api | New array of permission names (replaces existing permissions) |

**Note:** All fields are optional for update. Only include fields you want to change.

**Request Example:**
```json
{
  "name": "Store Manager Updated",
  "permissions": ["view_product", "create_product", "edit_product"]
}
```

**Response:** `200 OK`

---

### 6. Delete Role

**DELETE** `/roles/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage-roles`

**Response:** `204 No Content`

---

### 7. Add Permission to Role

**POST** `/roles/addpermission`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Request Schema:**

| Field | Type | Required | Validation | Description |
|-------|------|----------|------------|-------------|
| role_id | integer | ✅ Yes | exists:roles,id | Role ID (must belong to this business) |
| permission_name | array | ✅ Yes | array of strings, exists:permissions,name,guard_name,api | Permission names to add |

**Request:**
```json
{
  "role_id": 1,
  "permission_name": ["edit_product", "view_product"]
}
```

**Response:** `200 OK`

---

### 7b. Remove Permission from Role

**POST** `/roles/removepermission`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Request Schema:**

| Field | Type | Required | Validation | Description |
|-------|------|----------|------------|-------------|
| role_id | integer | ✅ Yes | exists:roles,id | Role ID (must belong to this business) |
| permission_name | array | ✅ Yes | array of strings, exists:permissions,name,guard_name,api | Permission names to remove |

**Request:**
```json
{
  "role_id": 1,
  "permission_name": ["edit_product"]
}
```

**Response:** `200 OK`

---

### 8. Assign Role to User

**POST** `/roles/assign`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage-roles`

**Request Schema:**

| Field | Type | Required | Validation | Description |
|-------|------|----------|------------|-------------|
| user_id | integer | ✅ Yes | exists:users,id | User ID (must be a member of this business) |
| role_id | integer | ✅ Yes | exists:roles,id | Role ID (must belong to this business) |
| branch_id | integer | ❌ No | exists:branches,id | Optional branch scope for this role assignment |

**Request:**
```json
{
  "user_id": 5,
  "role_id": 1,
  "branch_id": 2
}
```

**Response:** `200 OK`

---

### 9. Remove Role from User

**POST** `/roles/remove`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage-roles`

**Request Schema:**

| Field | Type | Required | Validation | Description |
|-------|------|----------|------------|-------------|
| user_id | integer | ✅ Yes | exists:users,id | User ID |
| role_id | integer | ✅ Yes | exists:roles,id | Role ID (must belong to this business) |

**Request:**
```json
{
  "user_id": 5,
  "role_id": 1
}
```

**Response:** `200 OK`

---

### 10. Get User Roles

**GET** `/users/{userId}/roles`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`
```json
{
  "data": {
    "user": {
      "id": 5,
      "name": "Jane Smith",
      "email": "jane@example.com"
    },
    "roles": [
      { "id": 1, "name": "Cashier", "permissions": ["view_product", "create_sale"] },
      { "id": 2, "name": "Inventory Manager", "permissions": ["manage_inventory"] }
    ],
    "permissions": ["view_product", "create_sale", "manage_inventory"]
  }
}
```

---

## Product Category Module

Manage hierarchical product categories.

### 1. List Categories

**GET** `/categories`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `search` - Search by name
- `parent_id` - Filter by parent category (null for root)

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 1,
      "business_id": 1,
      "name": "Electronics",
      "description": "Electronic devices",
      "parent_id": null,
      "children": [
        {
          "id": 2,
          "name": "Phones",
          "parent_id": 1
        }
      ],
      "products_count": 15
    }
  ]
}
```

---

### 2. Create Category

**POST** `/categories`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `create_category`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `name` | string | ✅ Yes | ❌ No | max:255, unique per business+parent | Category name |
| `description` | text | ❌ No | ✅ Yes | max:1000 | Category description |
| `parent_id` | integer | ❌ No | ✅ Yes | exists:categories,id | Parent category ID (for subcategories) |
| `display_order` | integer | ❌ No | ✅ Yes | integer, min:0 | Display order |
| `is_active` | boolean | ❌ No | ❌ No | boolean | Active status (default: true) |
| `image_url` | string | ❌ No | ✅ Yes | url, max:500 | Category image URL |

**Request Example:**
```json
{
  "name": "Laptops",
  "description": "Laptop computers",
  "parent_id": 1,
  "display_order": 10,
  "is_active": true
}
```

**Response:** `201 Created`

---

### 3. Get Category Details

**GET** `/categories/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`

---

### 4. Update Category

**PUT** `/categories/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `edit_category`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| name | string | ❌ No | ❌ No | max:255, unique per business+parent | Category name, must be unique within parent category |
| description | text | ❌ No | ✅ Yes | max:1000 | Category description |
| parent_id | integer | ❌ No | ✅ Yes | exists:product_categories,id | Parent category for hierarchy, null for top-level |
| display_order | integer | ❌ No | ✅ Yes | min:0 | Display order for sorting |
| is_active | boolean | ❌ No | ❌ No | - | Whether category is active and visible |
| image_url | string | ❌ No | ✅ Yes | url, max:500 | Category image URL |

**Note:** All fields are optional for update. Only include fields you want to change.

**Request Example:**
```json
{
  "name": "Updated Electronics",
  "is_active": true,
  "display_order": 5
}
```

**Response:** `200 OK`

---

### 5. Delete Category

**DELETE** `/categories/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `delete_category`

**Response:** `204 No Content`

---

### 6. Get Category Breadcrumb

**GET** `/categories/{id}/breadcrumb`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 1,
      "name": "Electronics"
    },
    {
      "id": 2,
      "name": "Computers"
    },
    {
      "id": 3,
      "name": "Laptops"
    }
  ]
}
```

---

## Product Module

Manage product catalog.

### 1. List Products

**GET** `/products`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `search` - Search by name, sku, barcode
- `category_id` - Filter by category
- `branch_id` - Filter by branch availability
- `status` - Filter by status (active/inactive)
- `low_stock` - Show low stock items (true/false)

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 1,
      "business_id": 1,
      "category_id": 2,
      "name": "iPhone 14 Pro",
      "sku": "IPH14PRO-256",
      "barcode": "1234567890123",
      "description": "Latest iPhone model",
      "base_selling_price": 999.99,
      "base_cost_price": 750.00,
      "is_taxable": true,
      "default_tax_rate": 15.00,
      "stock_tracking": "simple",
      "low_stock_threshold": 10,
      "is_active": true,
      "image_url": null,
      "category": {
        "id": 2,
        "name": "Phones"
      },
      "branches": [
        {
          "id": 1,
          "name": "Main Branch",
          "pivot": {
            "shelf_quantity": 5,
            "store_quantity": 15,
            "total_quantity": 20
          }
        }
      ],
      "created_at": "2026-01-01T00:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "total": 50
  }
}
```

---

### 2. Create Product

**POST** `/products`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `create products`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `name` | string | ✅ Yes | ❌ No | max:255 | Product name |
| `sku` | string | ✅ Yes | ❌ No | max:100, unique per business | Stock Keeping Unit |
| `barcode` | string | ❌ No | ✅ Yes | max:100, unique | Barcode/UPC |
| `category_id` | integer | ✅ Yes | ❌ No | exists:categories,id | Category ID |
| `description` | text | ❌ No | ✅ Yes | max:5000 | Product description |
| `base_selling_price` | decimal | ✅ Yes | ❌ No | numeric, min:0 | Base selling price |
| `minimum_selling_price` | decimal | ❌ No | ✅ Yes | numeric, min:0 | Minimum allowed price |
| `base_cost_price` | decimal | ✅ Yes | ❌ No | numeric, min:0 | Cost/purchase price |
| `is_taxable` | boolean | ❌ No | ❌ No | boolean | Subject to tax (default: true) |
| `default_tax_rate` | decimal | ❌ No | ✅ Yes | numeric, min:0, max:100 | Tax rate percentage |
| `unit_of_measure` | string | ❌ No | ✅ Yes | max:50 | Unit (e.g., 'piece', 'kg', 'liter') |
| `stock_tracking` | enum | ❌ No | ❌ No | in:simple,batch,none | Tracking method (default: 'simple') |
| `has_expiry` | boolean | ❌ No | ❌ No | boolean | Has expiry date (default: false) |
| `low_stock_threshold` | integer | ❌ No | ✅ Yes | integer, min:0 | Low stock alert threshold |
| `is_active` | boolean | ❌ No | ❌ No | boolean | Active status (default: true) |
| `is_available_online` | boolean | ❌ No | ❌ No | boolean | Available for online sales (default: false) |
| `image_url` | string | ❌ No | ✅ Yes | url, max:500 | Product image URL |
| `supplier_name` | string | ❌ No | ✅ Yes | max:255 | Default supplier |
| `supplier_code` | string | ❌ No | ✅ Yes | max:100 | Supplier's product code |
| `weight` | decimal | ❌ No | ✅ Yes | numeric, min:0 | Weight (in kg) |
| `dimensions` | string | ❌ No | ✅ Yes | max:100 | Dimensions (e.g., '10x5x3 cm') |
| `notes` | text | ❌ No | ✅ Yes | max:1000 | Internal notes |

**Request Example:**
```json
{
  "name": "Samsung Galaxy S23",
  "sku": "SGS23-128",
  "barcode": "9876543210123",
  "category_id": 2,
  "description": "Latest Samsung flagship",
  "base_selling_price": 899.99,
  "minimum_selling_price": 799.99,
  "base_cost_price": 650.00,
  "is_taxable": true,
  "default_tax_rate": 15.00,
  "unit_of_measure": "piece",
  "stock_tracking": "simple",
  "has_expiry": false,
  "low_stock_threshold": 5,
  "is_active": true,
  "is_available_online": false
}
```

**Response:** `201 Created`

---

### 3. Get Product Details

**GET** `/products/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`

---

### 4. Update Product

**PUT** `/products/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `edit products`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| name | string | ❌ No | ❌ No | max:255 | Product name |
| description | text | ❌ No | ✅ Yes | max:5000 | Product description |
| category_id | integer | ❌ No | ✅ Yes | exists:product_categories,id | Category assignment |
| sku | string | ❌ No | ❌ No | max:100, unique:products,sku,{business_id} | Stock Keeping Unit, must be unique per business |
| barcode | string | ❌ No | ✅ Yes | max:100, unique:products | Product barcode, globally unique |
| base_selling_price | decimal | ❌ No | ❌ No | min:0, max:999999999.99 | Base selling price |
| minimum_selling_price | decimal | ❌ No | ✅ Yes | min:0, max:999999999.99 | Minimum allowed selling price |
| base_cost_price | decimal | ❌ No | ✅ Yes | min:0, max:999999999.99 | Base cost price |
| is_taxable | boolean | ❌ No | ❌ No | - | Whether product is taxable |
| default_tax_rate | decimal | ❌ No | ✅ Yes | min:0, max:100 | Default tax rate percentage |
| stock_tracking | string | ❌ No | ❌ No | in:simple,batch,none | Stock tracking method |
| has_expiry | boolean | ❌ No | ❌ No | - | Whether product has expiry dates |
| unit_of_measure | string | ❌ No | ✅ Yes | max:50 | Unit of measure (e.g., pcs, kg, liters) |
| low_stock_threshold | integer | ❌ No | ✅ Yes | min:0 | Alert threshold for low stock |
| is_active | boolean | ❌ No | ❌ No | - | Whether product is active |
| is_available_online | boolean | ❌ No | ❌ No | - | Whether product available for online orders |
| supplier_name | string | ❌ No | ✅ Yes | max:255 | Supplier name |
| supplier_code | string | ❌ No | ✅ Yes | max:100 | Supplier product code |
| weight | decimal | ❌ No | ✅ Yes | min:0 | Product weight in kg |
| dimensions | string | ❌ No | ✅ Yes | max:100 | Product dimensions (e.g., "10x20x5 cm") |
| image_url | string | ❌ No | ✅ Yes | url, max:500 | Product image URL |
| notes | text | ❌ No | ✅ Yes | max:1000 | Internal notes |

**Note:** All fields are optional for update. Only include fields you want to change.

**Request Example:**
```json
{
  "base_selling_price": 949.99,
  "is_active": true
}
```

**Response:** `200 OK`

---

### 5. Delete Product

**DELETE** `/products/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `delete products`

**Response:** `204 No Content`

---

### 6. Add Product to Branch

**POST** `/products/{id}/branches`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```
Alternatively, business context can be sent as query: `current_business_id={business_id}`.

**Permission Required:** `manage branch products`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| branch_id | integer | ✅ Yes | ❌ No | exists:branches,id,business_id,{business_id} | Branch to add product to |
| cost_price | number | ❌ No | ✅ Yes | numeric, min:0 | Branch cost price |
| selling_price | number | ❌ No | ✅ Yes | numeric, min:0 | Branch selling price |
| compare_price | number | ❌ No | ✅ Yes | numeric, min:0 | Compare-at price |
| discount_amount | number | ❌ No | ✅ Yes | numeric, min:0 | Discount amount |
| discount_type | string | ❌ No | ✅ Yes | in:fixed,percentage | Discount type |
| tax_rate | number | ❌ No | ✅ Yes | numeric, min:0, max:100 | Tax rate |
| stock_quantity | integer | ❌ No | ❌ No | integer, min:0 | Initial stock (default: 0) |
| low_stock_threshold | integer | ❌ No | ✅ Yes | integer, min:0 | Low stock alert level |
| allow_backorder | boolean | ❌ No | ❌ No | boolean | Allow backorder (default: false) |
| reorder_point | integer | ❌ No | ✅ Yes | integer, min:0 | Reorder point |
| reorder_quantity | integer | ❌ No | ✅ Yes | integer, min:0 | Reorder quantity |
| is_available | boolean | ❌ No | ❌ No | boolean | Available at branch (default: true) |
| is_featured | boolean | ❌ No | ❌ No | boolean | Featured (default: false) |
| display_order | integer | ❌ No | ❌ No | integer | Display order (default: 0) |
| bin_location | string | ❌ No | ✅ Yes | string, max:255 | Bin location |
| shelf_location | string | ❌ No | ✅ Yes | string, max:255 | Shelf location |
| branch_meta_data | object | ❌ No | ✅ Yes | array | Branch-specific metadata |

**Request Example:**
```json
{
  "branch_id": 1,
  "selling_price": 29.99,
  "compare_price": 39.99,
  "cost_price": 15.99,
  "stock_quantity": 100,
  "discount_amount": 0,
  "is_available": true,
  "low_stock_threshold": 10
}
```

**Response:** `200 OK` with `message` and `data` (formatted product including `branch_data`).

---

### 7. Remove Product from Branch

**DELETE** `/products/{id}/branches`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```
Alternatively, business context via query: `current_business_id={business_id}`.

**Query Parameters:** `branch_id` (required), `current_business_id` (optional if using header).

**Permission Required:** `manage branch products`

**Request:** No body. Pass `branch_id` and optionally `current_business_id` as query parameters.

**Example:** `DELETE /products/1/branches?branch_id=1&current_business_id=1`

**Response:** `200 OK` with `message`: "Product removed from branch successfully". The branch product is soft-deleted.

---

### 8. Update Product Price

**PATCH** `/products/{id}/price`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `edit_product`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| base_selling_price | decimal | ❌ No | ❌ No | min:0, max:999999999.99 | New base selling price |
| minimum_selling_price | decimal | ❌ No | ✅ Yes | min:0, max:999999999.99 | New minimum allowed selling price |
| base_cost_price | decimal | ❌ No | ✅ Yes | min:0, max:999999999.99 | New base cost price |

**Note:** At least one field must be provided.

**Request Example:**
```json
{
  "base_selling_price": 899.99,
  "base_cost_price": 675.00
}
```

**Response:** `200 OK`

---

### 9. Get Products by Branch

**GET** `/branches/{branchId}/products`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```
Alternatively, business context via query: `current_business_id={business_id}`.

**Permission Required:** `view products`

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| current_business_id | integer | Business context (if not using X-Business-Id) |
| category_id | integer | Filter by product category |
| active_only | boolean | If true, only active products |
| available_only | boolean | If true, only products available at this branch |
| in_stock_only | boolean | If true, only products with stock_quantity > 0 at branch |
| search | string | Search by name, sku, barcode |
| start_id | integer | Return products with id >= start_id (cursor-style) |
| per_page | integer | Items per page (default: 15) |
| paginated | boolean | If false, return all results without pagination (default: true) |

**Response:** `200 OK`
```json
{
  "data": [ { "id", "name", "sku", "branch_data": { ... } }, ... ],
  "branch": { "id", "name", "code" },
  "meta": { "current_page", "last_page", "per_page", "total", "paginated" }
}
```

---

## Branch Product Module

Manage branch-specific product data and inventory.

### 1. List Branch Products

**GET** `/branch-products`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `branch_id` - Filter by branch (required or inferred)
- `search` - Search products
- `low_stock` - Show low stock items

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 1,
      "branch_id": 1,
      "product_id": 5,
      "shelf_quantity": 8,
      "store_quantity": 42,
      "total_quantity": 50,
      "reorder_level": 15,
      "reorder_quantity": 30,
      "is_low_stock": false,
      "product": {
        "id": 5,
        "name": "Product Name",
        "sku": "SKU123",
        "unit_price": 29.99
      }
    }
  ]
}
```

---

### 2. Create Branch Product

**POST** `/branch-products`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage_inventory`

**Request:**
```json
{
  "branch_id": 1,
  "product_id": 5,
  "shelf_quantity": 10,
  "store_quantity": 50,
  "reorder_level": 15,
  "reorder_quantity": 30
}
```

**Response:** `201 Created`

---

### 3. Get Branch Product Details

**GET** `/branch-products/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`

---

### 4. Update Branch Product

**PUT** `/branch-products/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage_inventory`

**Response:** `200 OK`

---

### 5. Delete Branch Product

**DELETE** `/branch-products/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage_inventory`

**Response:** `204 No Content`

---

### 6. Update Stock

**POST** `/branch-products/{id}/stock`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage_inventory`

**Request:**
```json
{
  "shelf_quantity": 15,
  "store_quantity": 60
}
```

**Response:** `200 OK`

---

### 7. Move to Shelf

**POST** `/branch-products/{id}/move-to-shelf`

**Direct move** (no approval). For request-and-approve workflow, use **Shelf/Store Move Requests** instead.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** Owner, or `manage inventory`, or `adjust inventory`, or `approve shelf store move`. Others must use `shelf-store-move-requests` to request a move for approval.

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| quantity | integer | ✅ Yes | ❌ No | min:1 | Quantity to move from store to shelf |

**Request Example:**
```json
{
  "quantity": 10
}
```

**Response:** `200 OK`
```json
{
  "message": "Stock moved to shelf successfully",
  "data": {
    "shelf_quantity": 18,
    "store_quantity": 32,
    "total_quantity": 50
  }
}
```

---

### 8. Move to Store

**POST** `/branch-products/{id}/move-to-store`

**Direct move** (no approval). For request-and-approve workflow, use **Shelf/Store Move Requests** instead.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** Owner, or `manage inventory`, or `adjust inventory`, or `approve shelf store move`. Others must use `shelf-store-move-requests` to request a move for approval.

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| quantity | integer | ✅ Yes | ❌ No | min:1 | Quantity to move from shelf to store |

**Request Example:**
```json
{
  "quantity": 5
}
```

**Response:** `200 OK`

---

### 9. Stock Summary

**GET** `/branch-products/summary/stock`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `branch_id` - Required

**Response:** `200 OK`
```json
{
  "data": {
    "total_products": 150,
    "in_stock": 142,
    "out_of_stock": 8,
    "low_stock": 15,
    "total_shelf_value": 45000.00,
    "total_store_value": 180000.00
  }
}
```

---

### 10. Bulk Update

**POST** `/branch-products/bulk-update`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage_inventory`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| updates | array | ✅ Yes | ❌ No | min:1 | Array of branch product updates |
| updates.*.id | integer | ✅ Yes | ❌ No | exists:branch_products,id | Branch product ID to update |
| updates.*.shelf_quantity | integer | ❌ No | ❌ No | min:0 | New shelf quantity |
| updates.*.store_quantity | integer | ❌ No | ❌ No | min:0 | New store quantity |
| updates.*.reorder_level | integer | ❌ No | ✅ Yes | min:0 | New reorder level |
| updates.*.reorder_quantity | integer | ❌ No | ✅ Yes | min:0 | New reorder quantity |

**Note:** Each update object must include `id` and at least one other field to update.

**Request Example:**
```json
{
  "updates": [
    {
      "id": 1,
      "shelf_quantity": 10
    },
    {
      "id": 2,
      "store_quantity": 50
    }
  ]
}
```

**Response:** `200 OK`

---

## Inventory Module

Manage inventory transactions and stock tracking (FEFO system). Every stock-in and stock-out transaction is tied to product batches when the product uses batch tracking: stock-out (sale, transfer_out, damage, negative adjustment) is allocated across batches using FEFO; stock-in (purchase, transfer_in, return, adjustment, refund, sale cancel) creates or updates a batch and sets `batch_id` on the transaction. Child transactions of type `batch_allocation` may be created for FEFO deductions.

### 1. List Inventory Transactions

**GET** `/inventory/transactions`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `branch_id` - Filter by branch
- `product_id` - Filter by product
- `type` - Filter by type (purchase, sale, adjustment, transfer_in, transfer_out, return, damage, initial, batch_allocation)
- `start_date` - Date range start (Y-m-d)
- `end_date` - Date range end (Y-m-d)

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 1,
      "branch_id": 1,
      "product_id": 5,
      "batch_id": 10,
      "type": "purchase",
      "quantity": 50,
      "unit_cost": 25.00,
      "reference_id": null,
      "reference_type": null,
      "notes": "Initial stock purchase",
      "created_by": 1,
      "created_at": "2026-02-01T10:00:00.000000Z",
      "product": {
        "id": 5,
        "name": "Product Name",
        "sku": "SKU123"
      },
      "batch": {
        "id": 10,
        "batch_number": "BATCH-001",
        "expiry_date": "2027-02-01"
      },
      "creator": {
        "id": 1,
        "name": "John Doe"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "total": 500
  }
}
```

---

### 2. Create Inventory Transaction

**POST** `/inventory/transactions`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage_inventory`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| branch_id | integer | ✅ Yes | ❌ No | exists:branches,id,business_id,{business_id} | Branch where transaction occurs |
| product_id | integer | ✅ Yes | ❌ No | exists:products,id,business_id,{business_id} | Product involved |
| type | string | ✅ Yes | ❌ No | in:purchase,sale,adjustment,transfer_out,transfer_in,return,damage,initial | Transaction type |
| quantity | integer | ✅ Yes | ❌ No | integer, not_in:0 | Quantity (positive = stock in, negative = stock out) |
| shelf_quantity | integer | ❌ No | ❌ No | min:0 | Shelf delta (optional) |
| store_quantity | integer | ❌ No | ❌ No | min:0 | Store delta (optional) |
| location | string | ❌ No | ✅ Yes | in:shelf,store,both | For adjustments: which location |
| unit_cost | number | ❌ No | ✅ Yes | min:0 | Cost per unit |
| reference_number | string | ❌ No | ✅ Yes | max:255 | Reference (e.g. ADJ-001) |
| related_branch_id | integer | ❌ No | ✅ Yes | exists:branches,id | For transfers: other branch |
| notes | string | ❌ No | ✅ Yes | - | Notes |
| meta_data | object | ❌ No | ✅ Yes | array | Extra metadata |
| batch_number | string | ❌ No | ✅ Yes | max:255 | For purchase: batch number |
| lot_number | string | ❌ No | ✅ Yes | max:255 | Lot number |
| manufacturing_date | date | ❌ No | ✅ Yes | date | Manufacturing date |
| expiry_date | date | ❌ No | ✅ Yes | date, after:manufacturing_date | Expiry date |
| supplier_name | string | ❌ No | ✅ Yes | max:255 | Supplier name |
| supplier_reference | string | ❌ No | ✅ Yes | max:255 | Supplier reference |

**Note:** For batch-tracked products, the server allocates or creates batches (FEFO); `batch_id` is set automatically. Child transactions of type `batch_allocation` may be created for stock-out.

**Transaction Types:**
- `purchase` - New stock purchase
- `sale` - Stock sold (typically created by sales flow)
- `adjustment` - Manual adjustment (positive or negative quantity)
- `transfer_in` - Received from another branch
- `transfer_out` - Sent to another branch
- `return` - Customer return
- `damage` - Damaged/write-off (often created via stock-writeoffs endpoint)
- `initial` - Initial stock setup

**Request Example:**
```json
{
  "branch_id": 1,
  "product_id": 5,
  "type": "adjustment",
  "quantity": 25,
  "location": "both",
  "unit_cost": 10.50,
  "reference_number": "ADJ-001",
  "notes": "Stock count correction",
  "batch_number": null,
  "expiry_date": null
}
```

**Response:** `201 Created`

---

### 3. Get Transaction Details

**GET** `/inventory/transactions/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`

---

### 4. Stock Summary

**GET** `/inventory/stock-summary`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `branch_id` - Filter by branch
- `product_id` - Filter by product

**Response:** `200 OK`
```json
{
  "data": {
    "product_id": 5,
    "product_name": "Product Name",
    "total_quantity": 150,
    "total_value": 3750.00,
    "branches": [
      {
        "branch_id": 1,
        "branch_name": "Main Branch",
        "shelf_quantity": 20,
        "store_quantity": 80,
        "total_quantity": 100
      },
      {
        "branch_id": 2,
        "branch_name": "Downtown Branch",
        "shelf_quantity": 15,
        "store_quantity": 35,
        "total_quantity": 50
      }
    ],
    "batches": [
      {
        "batch_id": 10,
        "batch_number": "BATCH-001",
        "quantity": 80,
        "expiry_date": "2027-02-01",
        "status": "active"
      },
      {
        "batch_id": 11,
        "batch_number": "BATCH-002",
        "quantity": 70,
        "expiry_date": "2027-03-15",
        "status": "active"
      }
    ]
  }
}
```

---

## Batch Management Module

Manage product batches with expiry tracking (FEFO - First Expiry First Out).

### 1. List Batches

**GET** `/batches`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `branch_id` - Filter by branch
- `product_id` - Filter by product
- `status` - Filter by status (active, depleted, expired, recalled)
- `expired` - Set to `true` to return only expired batches
- `near_expiry` - Days threshold for near-expiry filter (e.g. 30)
- `batch_number` - Partial match on batch number
- `lot_number` - Partial match on lot number
- `sort_by` - Sort field (default: expiry_date)
- `sort_direction` - asc or desc
- `per_page` - Items per page (default: 15)

**Response:** `200 OK` (paginated). Each batch includes full `product` and `branch` objects, plus `quick_sale_requested_count` and `quick_sale_requested` (true when the batch or its product has pending/approved/active quick-sale requests).
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 10,
      "uuid": "...",
      "business_id": 1,
      "branch_id": 1,
      "product_id": 5,
      "batch_number": "BATCH-20260201-001",
      "lot_number": "LOT-001",
      "manufacturing_date": "2026-01-15T00:00:00.000000Z",
      "expiry_date": "2027-02-01T00:00:00.000000Z",
      "received_quantity": 100,
      "current_quantity": 80,
      "unit_cost": "25.00",
      "supplier_name": "Acme",
      "supplier_reference": "PO-001",
      "status": "active",
      "product": { "id": 5, "name": "Product Name", "sku": "SKU123", ... },
      "branch": { "id": 1, "name": "Main Branch", ... },
      "quick_sale_requested_count": 1,
      "quick_sale_requested": true,
      "created_at": "2026-02-01T10:00:00.000000Z"
    }
  ],
  "first_page_url": "...",
  "from": 1,
  "last_page": 3,
  "last_page_url": "...",
  "links": [...],
  "next_page_url": "...",
  "path": "...",
  "per_page": 15,
  "prev_page_url": null,
  "to": 15,
  "total": 45
}
```

---

### 2. Get Near Expiry Batches

**GET** `/batches/near-expiry`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `days` - Days until expiry threshold (default: 30)
- `branch_id` - Filter by branch (optional)

**Response:** `200 OK`
```json
{
  "batches": [
    {
      "id": 12,
      "uuid": "...",
      "batch_number": "BATCH-20260115-003",
      "lot_number": "LOT-003",
      "product": { "id": 7, "name": "Perishable Item", "sku": "SKU456" },
      "branch": { "id": 1, "name": "Main Branch" },
      "expiry_date": "2026-03-01",
      "current_quantity": 25,
      "unit_cost": "1.50",
      "days_until_expiry": 21,
      "status": "active",
      "quick_sale_requested": true
    }
  ],
  "count": 5,
  "days_threshold": 30
}
```

---

### 3. Get Expired Batches

**GET** `/batches/expired`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `branch_id` - Filter by branch

**Response:** `200 OK`. Returns batches with `current_quantity` > 0 that are expired. Body: `batches` (array with slim product/branch, total_value per batch), `count`, `total_value`.

---

### 4. Get Batch Details

**GET** `/batches/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`. Single batch with product, branch, inventory transaction, transaction count, `quick_sale_requested`, and computed fields (is_expired, is_near_expiry, days_until_expiry).

---

### 5. Update Batch

**PATCH** `/batches/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage batches`

**Request:** All fields optional.
```json
{
  "status": "active",
  "lot_number": "LOT-001",
  "supplier_name": "Acme",
  "supplier_reference": "PO-001",
  "notes": "Extended shelf life"
}
```
Allowed `status`: active, depleted, expired, recalled.

**Response:** `200 OK`

---

### 6. Get Batches for Product

**GET** `/products/{id}/batches`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `branch_id` - Filter by branch (optional)

**Response:** `200 OK`. Body: `batches` array in FEFO order; each item includes branch (id, name), expiry dates, quantities, `quick_sale_requested`, and computed fields (is_expired, is_near_expiry, days_until_expiry).

---

## Customer Module

Manage customer records and loyalty.

### 1. List Customers

**GET** `/customers`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `search` - Search by name, email, phone
- `has_loyalty` - Filter loyalty members

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 1,
      "business_id": 1,
      "customer_code": "CUST-000001",
      "name": "Alice Johnson",
      "email": "alice@example.com",
      "phone": "+1234567890",
      "address": "123 Customer St",
      "type": "regular",
      "credit_limit": 5000.00,
      "balance": 0.00,
      "is_active": true,
      "metadata": null,
      "created_at": "2025-06-01T00:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "total": 250
  }
}
```

---

### 2. Create Customer

**POST** `/customers`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `create customers`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `name` | string | ✅ Yes | ❌ No | max:255 | Customer name |
| `email` | string | ❌ No | ✅ Yes | email, unique per business, max:255 | Email address |
| `phone` | string | ❌ No | ✅ Yes | max:20 | Phone number |
| `address` | text | ❌ No | ✅ Yes | max:500 | Physical address |
| `city` | string | ❌ No | ✅ Yes | max:100 | City |
| `state` | string | ❌ No | ✅ Yes | max:100 | State/Province |
| `postal_code` | string | ❌ No | ✅ Yes | max:20 | Postal code |
| `country` | string | ❌ No | ✅ Yes | max:2 | Country code |
| `type` | enum | ❌ No | ❌ No | in:walk-in,regular,vip,wholesale | Customer type (default: 'walk-in') |
| `credit_limit` | decimal | ❌ No | ✅ Yes | numeric, min:0 | Credit limit for wholesale |
| `tax_exempt` | boolean | ❌ No | ❌ No | boolean | Tax exempt status (default: false) |
| `date_of_birth` | date | ❌ No | ✅ Yes | date | Birth date (Y-m-d) |
| `notes` | text | ❌ No | ✅ Yes | max:1000 | Customer notes |
| `metadata` | object | ❌ No | ✅ Yes | json | Additional custom data |

**Request Example:**
```json
{
  "name": "Bob Smith",
  "email": "bob@example.com",
  "phone": "+1987654321",
  "address": "456 Customer Ave",
  "city": "New York",
  "type": "regular",
  "credit_limit": 1000.00,
  "tax_exempt": false,
  "metadata": {}
}
```

**Response:** `201 Created`

---

### 3. Get Customer Details

**GET** `/customers/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`
```json
{
  "data": {
    "id": 1,
    "name": "Alice Johnson",
    "email": "alice@example.com",
    "phone": "+1234567890",
    "address": "123 Customer St",
    "loyalty_points": 150,
    "total_purchases": 2500.00,
    "last_purchase_at": "2026-02-05T14:30:00.000000Z",
    "recent_purchases": [
      {
        "id": 45,
        "sale_number": "SALE-20260205-045",
        "total": 125.00,
        "created_at": "2026-02-05T14:30:00.000000Z"
      }
    ]
  }
}
```

---

### 4. Update Customer

**PUT** `/customers/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `edit customers`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| name | string | ❌ No | ❌ No | max:255 | Customer full name |
| email | string | ❌ No | ✅ Yes | email, max:255, unique per business | Customer email address |
| phone | string | ❌ No | ✅ Yes | max:20 | Customer phone number |
| address | string | ❌ No | ✅ Yes | max:500 | Street address |
| city | string | ❌ No | ✅ Yes | max:100 | City |
| state | string | ❌ No | ✅ Yes | max:100 | State/Province |
| postal_code | string | ❌ No | ✅ Yes | max:20 | Postal/ZIP code |
| country | string | ❌ No | ✅ Yes | max:100 | Country |
| type | string | ❌ No | ❌ No | in:walk-in,regular,vip,wholesale | Customer type classification |
| credit_limit | decimal | ❌ No | ✅ Yes | min:0, max:999999999.99 | Credit limit for wholesale customers |
| tax_exempt | boolean | ❌ No | ❌ No | - | Whether customer is tax exempt |
| date_of_birth | date | ❌ No | ✅ Yes | date, before:today | Customer date of birth |
| notes | text | ❌ No | ✅ Yes | max:1000 | Internal notes about customer |
| metadata | json | ❌ No | ✅ Yes | json | Additional metadata as JSON object |

**Note:** All fields are optional for update. Only include fields you want to change.

**Request Example:**
```json
{
  "email": "newemail@example.com",
  "phone": "+1234567890",
  "type": "vip"
}
```

**Response:** `200 OK`

---

### 5. Delete Customer

**DELETE** `/customers/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `delete customers`

**Response:** `204 No Content`

---

## Payment Method Module

Configure payment methods.

### 1. List Payment Methods

**GET** `/payment-methods`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 1,
      "business_id": 1,
      "name": "Cash",
      "type": "cash",
      "is_active": true,
      "requires_reference": false,
      "icon": "💵",
      "created_at": "2026-01-01T00:00:00.000000Z"
    },
    {
      "id": 2,
      "business_id": 1,
      "name": "Credit Card",
      "type": "card",
      "is_active": true,
      "requires_reference": true,
      "icon": "💳",
      "created_at": "2026-01-01T00:00:00.000000Z"
    }
  ]
}
```

---

### 2. Create Payment Method

**POST** `/payment-methods`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage_payment_methods`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| name | string | ✅ Yes | ❌ No | max:255 | Payment method name |
| type | string | ✅ Yes | ❌ No | in:cash,card,mobile,bank_transfer,check,other | Payment method type |
| is_active | boolean | ❌ No | ❌ No | - | Whether payment method is active (default: true) |
| requires_reference | boolean | ❌ No | ❌ No | - | Whether payment requires reference number (default: false) |
| icon | string | ❌ No | ✅ Yes | max:10 | Emoji or icon character |

**Request Example:**
```json
{
  "name": "Mobile Money",
  "type": "mobile",
  "is_active": true,
  "requires_reference": true,
  "icon": "📱"
}
```

**Response:** `201 Created`

---

### 3. Get Payment Method Details

**GET** `/payment-methods/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`

---

### 4. Update Payment Method

**PUT** `/payment-methods/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage_payment_methods`

**Response:** `200 OK`

---

### 5. Delete Payment Method

**DELETE** `/payment-methods/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage_payment_methods`

**Response:** `204 No Content`

---

## Sales Module

Process and manage sales transactions.

### 1. List Sales

**GET** `/sales`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `branch_id` - Filter by branch
- `shift_id` - Filter by shift
- `customer_id` - Filter by customer
- `status` - Filter by status (completed, pending, cancelled)
- `start_date` - Date range start (Y-m-d)
- `end_date` - Date range end (Y-m-d)
- `payment_status` - Filter by payment (paid, partial, unpaid)

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 45,
      "business_id": 1,
      "branch_id": 1,
      "shift_id": 12,
      "customer_id": 5,
      "sale_number": "SALE-20260208-045",
      "subtotal": 150.00,
      "tax": 22.50,
      "discount": 0.00,
      "total": 172.50,
      "amount_paid": 172.50,
      "change_given": 0.00,
      "payment_status": "paid",
      "status": "completed",
      "notes": null,
      "created_by": 1,
      "created_at": "2026-02-08T14:30:00.000000Z",
      "items": [
        {
          "id": 101,
          "product_id": 5,
          "product_name": "iPhone 14 Pro",
          "quantity": 1,
          "unit_price": 999.99,
          "discount": 0.00,
          "subtotal": 999.99
        }
      ],
      "payments": [
        {
          "id": 78,
          "payment_method_id": 1,
          "payment_method_name": "Cash",
          "amount": 172.50,
          "reference": null
        }
      ],
      "customer": {
        "id": 5,
        "name": "Alice Johnson"
      },
      "shift": {
        "id": 12,
        "shift_number": "SHIFT-20260208-001"
      },
      "cashier": {
        "id": 1,
        "name": "John Doe"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "total": 1250
  }
}
```

---

### 2. Create Sale

**POST** `/sales`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `create sales`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `branch_id` | integer | ✅ Yes | ❌ No | exists:branches,id | Branch where sale occurs |
| `shift_id` | integer | ✅ Yes | ❌ No | exists:sales_shifts,id | Active shift ID |
| `customer_id` | integer | ❌ No | ✅ Yes | exists:customers,id | Customer ID (optional) |
| `sale_type` | enum | ❌ No | ❌ No | in:pos,online,phone,wholesale | Sale type (default: 'pos') |
| `items` | array | ✅ Yes | ❌ No | min:1 | Array of sale items |
| `items.*.product_id` | integer | ✅ Yes | ❌ No | exists:products,id | Product ID |
| `items.*.quantity` | decimal | ✅ Yes | ❌ No | numeric, min:0.01 | Quantity sold |
| `items.*.unit_price` | decimal | ✅ Yes | ❌ No | numeric, min:0 | Unit price |
| `items.*.discount_percentage` | decimal | ❌ No | ❌ No | numeric, min:0, max:100 | Item discount % (default: 0) |
| `items.*.discount_amount` | decimal | ❌ No | ❌ No | numeric, min:0 | Item discount amount |
| `items.*.tax_rate` | decimal | ❌ No | ✅ Yes | numeric, min:0, max:100 | Item tax rate |
| `items.*.notes` | string | ❌ No | ✅ Yes | max:500 | Item-specific notes |
| `payments` | array | ✅ Yes | ❌ No | min:1 | Array of payments |
| `payments.*.payment_method_id` | integer | ✅ Yes | ❌ No | exists:payment_methods,id | Payment method |
| `payments.*.amount` | decimal | ✅ Yes | ❌ No | numeric, min:0.01 | Payment amount |
| `payments.*.reference_number` | string | ❌ No | ✅ Yes | max:100 | Transaction reference |
| `payments.*.notes` | string | ❌ No | ✅ Yes | max:500 | Payment notes |
| `discount_amount` | decimal | ❌ No | ❌ No | numeric, min:0 | Sale-level discount (default: 0) |
| `discount_percentage` | decimal | ❌ No | ❌ No | numeric, min:0, max:100 | Sale-level discount % |
| `notes` | string | ❌ No | ✅ Yes | max:1000 | Sale notes |
| `reference_number` | string | ❌ No | ✅ Yes | max:100 | External reference |

**Request Example:**
```json
{
  "branch_id": 1,
  "shift_id": 12,
  "customer_id": 5,
  "sale_type": "pos",
  "items": [
    {
      "product_id": 5,
      "quantity": 2,
      "unit_price": 29.99,
      "discount_percentage": 0,
      "tax_rate": 15
    },
    {
      "product_id": 8,
      "quantity": 1,
      "unit_price": 49.99,
      "discount_percentage": 10,
      "tax_rate": 15
    }
  ],
  "payments": [
    {
      "payment_method_id": 1,
      "amount": 104.97,
      "reference_number": null
    }
  ],
  "discount_amount": 0.00,
  "notes": "Customer requested gift wrap"
}
```

**Business Rules:**
- Must have an open shift (shift_id required)
- Stock deducted using FEFO (First Expiry First Out)
- Tax calculated automatically from business settings
- Loyalty points awarded if customer has account
- Inventory transactions created automatically

**Response:** `201 Created`
```json
{
  "data": {
    "id": 46,
    "sale_number": "SALE-20260208-046",
    "subtotal": 104.97,
    "tax": 15.75,
    "discount": 0.00,
    "total": 120.72,
    "amount_paid": 104.97,
    "change_given": 0.00,
    "payment_status": "paid",
    "status": "completed",
    "receipt_url": "https://api.com/receipts/46"
  }
}
```

---

### 3. Get Sale Details

**GET** `/sales/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`

---

### 4. Add Payment to Sale

**POST** `/sales/{id}/payments`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `create_sale`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| payment_method_id | integer | ✅ Yes | ❌ No | exists:payment_methods,id | Payment method used |
| amount | decimal | ✅ Yes | ❌ No | min:0.01, max:999999999.99 | Payment amount |
| reference | string | ❌ No | ✅ Yes | max:255 | Payment reference number (required if payment method requires_reference) |
| notes | text | ❌ No | ✅ Yes | max:500 | Payment notes |

**Use Case:** For partial payments or split payments

**Request Example:**
```json
{
  "payment_method_id": 2,
  "amount": 50.00,
  "reference": "TXN123456"
}
```

**Response:** `200 OK`

---

### 5. Cancel Sale

**POST** `/sales/{id}/cancel`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `cancel sales`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| reason | string | ✅ Yes | ❌ No | max:1000 | Reason for cancelling the sale |
| restore_stock | boolean | ❌ No | ❌ No | - | Whether to restore inventory to stock (default: true) |

**Request Example:**
```json
{
  "reason": "Customer changed mind",
  "restore_stock": true
}
```

**Business Rules:**
- Only sales within shift can be cancelled
- Stock is restored to batches if `restore_stock` is true
- Payments are reversed

**Response:** `200 OK`

---

## Sales Shift Module

Manage cashier shifts and accountability.

### 1. List Shifts

**GET** `/shifts`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `branch_id` - Filter by branch
- `user_id` - Filter by cashier
- `status` - Filter by status (open, closed)
- `start_date` - Date range start
- `end_date` - Date range end

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 12,
      "business_id": 1,
      "branch_id": 1,
      "user_id": 1,
      "shift_number": "SHIFT-20260208-001",
      "start_time": "2026-02-08T08:00:00.000000Z",
      "end_time": null,
      "opening_balance": 500.00,
      "closing_balance": null,
      "expected_balance": 1250.00,
      "actual_balance": null,
      "discrepancy": null,
      "discrepancy_reason": null,
      "status": "open",
      "sales_count": 15,
      "total_sales": 750.00,
      "branch": {
        "id": 1,
        "name": "Main Branch"
      },
      "user": {
        "id": 1,
        "name": "John Doe"
      },
      "created_at": "2026-02-08T08:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "total": 85
  }
}
```

---

### 2. Open Shift

**POST** `/shifts`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `create shift`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| branch_id | integer | ✅ Yes | ❌ No | exists:branches,id | Branch where shift is being opened |
| opening_balance | decimal | ✅ Yes | ❌ No | min:0, max:999999999.99 | Cash amount in register at shift start |

**Business Rules:**
- User can only have ONE active shift at a time (across all branches)
- A branch CAN have MULTIPLE active shifts (one per user/cashier)
- Must close current shift before opening a new one

**Request Example:**
```json
{
  "branch_id": 1,
  "opening_balance": 500.00
}
```

**Response:** `201 Created`
```json
{
  "data": {
    "id": 13,
    "shift_number": "SHIFT-20260208-002",
    "branch_id": 1,
    "user_id": 1,
    "start_time": "2026-02-08T16:00:00.000000Z",
    "opening_balance": 500.00,
    "status": "open"
  }
}
```

**Error (User has open shift):** `400 Bad Request`
```json
{
  "message": "You already have an open shift. Please close your current shift before opening a new one.",
  "current_shift": {
    "id": 12,
    "shift_number": "SHIFT-20260208-001",
    "branch_id": 1,
    "branch_name": "Main Branch",
    "opened_at": "2026-02-08T08:00:00.000000Z"
  }
}
```

---

### 3. Get Current Shift

**GET** `/shifts/current`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

Returns the authenticated user's currently open shift.

**Response:** `200 OK`
```json
{
  "data": {
    "id": 12,
    "shift_number": "SHIFT-20260208-001",
    "branch_id": 1,
    "start_time": "2026-02-08T08:00:00.000000Z",
    "opening_balance": 500.00,
    "expected_balance": 1250.00,
    "sales_count": 15,
    "total_sales": 750.00,
    "status": "open"
  }
}
```

**Response (No open shift):** `404 Not Found`

---

### 4. Get Shift Details

**GET** `/shifts/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`

---

### 5. Get Shift Sales

**GET** `/shifts/{id}/sales`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

Returns all sales made during this shift.

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 45,
      "sale_number": "SALE-20260208-045",
      "total": 172.50,
      "payment_status": "paid",
      "created_at": "2026-02-08T09:15:00.000000Z"
    }
  ],
  "meta": {
    "total_sales": 15,
    "total_amount": 750.00
  }
}
```

---

### 6. Pause Shift

**POST** `/shifts/{id}/pause`

Pause an open shift. Requires `manage shifts` or be shift owner. Request body can include optional notes.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`

---

### 7. Resume Shift

**POST** `/shifts/{id}/resume`

Resume a paused shift. Requires PIN code (6 digits) in body. User must have PIN set.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Request:**
```json
{
  "pin_code": "123456"
}
```

**Response:** `200 OK`

---

### 8. Close Shift

**POST** `/shifts/{id}/close`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `close shift`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| actual_cash | decimal | ✅ Yes | ❌ No | numeric, min:0 | Cash amount in register at shift end |
| closing_notes | string | ❌ No | ✅ Yes | - | Optional notes for shift closure |
| pin_code | string | ✅ Yes | ❌ No | size:6, regex:/^[0-9]{6}$/ | Current user's 6-digit PIN (user must have PIN set) |

**Business Rules:**
- User must have a PIN set; PIN is verified before closing.
- Calculates expected cash from opening + cash sales; variance = expected − actual_cash.
- Only shift owner, business owner, or user with `manage shifts` can close.

**Request Example:**
```json
{
  "actual_cash": 1240.00,
  "closing_notes": "End of day count",
  "pin_code": "123456"
}
```

**Response:** `200 OK`
```json
{
  "message": "Shift closed successfully",
  "shift": {
    "id": 12,
    "shift_number": "SHIFT-20260208-001",
    "start_time": "2026-02-08T08:00:00.000000Z",
    "end_time": "2026-02-08T16:00:00.000000Z",
    "opening_balance": 500.00,
    "actual_cash": 1240.00,
    "expected_cash": 1250.00,
    "variance": -10.00,
    "status": "closed"
  }
}
```

---

### 9. Resolve Discrepancy

**POST** `/shifts/{id}/resolve-discrepancy`

Marks a closed shift's cash variance as resolved. Shift must be closed and have a non-zero variance.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage shifts` (or business owner)

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| resolution_notes | string | ✅ Yes | ❌ No | max:1000 | Explanation for the discrepancy |

**Request Example:**
```json
{
  "resolution_notes": "Cashier forgot to record petty cash withdrawal; variance explained and accepted."
}
```

**Response:** `200 OK`

---

## Quick Sale Module

Manage near-expiry discount approvals.

### 1. List Quick Sales

**GET** `/quick-sales`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `branch_id` - Filter by branch
- `status` - Filter by status (pending, approved, rejected, ended)

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 5,
      "business_id": 1,
      "branch_id": 1,
      "product_id": 12,
      "batch_id": 25,
      "original_price": 50.00,
      "discounted_price": 35.00,
      "discount_percentage": 30,
      "quantity_available": 20,
      "quantity_sold": 8,
      "reason": "Product expiring in 10 days",
      "status": "approved",
      "requested_by": 2,
      "approved_by": 1,
      "approved_at": "2026-02-07T10:00:00.000000Z",
      "ends_at": "2026-02-10T00:00:00.000000Z",
      "product": {
        "id": 12,
        "name": "Dairy Product",
        "sku": "DAIRY-001"
      },
      "batch": {
        "id": 25,
        "batch_number": "BATCH-20260120-005",
        "expiry_date": "2026-02-18"
      }
    }
  ]
}
```

---

### 2. Request Quick Sale

**POST** `/quick-sales`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `request quick sale`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| product_id | integer | ✅ Yes | ❌ No | exists:products,id | Product to sell at discount |
| branch_id | integer | ✅ Yes | ❌ No | exists:branches,id | Branch requesting quick sale |
| reason | string | ✅ Yes | ❌ No | max:1000 | Reason for discount request (e.g., near expiry, damage) |
| expiry_date | date | ❌ No | ✅ Yes | date, after:today | Date when quick sale should auto-end |

**Business Rules:**
- Discounted price must be below minimum threshold
- Requires manager approval
- Applies only to specified batch
- Auto-ends at specified date

**Request Example:**
```json
{
  "product_id": 12,
  "branch_id": 1,
  "reason": "Product expiring in 10 days - need to clear inventory before expiry",
  "expiry_date": "2026-02-18"
}
```

**Response:** `201 Created`

---

### 3. Get Quick Sale Details

**GET** `/quick-sales/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`

---

### 4. Approve Quick Sale

**POST** `/quick-sales/{id}/approve`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `approve quick sale`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| notes | text | ❌ No | ✅ Yes | max:1000 | Approval notes or comments |

**Request Example:**
```json
{
  "notes": "Approved due to near expiry"
}
```

**Response:** `200 OK`

---

### 5. Reject Quick Sale

**POST** `/quick-sales/{id}/reject`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `approve quick sale`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| reason | string | ✅ Yes | ❌ No | max:1000 | Reason for rejecting the quick sale request |

**Request Example:**
```json
{
  "reason": "Discount too high, reduce to 20%"
}
```

**Response:** `200 OK`

---

### 6. End Quick Sale

**POST** `/quick-sales/{id}/end`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `manage_quick_sale`

Manually end a quick sale before scheduled end date.

**Response:** `200 OK`

---

## Refund Request Module

Manage refund approvals workflow.

### 1. List Refund Requests

**GET** `/refund-requests`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `branch_id` - Filter by branch
- `status` - Filter by status (pending, approved, rejected)
- `start_date` - Date range start
- `end_date` - Date range end

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 8,
      "business_id": 1,
      "branch_id": 1,
      "sale_id": 42,
      "amount": 125.00,
      "reason": "Product defective",
      "status": "pending",
      "requested_by": 2,
      "approved_by": null,
      "approved_at": null,
      "notes": null,
      "created_at": "2026-02-08T11:00:00.000000Z",
      "sale": {
        "id": 42,
        "sale_number": "SALE-20260207-042",
        "total": 125.00
      },
      "requester": {
        "id": 2,
        "name": "Jane Smith"
      }
    }
  ]
}
```

---

### 2. Create Refund Request

**POST** `/refund-requests`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `request refund`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `sale_id` | integer | ✅ Yes | ❌ No | exists:sales,id | Sale to refund |
| `reason` | text | ✅ Yes | ❌ No | min:10, max:1000 | Reason for refund |
| `refund_scope` | string | ❌ No | ✅ Yes | in:whole_sale,items | `whole_sale` (default) = refund entire sale; `items` = refund specific line items only |
| `items` | array | When scope=items | ✅ Yes | - | Required when `refund_scope` is `items`. Each element: `sale_item_id`, `quantity` |
| `items.*.sale_item_id` | integer | When scope=items | ❌ No | exists:sale_items,id | Sale line item ID (must belong to the sale) |
| `items.*.quantity` | decimal | When scope=items | ❌ No | numeric, min:0.01 | Quantity to refund for that line (cannot exceed remaining refundable qty) |

**Whole-sale request example:**
```json
{
  "sale_id": 42,
  "reason": "Customer returned entire order with receipt"
}
```

**Partial (specific items) request example:**
```json
{
  "sale_id": 42,
  "reason": "Customer returned 2 of 5 units - defective",
  "refund_scope": "items",
  "items": [
    { "sale_item_id": 101, "quantity": 2 }
  ]
}
```

**Business Rules:**
- Sale must be completed and not fully refunded (`refunded_amount` < `total_amount`). Only one pending refund request per sale at a time.
- **Whole sale:** Refund amount = sale total. On approval, all line items are restored to inventory and sale is marked fully refunded when applicable.
- **Items:** Refund amount is computed from the selected lines (proportional to quantity). Each line's quantity cannot exceed (sale_item.quantity minus already refunded for that line). On approval, only the requested items/quantities are restored to inventory; `refunded_amount` on the sale is incremented; sale is marked fully refunded when `refunded_amount` >= `total_amount`.

**Response:** `201 Created` — includes `refund_request` with `refund_scope`, `amount`, and when `items`, the `items` relation (sale_item_id, quantity, saleItem.product).

---

### 3. Get Refund Request Details

**GET** `/refund-requests/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`

---

### 4. Approve Refund

**POST** `/refund-requests/{id}/approve`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `approve refund`

**Business Rules:**
- Only pending requests can be approved. Approver cannot be the requester.
- **Whole sale:** Restores inventory for all sale items; adds request amount to sale `refunded_amount`; marks sale fully refunded when total is reached.
- **Items:** Restores inventory only for the requested line items/quantities; adds request amount to sale `refunded_amount`; marks sale fully refunded when `refunded_amount` >= sale total.

**Response:** `200 OK`

---

### 5. Reject Refund

**POST** `/refund-requests/{id}/reject`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `approve refund`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| reason | string | ✅ Yes | ❌ No | max:1000 | Reason for rejecting the refund request |

**Request Example:**
```json
{
  "reason": "No receipt provided"
}
```

**Response:** `200 OK`

---

## Stock Transfer Module

Manage inter-branch stock transfers.

### 1. List Stock Transfer Requests

**GET** `/stock-transfer-requests`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `from_branch_id` - Filter by source branch
- `to_branch_id` - Filter by destination branch
- `status` - Filter by status (pending, approved, rejected, in_transit, completed, cancelled)

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 3,
      "business_id": 1,
      "from_branch_id": 1,
      "to_branch_id": 2,
      "product_id": 5,
      "batch_id": 10,
      "quantity": 50,
      "reason": "Low stock at downtown branch",
      "status": "approved",
      "requested_by": 2,
      "approved_by": 1,
      "confirmed_by": null,
      "requested_at": "2026-02-07T10:00:00.000000Z",
      "approved_at": "2026-02-07T11:00:00.000000Z",
      "confirmed_at": null,
      "product": {
        "id": 5,
        "name": "Product Name"
      },
      "from_branch": {
        "id": 1,
        "name": "Main Branch"
      },
      "to_branch": {
        "id": 2,
        "name": "Downtown Branch"
      }
    }
  ]
}
```

---

### 2. Create Stock Transfer Request

**POST** `/stock-transfer-requests`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `request_stock_transfer`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `from_branch_id` | integer | ✅ Yes | ❌ No | exists:branches,id | Source branch |
| `to_branch_id` | integer | ✅ Yes | ❌ No | exists:branches,id,different from from_branch_id | Destination branch |
| `product_id` | integer | ✅ Yes | ❌ No | exists:products,id | Product to transfer |
| `batch_id` | integer | ❌ No | ✅ Yes | exists:batches,id | Specific batch (for batch tracking) |
| `quantity` | decimal | ✅ Yes | ❌ No | numeric, min:0.01 | Quantity to transfer |
| `reason` | text | ✅ Yes | ❌ No | max:1000 | Reason for transfer |
| `urgency` | enum | ❌ No | ❌ No | in:low,medium,high,urgent | Priority level (default: 'medium') |
| `expected_date` | date | ❌ No | ✅ Yes | date, after_or_equal:today | Expected completion date |
| `notes` | text | ❌ No | ✅ Yes | max:1000 | Additional notes |

**Request Example:**
```json
{
  "from_branch_id": 1,
  "to_branch_id": 2,
  "product_id": 5,
  "batch_id": 10,
  "quantity": 50,
  "reason": "Low stock at downtown branch",
  "urgency": "high",
  "notes": "Needed for weekend sales"
}
```

**Business Rules:**
- Requires approval from source branch manager
- Requires confirmation from destination branch
- Stock reserved at source until confirmed/rejected
- Uses FEFO batch allocation

**Response:** `201 Created`

---

### 3. Get Transfer Request Details

**GET** `/stock-transfer-requests/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`

---

### 4. Approve Transfer

**POST** `/stock-transfer-requests/{id}/approve`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `approve_stock_transfer`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| notes | text | ❌ No | ✅ Yes | max:1000 | Approval notes or comments |

**Request Example:**
```json
{
  "notes": "Stock available, approved for transfer"
}
```

**Response:** `200 OK`

---

### 5. Reject Transfer

**POST** `/stock-transfer-requests/{id}/reject`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `approve_stock_transfer`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| reason | string | ✅ Yes | ❌ No | max:1000 | Reason for rejecting the transfer request |

**Request Example:**
```json
{
  "reason": "Insufficient stock available"
}
```

**Response:** `200 OK`

---

### 6. Confirm Transfer Receipt

**POST** `/stock-transfer-requests/{id}/confirm`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `confirm_stock_transfer`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| received_quantity | decimal | ✅ Yes | ❌ No | min:0.01 | Actual quantity received (may differ from requested if there was shortage/damage) |
| notes | text | ❌ No | ✅ Yes | max:1000 | Receipt notes or comments |

**Business Rules:**
- Updates stock levels at both branches
- Creates inventory transactions
- Completes the transfer workflow

**Request Example:**
```json
{
  "received_quantity": 50,
  "notes": "Stock received in good condition"
}
```

**Response:** `200 OK`

---

### 7. Cancel Transfer

**POST** `/stock-transfer-requests/{id}/cancel`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `cancel_stock_transfer`

**Request:**
```json
{
  "reason": "No longer needed"
}
```

**Response:** `200 OK`

---

## Shelf/Store Move Requests

Move requests allow users to **request** moving stock between shelf and store; approvers **approve** or **reject**. On approval, the move is performed. Direct move (branch-products move-to-shelf / move-to-store) remains available for users with **approve shelf store move** or **manage inventory** / **adjust inventory** (or owner).

**Permissions:** `request shelf store move` (create request, list), `approve shelf store move` (approve/reject, or direct move).

### 1. List Shelf/Store Move Requests

**GET** `/shelf-store-move-requests`

**Headers:** `Authorization: Bearer {token}`, `X-Business-Id: {business_id}`

**Query:** `branch_id`, `status` (pending|approved|rejected), `my_requests` (bool), `pending_approval` (bool), `per_page`

**Response:** `200 OK` — Paginated list with `data` (array of requests) and `meta`.

### 2. Create Shelf/Store Move Request

**POST** `/shelf-store-move-requests`

**Headers:** `Authorization: Bearer {token}`, `X-Business-Id: {business_id}`

**Request Schema:**

| Field | Type | Required | Validation | Description |
|-------|------|----------|------------|-------------|
| branch_product_id | integer | Yes | exists:branch_products,id | Branch product to move |
| direction | string | Yes | in:to_shelf,to_store | Move from store→shelf or shelf→store |
| quantity | integer | Yes | min:1 | Quantity to move |
| reason | string | No | max:500 | Optional reason |

**Request Example:**
```json
{
  "branch_product_id": 1,
  "direction": "to_shelf",
  "quantity": 5,
  "reason": "Restock shelf"
}
```

**Response:** `201 Created` — Created request (status pending) with `request_number`, `branch_product`, `requested_by`, etc.

### 3. Get Shelf/Store Move Request

**GET** `/shelf-store-move-requests/{id}`

**Response:** `200 OK` — Single request with branch, branch_product, requestedBy, reviewedBy.

### 4. Approve Request

**POST** `/shelf-store-move-requests/{id}/approve`

Performs the move (calls BranchProduct moveToShelf or moveToStore) and sets request status to `approved`. Requires `approve shelf store move`. Request must be `pending`.

**Response:** `200 OK` — Updated request and success message.

### 5. Reject Request

**POST** `/shelf-store-move-requests/{id}/reject`

**Body:** `reason` (optional, string, max 500). Sets status to `rejected`, `reviewed_by`, `reviewed_at`, `review_notes`.

**Response:** `200 OK` — Updated request.

---

## Stock Write-off Module

Manage stock write-offs (damage, expiry, theft).

### 1. List Stock Write-offs

**GET** `/stock-writeoffs`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `branch_id` - Filter by branch
- `reason` - Filter by reason (damage, expiry, theft, other)
- `start_date` - Date range start
- `end_date` - Date range end

**Response:** `200 OK`
```json
{
  "data": [
    {
      "id": 7,
      "business_id": 1,
      "branch_id": 1,
      "product_id": 12,
      "batch_id": 25,
      "quantity": 5,
      "unit_cost": 25.00,
      "total_cost": 125.00,
      "reason": "expiry",
      "notes": "Batch expired yesterday",
      "approved_by": 1,
      "created_by": 2,
      "created_at": "2026-02-08T10:00:00.000000Z",
      "product": {
        "id": 12,
        "name": "Dairy Product"
      },
      "batch": {
        "id": 25,
        "batch_number": "BATCH-20260120-005",
        "expiry_date": "2026-02-07"
      }
    }
  ]
}
```

---

### 2. Create Stock Write-off

**POST** `/stock-writeoffs`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `write off stock`

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `branch_id` | integer | When using `product_id` | ❌ No | exists:branches,id | Branch where write-off occurs (required when passing `product_id`) |
| `product_id` | integer | One of with `branch_product_id` | ❌ No | exists:products,id | Product to write off (must belong to business and be in the branch) |
| `branch_product_id` | integer | One of with `product_id` | ❌ No | exists:branch_products,id | Branch product to write off (branch is inferred) |
| `quantity` | integer | ✅ Yes | ❌ No | integer, min:1 | Quantity to write off |
| `source` | string | ✅ Yes | ❌ No | in:shelf,store | Where to deduct: `shelf` or `store` |
| `reason` | string | ✅ Yes | ❌ No | max:1000 | Reason for write-off (free text) |

Pass either `product_id` + `branch_id`, or `branch_product_id`.

**Request Example (product_id + branch_id, deduct from shelf):**
```json
{
  "current_business_id": 1,
  "branch_id": 1,
  "product_id": 1,
  "quantity": 5,
  "source": "shelf",
  "reason": "Damaged - water damage"
}
```

**Request Example (branch_product_id, deduct from store):**
```json
{
  "current_business_id": 1,
  "branch_product_id": 1,
  "quantity": 3,
  "source": "store",
  "reason": "Expired in warehouse"
}
```

**Business Rules:**
- Identify the product by `product_id` (with `branch_id`) or by `branch_product_id`. Product must belong to the business and be available in the branch.
- Quantity is deducted from the location given by `source` (shelf or store).
- Requires `write off stock` permission (or business owner).

**Response:** `201 Created`

---

### 3. Get Write-off Details

**GET** `/stock-writeoffs/{id}`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response:** `200 OK`

---

## Analytics Module

Business intelligence and reporting.

### 1. Organization Analytics

**GET** `/analytics/organization`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `view_analytics`

**Query Parameters:**
- `start_date` - Date range start (Y-m-d)
- `end_date` - Date range end (Y-m-d)

**Response:** `200 OK`
```json
{
  "data": {
    "period": {
      "start": "2026-02-01",
      "end": "2026-02-08"
    },
    "revenue": {
      "total": 125000.00,
      "by_branch": [
        {
          "branch_id": 1,
          "branch_name": "Main Branch",
          "revenue": 75000.00
        },
        {
          "branch_id": 2,
          "branch_name": "Downtown Branch",
          "revenue": 50000.00
        }
      ]
    },
    "sales": {
      "total_count": 450,
      "average_value": 277.78,
      "by_day": [
        {
          "date": "2026-02-01",
          "count": 65,
          "total": 18000.00
        }
      ]
    },
    "products": {
      "total_sold": 1250,
      "top_sellers": [
        {
          "product_id": 5,
          "product_name": "iPhone 14 Pro",
          "quantity_sold": 45,
          "revenue": 44999.55
        }
      ]
    },
    "inventory": {
      "total_value": 350000.00,
      "low_stock_items": 12,
      "out_of_stock": 3
    },
    "customers": {
      "total": 250,
      "new_this_period": 15,
      "repeat_rate": 0.68
    }
  }
}
```

---

### 2. Branch Analytics

**GET** `/analytics/branches`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `view_analytics`

**Query Parameters:**
- `branch_id` - Specific branch (optional)
- `start_date` - Date range start
- `end_date` - Date range end

**Response:** `200 OK`
```json
{
  "data": [
    {
      "branch_id": 1,
      "branch_name": "Main Branch",
      "revenue": 75000.00,
      "sales_count": 280,
      "average_sale": 267.86,
      "products_sold": 750,
      "active_shifts": 1,
      "inventory_value": 200000.00,
      "top_products": [
        {
          "product_id": 5,
          "product_name": "Product A",
          "quantity_sold": 30,
          "revenue": 29999.70
        }
      ]
    }
  ]
}
```

---

### 3. Product Analytics

**GET** `/analytics/products`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `view_analytics`

**Query Parameters:**
- `product_id` - Specific product (optional)
- `category_id` - Filter by category
- `branch_id` - Filter by branch
- `start_date` - Date range start
- `end_date` - Date range end

**Response:** `200 OK`
```json
{
  "data": [
    {
      "product_id": 5,
      "product_name": "iPhone 14 Pro",
      "sku": "IPH14PRO-256",
      "quantity_sold": 45,
      "revenue": 44999.55,
      "cost": 33750.00,
      "profit": 11249.55,
      "profit_margin": 0.25,
      "average_price": 999.99,
      "stock_remaining": 20,
      "turnover_rate": 2.25,
      "sales_trend": [
        {
          "date": "2026-02-01",
          "quantity": 6
        }
      ]
    }
  ]
}
```

---

### 4. Profit & Loss

**GET** `/analytics/profit-loss`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `view_analytics`

**Query Parameters:**
- `start_date` - Date range start
- `end_date` - Date range end
- `branch_id` - Filter by branch (optional)

**Response:** `200 OK`
```json
{
  "data": {
    "period": {
      "start": "2026-02-01",
      "end": "2026-02-08"
    },
    "revenue": {
      "gross_sales": 125000.00,
      "refunds": -2500.00,
      "discounts": -1000.00,
      "net_sales": 121500.00
    },
    "costs": {
      "cost_of_goods_sold": 90000.00,
      "writeoffs": 500.00,
      "total_costs": 90500.00
    },
    "profit": {
      "gross_profit": 31000.00,
      "gross_margin": 0.255,
      "net_profit": 31000.00,
      "net_margin": 0.255
    },
    "by_category": [
      {
        "category": "Electronics",
        "revenue": 75000.00,
        "cost": 55000.00,
        "profit": 20000.00,
        "margin": 0.267
      }
    ]
  }
}
```

---

### 5. Growth Trends

**GET** `/analytics/growth-trends`

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Permission Required:** `view_analytics`

**Query Parameters:**
- `period` - Grouping period (day, week, month)
- `start_date` - Date range start
- `end_date` - Date range end

**Response:** `200 OK`
```json
{
  "data": {
    "revenue_trend": [
      {
        "period": "2026-02-01",
        "revenue": 18000.00,
        "growth_rate": 0.05
      },
      {
        "period": "2026-02-02",
        "revenue": 19500.00,
        "growth_rate": 0.083
      }
    ],
    "sales_trend": [
      {
        "period": "2026-02-01",
        "count": 65,
        "growth_rate": 0.03
      }
    ],
    "customer_acquisition": [
      {
        "period": "2026-02-01",
        "new_customers": 3,
        "total_customers": 250
      }
    ],
    "summary": {
      "revenue_growth": 0.12,
      "sales_growth": 0.08,
      "customer_growth": 0.06
    }
  }
}
```

---

## Offline Synchronization Module

Enable offline-first POS operation with reliable data synchronization.

### System Overview

The synchronization system allows POS clients (web, desktop, mobile) to:
- Operate fully offline with local data storage
- Sync changes bi-directionally when online
- Handle conflicts automatically using version control
- Maintain data integrity and auditability

### Core Concepts

**Client UUID:** Unique identifier for each record to ensure idempotency
**Version Number:** Optimistic locking for conflict detection
**Device Registration:** Each POS terminal must register before syncing
**Sync Sessions:** Track each synchronization operation
**Change Logs:** Audit trail of all modifications

---

### 1. Register Device

**POST** `/sync/register-device`

Register a new POS device or retrieve existing registration.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `device_name` | string | ✅ Yes | ❌ No | max:255 | Human-readable device name |
| `device_identifier` | string | ✅ Yes | ❌ No | max:255, unique | Unique device identifier (MAC/UUID) |
| `branch_id` | integer | ✅ Yes | ❌ No | exists:branches,id | Assigned branch |
| `device_type` | enum | ❌ No | ❌ No | in:pos_terminal,mobile,tablet,web | Device type (default: 'pos_terminal') |
| `os_info` | string | ❌ No | ✅ Yes | max:255 | Operating system info |
| `app_version` | string | ❌ No | ✅ Yes | max:50 | Application version |
| `capabilities` | object | ❌ No | ✅ Yes | json | Device capabilities |
| `metadata` | object | ❌ No | ✅ Yes | json | Additional device metadata |

**Request Example:**
```json
{
  "device_name": "Main Counter POS",
  "device_identifier": "POS-001-ABC123DEF456",
  "branch_id": 1,
  "device_type": "pos_terminal",
  "os_info": "Android 12",
  "app_version": "1.0.0",
  "capabilities": {
    "offline_mode": true,
    "barcode_scanner": true,
    "receipt_printer": true,
    "cash_drawer": false
  }
}
```

**Device Types:**
- `web` - Web browser POS
- `desktop` - Desktop application
- `mobile` - Mobile app
- `tablet` - Tablet device

**Response:** `200 OK`
```json
{
  "device": {
    "id": 1,
    "device_id": "POS-MAIN-001",
    "business_id": 1,
    "branch_id": 1,
    "device_type": "web",
    "device_name": "Main Counter POS",
    "status": "active",
    "capabilities": {
      "offline_mode": true,
      "barcode_scanner": true,
      "receipt_printer": true,
      "cash_drawer": false
    },
    "last_seen_at": "2026-02-08T10:00:00.000000Z",
    "created_at": "2026-02-08T10:00:00.000000Z"
  }
}
```

---

### 2. Bootstrap Initial Data

**POST** `/sync/bootstrap`

Download initial dataset for a newly registered device.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
X-Device-Id: {device_id}
```

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `branch_id` | integer | ✅ Yes | ❌ No | exists:branches,id | Branch to bootstrap for |
| `include_historical_data` | boolean | ❌ No | ❌ No | boolean | Include historical sales (default: false) |
| `days_of_history` | integer | ❌ No | ✅ Yes | integer, min:1, max:365 | Days of history to include |
| `entities` | array | ❌ No | ✅ Yes | array of entity types | Specific entities to bootstrap |

**Available Entities:**
- `products` - Product catalog for branch
- `categories` - Product categories
- `customers` - Customer list
- `payment_methods` - Payment methods
- `batches` - Batch inventory data
- `users` - Branch users
- `sales_shifts` - Historical shifts

**Request Example:**
```json
{
  "branch_id": 1,
  "include_historical_data": false,
  "days_of_history": 30,
  "entities": [
    "products",
    "customers",
    "payment_methods",
    "categories"
  ]
}
```

**Response:** `200 OK`
```json
{
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "timestamp": "2026-02-08T10:00:00.000000Z",
  "data": {
    "products": [
      {
        "id": 5,
        "client_uuid": "prod-550e8400-e29b-41d4-a716-446655440001",
        "name": "iPhone 14 Pro",
        "sku": "IPH14PRO-256",
        "base_selling_price": 999.99,
        "version": 1,
        "synced_at": "2026-02-08T10:00:00.000000Z"
      }
    ],
    "customers": [
      {
        "id": 1,
        "client_uuid": "cust-650e8400-e29b-41d4-a716-446655440001",
        "name": "Alice Johnson",
        "email": "alice@example.com",
        "version": 1,
        "synced_at": "2026-02-08T10:00:00.000000Z"
      }
    ],
    "payment_methods": [
      {
        "id": 1,
        "client_uuid": "pm-750e8400-e29b-41d4-a716-446655440001",
        "name": "Cash",
        "type": "cash",
        "version": 1
      }
    ],
    "sales_shifts": []
  },
  "counts": {
    "products": 150,
    "customers": 250,
    "payment_methods": 5,
    "sales_shifts": 0
  }
}
```

---

### 3. Pull Server Changes

**POST** `/sync/pull`

Retrieve changes from server since last sync.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
X-Device-Id: {device_id}
```

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| last_sync_at | datetime | ❌ No | ✅ Yes | ISO 8601 format | Timestamp of last successful sync, null for initial sync |
| entities | array | ❌ No | ✅ Yes | in:products,categories,customers,payment_methods,batches,users,sales_shifts,sales,inventory_transactions | List of entity types to pull, null pulls all entities |

**Available Entities:**
- `products`, `categories`, `customers`, `payment_methods`, `batches`, `users`, `sales_shifts`, `sales`, `inventory_transactions`

**Request Example:**
```json
{
  "last_sync_at": "2026-02-08T09:00:00.000000Z",
  "entities": [
    "products",
    "customers",
    "sales",
    "inventory_transactions"
  ]
}
```

**Response:** `200 OK`
```json
{
  "session_id": "660e8400-e29b-41d4-a716-446655440000",
  "timestamp": "2026-02-08T10:00:00.000000Z",
  "changes": {
    "products": {
      "created": [
        {
          "id": 45,
          "client_uuid": "prod-770e8400-e29b-41d4-a716-446655440001",
          "name": "New Product",
          "version": 1,
          "synced_at": "2026-02-08T09:30:00.000000Z"
        }
      ],
      "updated": [
        {
          "id": 5,
          "client_uuid": "prod-550e8400-e29b-41d4-a716-446655440001",
          "base_selling_price": 949.99,
          "version": 2,
          "synced_at": "2026-02-08T09:45:00.000000Z"
        }
      ],
      "deleted": [12, 15]
    },
    "customers": {
      "created": [],
      "updated": [],
      "deleted": []
    }
  },
  "counts": {
    "total_changes": 4,
    "products": 3,
    "customers": 0,
    "sales": 1
  }
}
```

---

### 4. Push Client Changes

**POST** `/sync/push`

Upload offline changes to server.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
X-Device-Id: {device_id}
```

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| session_id | string | ✅ Yes | ❌ No | uuid | Unique session identifier for tracking this push operation |
| changes | object | ✅ Yes | ❌ No | - | Object containing arrays of entities to sync, grouped by entity type |
| changes.sales | array | ❌ No | ✅ Yes | - | Array of offline sales to sync |
| changes.sales.*.client_uuid | string | ✅ Yes | ❌ No | uuid | Client-generated UUID for this sale |
| changes.sales.*.branch_id | integer | ✅ Yes | ❌ No | exists:branches,id | Branch where sale occurred |
| changes.sales.*.shift_id | integer | ✅ Yes | ❌ No | exists:sales_shifts,id | Sales shift ID |
| changes.sales.*.customer_id | integer | ❌ No | ✅ Yes | exists:customers,id | Customer ID if applicable |
| changes.sales.*.subtotal | decimal | ✅ Yes | ❌ No | min:0 | Sale subtotal before tax |
| changes.sales.*.tax | decimal | ✅ Yes | ❌ No | min:0 | Total tax amount |
| changes.sales.*.total | decimal | ✅ Yes | ❌ No | min:0 | Final sale total |
| changes.sales.*.payment_status | string | ✅ Yes | ❌ No | in:paid,partial,unpaid | Payment status |
| changes.sales.*.status | string | ✅ Yes | ❌ No | in:completed,cancelled,refunded,partially_refunded | Sale status |
| changes.sales.*.version | integer | ✅ Yes | ❌ No | min:1 | Version number for conflict detection |
| changes.sales.*.created_at | datetime | ✅ Yes | ❌ No | ISO 8601 format | When sale was created offline |
| changes.sales.*.items | array | ✅ Yes | ❌ No | - | Array of sale items |
| changes.sales.*.payments | array | ✅ Yes | ❌ No | - | Array of payment records |
| changes.customers | array | ❌ No | ✅ Yes | - | Array of offline customers to sync |
| changes.customers.*.client_uuid | string | ✅ Yes | ❌ No | uuid | Client-generated UUID for this customer |
| changes.customers.*.name | string | ✅ Yes | ❌ No | max:255 | Customer name |
| changes.customers.*.email | string | ❌ No | ✅ Yes | email | Customer email |
| changes.customers.*.phone | string | ❌ No | ✅ Yes | max:20 | Customer phone |
| changes.customers.*.version | integer | ✅ Yes | ❌ No | min:1 | Version number for conflict detection |
| changes.customers.*.created_at | datetime | ✅ Yes | ❌ No | ISO 8601 format | When customer was created offline |

**Note:** Each entity type in changes can have similar structures with client_uuid, version, and timestamps for conflict resolution.

**Request Example:**
```json
{
  "session_id": "770e8400-e29b-41d4-a716-446655440000",
  "changes": {
    "sales": [
      {
        "client_uuid": "sale-880e8400-e29b-41d4-a716-446655440001",
        "branch_id": 1,
        "shift_id": 12,
        "customer_id": 5,
        "subtotal": 150.00,
        "tax": 22.50,
        "total": 172.50,
        "payment_status": "paid",
        "status": "completed",
        "version": 1,
        "created_at": "2026-02-08T09:30:00.000000Z",
        "items": [
          {
            "client_uuid": "saleitem-990e8400-e29b-41d4-a716-446655440001",
            "product_id": 5,
            "quantity": 1,
            "unit_price": 150.00,
            "version": 1
          }
        ],
        "payments": [
          {
            "client_uuid": "payment-aa0e8400-e29b-41d4-a716-446655440001",
            "payment_method_id": 1,
            "amount": 172.50,
            "version": 1
          }
        ]
      }
    ],
    "customers": [
      {
        "client_uuid": "cust-bb0e8400-e29b-41d4-a716-446655440001",
        "name": "New Customer",
        "email": "newcustomer@example.com",
        "phone": "+1234567890",
        "version": 1,
        "created_at": "2026-02-08T09:25:00.000000Z"
      }
    ]
  }
}
```

**Response:** `200 OK`
```json
{
  "session_id": "770e8400-e29b-41d4-a716-446655440000",
  "timestamp": "2026-02-08T10:00:00.000000Z",
  "results": {
    "successful": [
      {
        "entity_type": "sales",
        "client_uuid": "sale-880e8400-e29b-41d4-a716-446655440001",
        "server_id": 46,
        "version": 1,
        "synced_at": "2026-02-08T10:00:00.000000Z"
      },
      {
        "entity_type": "customers",
        "client_uuid": "cust-bb0e8400-e29b-41d4-a716-446655440001",
        "server_id": 251,
        "version": 1,
        "synced_at": "2026-02-08T10:00:00.000000Z"
      }
    ],
    "conflicts": [],
    "errors": []
  },
  "summary": {
    "total": 2,
    "successful": 2,
    "conflicts": 0,
    "errors": 0
  }
}
```

**Response with Conflicts:** `200 OK`
```json
{
  "session_id": "770e8400-e29b-41d4-a716-446655440000",
  "results": {
    "successful": [],
    "conflicts": [
      {
        "entity_type": "products",
        "client_uuid": "prod-550e8400-e29b-41d4-a716-446655440001",
        "client_version": 2,
        "server_version": 3,
        "conflict_type": "version_mismatch",
        "client_data": {
          "base_selling_price": 899.99,
          "version": 2
        },
        "server_data": {
          "base_selling_price": 949.99,
          "version": 3,
          "updated_at": "2026-02-08T09:50:00.000000Z"
        },
        "resolution_options": [
          "use_server",
          "use_client",
          "merge"
        ]
      }
    ],
    "errors": []
  },
  "summary": {
    "total": 1,
    "successful": 0,
    "conflicts": 1,
    "errors": 0
  }
}
```

---

### 5. Resolve Conflicts

**POST** `/sync/resolve-conflicts`

Submit conflict resolutions for processing.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
X-Device-Id: {device_id}
```

**Request Schema:**

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| session_id | string | ✅ Yes | ❌ No | uuid | Session identifier from the push operation that generated conflicts |
| resolutions | array | ✅ Yes | ❌ No | min:1 | Array of conflict resolution strategies |
| resolutions.*.entity_type | string | ✅ Yes | ❌ No | in:products,customers,sales,inventory_transactions,batches,categories,payment_methods | Type of entity being resolved |
| resolutions.*.client_uuid | string | ✅ Yes | ❌ No | uuid | Client UUID of the conflicting entity |
| resolutions.*.resolution | string | ✅ Yes | ❌ No | in:use_server,use_client,merge | Resolution strategy to apply |
| resolutions.*.merge_fields | object | ❌ No | ✅ Yes | - | Field-level merge instructions when resolution is 'merge', specifying which fields to take from client vs server |

**Resolution Strategies:**
- `use_server` - Accept server version (default, safest option)
- `use_client` - Override with client version (use with caution)
- `merge` - Merge both versions at field level (requires merge_fields)

**Request Example:**
```json
{
  "session_id": "770e8400-e29b-41d4-a716-446655440000",
  "resolutions": [
    {
      "entity_type": "products",
      "client_uuid": "prod-550e8400-e29b-41d4-a716-446655440001",
      "resolution": "use_server"
    }
  ]
}
```

**Resolution Strategies:**
- `use_server` - Accept server version (default)
- `use_client` - Override with client version
- `merge` - Merge both versions (field-level)

**Response:** `200 OK`
```json
{
  "resolved": [
    {
      "entity_type": "products",
      "client_uuid": "prod-550e8400-e29b-41d4-a716-446655440001",
      "server_id": 5,
      "final_version": 3,
      "resolution_applied": "use_server",
      "data": {
        "id": 5,
        "base_selling_price": 949.99,
        "version": 3
      }
    }
  ]
}
```

---

### 6. Check Sync Status

**GET** `/sync/status`

Get synchronization status for device.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
X-Device-Id: {device_id}
```

**Response:** `200 OK`
```json
{
  "device": {
    "device_id": "POS-MAIN-001",
    "status": "active",
    "last_seen_at": "2026-02-08T10:00:00.000000Z",
    "last_sync_at": "2026-02-08T09:00:00.000000Z"
  },
  "pending_changes": {
    "server_to_client": 5,
    "client_to_server": 0
  },
  "last_session": {
    "session_id": "660e8400-e29b-41d4-a716-446655440000",
    "direction": "pull",
    "status": "completed",
    "records_pulled": 5,
    "records_pushed": 0,
    "conflicts": 0,
    "started_at": "2026-02-08T09:00:00.000000Z",
    "completed_at": "2026-02-08T09:00:15.000000Z"
  },
  "sync_health": {
    "status": "healthy",
    "last_successful_sync": "2026-02-08T09:00:00.000000Z",
    "pending_conflicts": 0,
    "sync_lag_minutes": 60
  }
}
```

---

### 7. Send Heartbeat

**POST** `/sync/heartbeat`

Keep device registration active and check for urgent updates.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
X-Device-Id: {device_id}
```

**Request:**
```json
{
  "status": "online",
  "pending_local_changes": 3
}
```

**Response:** `200 OK`
```json
{
  "message": "Heartbeat received",
  "server_time": "2026-02-08T10:00:00.000000Z",
  "pending_server_changes": 5,
  "requires_sync": true,
  "system_messages": [
    {
      "type": "info",
      "message": "System maintenance scheduled for 2026-02-09 02:00 UTC"
    }
  ]
}
```

---

### Synchronization Best Practices

#### Client Implementation

**1. Initial Setup:**
```javascript
// Register device on first launch
const device = await registerDevice({
  device_id: generateDeviceId(),
  device_type: 'web',
  device_name: 'POS Terminal 1',
  branch_id: 1
});

// Bootstrap initial data
const initialData = await bootstrap({
  entities: ['products', 'customers', 'payment_methods']
});

// Store locally (IndexedDB, SQLite, etc.)
await localDB.bulkInsert(initialData);
```

**2. Regular Sync Cycle:**
```javascript
async function syncData() {
  try {
    // Pull server changes
    const serverChanges = await pullChanges({
      last_sync_at: await localDB.getLastSyncTime(),
      entities: ['products', 'customers', 'sales']
    });
    
    // Apply server changes locally
    await localDB.applyChanges(serverChanges);
    
    // Push local changes
    const localChanges = await localDB.getPendingChanges();
    const pushResult = await pushChanges(localChanges);
    
    // Handle conflicts
    if (pushResult.conflicts.length > 0) {
      const resolutions = await resolveConflicts(pushResult.conflicts);
      await submitResolutions(resolutions);
    }
    
    // Update sync timestamp
    await localDB.setLastSyncTime(new Date());
    
  } catch (error) {
    console.error('Sync failed:', error);
    // Queue for retry
  }
}

// Sync every 5 minutes
setInterval(syncData, 5 * 60 * 1000);
```

**3. Offline Operation:**
```javascript
async function createSale(saleData) {
  // Generate client UUID
  const clientUUID = generateUUID();
  
  // Create locally with sync metadata
  const sale = {
    ...saleData,
    client_uuid: clientUUID,
    version: 1,
    sync_status: 'pending',
    device_id: deviceId,
    origin: 'client'
  };
  
  // Save to local database
  await localDB.sales.insert(sale);
  
  // Try immediate sync if online
  if (navigator.onLine) {
    await syncData();
  }
  
  return sale;
}
```

**4. Conflict Resolution:**
```javascript
async function resolveConflicts(conflicts) {
  return conflicts.map(conflict => {
    // Default: server wins
    let resolution = 'use_server';
    
    // Custom logic for specific entities
    if (conflict.entity_type === 'sales') {
      // Sales are immutable - always use client version
      resolution = 'use_client';
    }
    
    if (conflict.entity_type === 'products') {
      // For products, prefer newer timestamp
      const clientTime = new Date(conflict.client_data.updated_at);
      const serverTime = new Date(conflict.server_data.updated_at);
      resolution = clientTime > serverTime ? 'use_client' : 'use_server';
    }
    
    return {
      entity_type: conflict.entity_type,
      client_uuid: conflict.client_uuid,
      resolution
    };
  });
}
```

#### Server-Side Guarantees

- **Idempotency:** Same `client_uuid` won't create duplicates
- **Version Control:** Optimistic locking prevents lost updates
- **Transaction Safety:** All sync operations are atomic
- **Audit Trail:** Complete history in `change_logs` table

#### Network Resilience

- **Heartbeat:** Send every 30-60 seconds when online
- **Retry Logic:** Exponential backoff for failed syncs
- **Partial Sync:** Continue even if some entities fail
- **Compression:** Large datasets are gzip compressed

#### Performance Tips

- **Batch Operations:** Send multiple changes in one push
- **Selective Sync:** Only sync relevant entities for device
- **Incremental Pull:** Use `last_sync_at` to minimize data transfer
- **Local Indexing:** Index `client_uuid` and `version` fields

#### Security Considerations

- **Authentication:** All endpoints require valid Bearer token
- **Device Validation:** `X-Device-Id` header must match registered device
- **Business Isolation:** Sync respects business_id boundaries
- **Branch Filtering:** Devices only sync data for assigned branch

---

## Server-to-Server Synchronization Module

Edge ↔ Cloud sync for multi-location deployments. All routes require `auth:sanctum` and `business.context` (X-Business-Id).

### 1. Push to Cloud (Edge → Cloud)

**POST** `/server-sync/push`

Edge server pushes local changes to the cloud. Sends sales, customers, and other entities created/updated on the edge.

**Headers:** `Authorization: Bearer {token}`, `X-Business-Id: {id}`

**Request:** JSON body with session id, entities, and change payloads as defined by the server-sync protocol.

**Response:** `200 OK` with accept/reject counts and mappings.

---

### 2. Pull from Cloud (Edge ← Cloud)

**POST** `/server-sync/pull`

Edge server pulls changes from the cloud since last sync.

**Headers:** `Authorization: Bearer {token}`, `X-Business-Id: {id}`

**Request:** `last_sync_at`, optional `entities`, limit.

**Response:** `200 OK` with `changes` (created/updated/deleted per entity type).

---

### 3. Server-Sync Status

**GET** `/server-sync/status`

Returns sync status for the current business (pending counts, last sync times).

**Headers:** `Authorization: Bearer {token}`, `X-Business-Id: {id}`

**Response:** `200 OK`

---

### 4. Server-Sync Health

**GET** `/server-sync/health`

Health check for the server-sync service.

**Response:** `200 OK`

---

### 5. Receive from Edge (Cloud)

**POST** `/server-sync/receive`

Cloud server receives data pushed by an edge server. Used internally by the sync flow.

**Headers:** `Authorization: Bearer {token}`, `X-Business-Id: {id}`

---

### 6. Provide Changes to Edge (Cloud)

**POST** `/server-sync/provide-changes`

Cloud server provides changes to an edge server (pull response). Used internally by the sync flow.

**Headers:** `Authorization: Bearer {token}`, `X-Business-Id: {id}`

---

## Error Handling

### Standard Error Response

All errors follow this structure:

```json
{
  "message": "Error message description",
  "errors": {
    "field_name": [
      "Validation error message"
    ]
  }
}
```

### HTTP Status Codes

| Code | Meaning | Usage |
|------|---------|-------|
| 200 | OK | Successful GET, PUT, PATCH |
| 201 | Created | Successful POST (resource created) |
| 204 | No Content | Successful DELETE |
| 400 | Bad Request | Invalid request data |
| 401 | Unauthorized | Missing or invalid authentication |
| 403 | Forbidden | Authenticated but lacks permission |
| 404 | Not Found | Resource doesn't exist |
| 422 | Unprocessable Entity | Validation failed |
| 429 | Too Many Requests | Rate limit exceeded |
| 500 | Internal Server Error | Server error |

### Common Error Scenarios

#### 1. Validation Error

**Status:** `422 Unprocessable Entity`

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": [
      "The email field is required."
    ],
    "price": [
      "The price must be greater than 0."
    ]
  }
}
```

---

#### 2. Authentication Error

**Status:** `401 Unauthorized`

```json
{
  "message": "Unauthenticated."
}
```

---

#### 3. Permission Error

**Status:** `403 Forbidden`

```json
{
  "message": "You do not have permission to perform this action.",
  "required_permission": "create_product"
}
```

---

#### 4. Business Context Missing

**Status:** `400 Bad Request`

```json
{
  "message": "Business context is required. Provide X-Business-Id header or business_id parameter."
}
```

---

#### 5. Resource Not Found

**Status:** `404 Not Found`

```json
{
  "message": "Product not found"
}
```

---

#### 6. Business Logic Error

**Status:** `400 Bad Request`

```json
{
  "message": "Insufficient stock available",
  "details": {
    "requested": 50,
    "available": 20
  }
}
```

---

## Appendix

### A. Permission List

Complete list of available permissions:

**Authentication:**
- `use-pin-login` - Use PIN for authentication
- `manage-pin-codes` - Set/manage PIN codes for users

**Business & Branch:**
- `view business`, `edit business`, `delete business`
- `view branch`, `create branch`, `edit branch`, `delete branch`
- `view all branches` (access to all branches)

**Products:**
- `view products`, `create products`, `edit products`, `delete products`
- `view category`, `create category`, `edit category`, `delete category`

**Inventory:**
- `view inventory`, `manage inventory`
- `view batch`, `edit batch`
- `write off stock`

**Sales:**
- `view sales`, `create sales`, `cancel sales`
- `view all shifts`, `view user shift`, `create shift`, `close shift`
- `approve shift discrepancy`

**Approvals:**
- `approve quick sale`, `request quick sale`
- `approve refund`, `request refund`
- `approve stock transfer`, `confirm stock transfer`, `request stock transfer`

**Customers & Payments:**
- `view customers`, `create customers`, `edit customers`, `delete customers`
- `manage payment methods`

**Analytics & Reporting:**
- `view analytics`

**Users & Roles:**
- `manage users`, `manage roles`

---

### B. Business Settings Structure

```json
{
  "tax_rate": 0.15,
  "currency": "USD",
  "currency_symbol": "$",
  "date_format": "Y-m-d",
  "time_format": "H:i",
  "timezone": "UTC",
  "low_stock_threshold": 10,
  "near_expiry_days": 30,
  "shift_discrepancy_threshold": 50.00,
  "enable_loyalty": true,
  "loyalty_points_rate": 0.01,
  "enable_batch_tracking": true,
  "default_payment_method": 1
}
```

---

### C. Workflow States

**Sale Status:**
- `pending` - Sale created but not completed
- `completed` - Sale finalized
- `cancelled` - Sale cancelled

**Payment Status:**
- `unpaid` - No payment received
- `partial` - Partial payment received
- `paid` - Fully paid

**Shift Status:**
- `open` - Shift is active
- `closed` - Shift closed, pending approval
- `approved` - Shift approved by manager

**Quick Sale Status:**
- `pending` - Awaiting approval
- `approved` - Approved and active
- `rejected` - Rejected by manager
- `ended` - Promotion ended

**Refund Request Status:**
- `pending` - Awaiting approval
- `approved` - Approved and processed
- `rejected` - Rejected

**Stock Transfer Status:**
- `pending` - Awaiting approval
- `approved` - Approved, ready to ship
- `in_transit` - Shipped, awaiting receipt
- `completed` - Received and confirmed
- `rejected` - Rejected
- `cancelled` - Cancelled

**Batch Status:**
- `active` - Available for sale
- `near_expiry` - Approaching expiry date
- `expired` - Past expiry date
- `sold_out` - All quantity sold

---

### D. Integration Checklist

When integrating with this API:

1. **Authentication:**
   - [ ] Implement login flow
   - [ ] Store and manage Bearer tokens
   - [ ] Handle token refresh/expiration
   - [ ] Implement PIN login for POS

2. **Business Context:**
   - [ ] Allow business selection
   - [ ] Include X-Business-Id in all requests
   - [ ] Handle multi-business users

3. **Permissions:**
   - [ ] Check user permissions before showing UI
   - [ ] Handle permission errors gracefully
   - [ ] Implement role-based UI visibility

4. **Error Handling:**
   - [ ] Handle all HTTP status codes
   - [ ] Display validation errors
   - [ ] Implement retry logic for failures
   - [ ] Show user-friendly error messages

5. **Data Management:**
   - [ ] Implement pagination for lists
   - [ ] Cache frequently accessed data
   - [ ] Implement search and filtering
   - [ ] Handle offline scenarios

6. **POS Features:**
   - [ ] Implement shift management
   - [ ] Handle FEFO inventory allocation
   - [ ] Support multi-payment transactions
   - [ ] Implement refund workflow

7. **Analytics:**
   - [ ] Fetch and display analytics data
   - [ ] Implement date range selection
   - [ ] Create charts and visualizations

---

### E. Rate Limiting

API implements rate limiting:

- **Default:** 60 requests per minute per user
- **Headers:**
  - `X-RateLimit-Limit` - Maximum requests allowed
  - `X-RateLimit-Remaining` - Requests remaining
  - `X-RateLimit-Reset` - Unix timestamp when limit resets

**Response when exceeded:** `429 Too Many Requests`

```json
{
  "message": "Too many requests. Please try again later.",
  "retry_after": 60
}
```

---

### F. Webhook Events (Future)

Planned webhook support for:

- `sale.created` - New sale completed
- `shift.closed` - Shift closed
- `refund.approved` - Refund processed
- `stock.low` - Stock below threshold
- `batch.near_expiry` - Batch approaching expiry
- `transfer.completed` - Stock transfer completed

---

### G. Complete API Route Reference

Every API route. Base path: `/api`. All protected routes require `Authorization: Bearer {token}`. Routes under *Business context* also require `X-Business-Id` (or `business_id` query).

| Method | Path | Description |
|--------|------|-------------|
| **Public** | | |
| POST | `register` | Register new user |
| POST | `login` | Email/password login |
| POST | `pin-login` | 6-digit PIN login (use-pin-login) |
| **Auth only** | | |
| GET | `user` | Current authenticated user |
| PUT | `user` | Update profile (name, profile_image) |
| POST | `pin/set` | Set/update PIN (user_id, pin_code, password if own) |
| POST | `pin/remove` | Remove PIN (user_id, password if own) |
| POST | `business-details-with-branch-auth` | Get business + branch by auth_code (body: auth_code only; business derived from code) |
| GET | `businesses` | List user's businesses |
| POST | `businesses` | Create business |
| GET | `permissions` | List all permissions (global) |
| **Business context** | | |
| GET | `businesses/{id}` | Get business |
| PUT | `businesses/{id}` | Update business |
| DELETE | `businesses/{id}` | Delete business |
| GET | `branches` | List branches |
| POST | `branches` | Create branch |
| POST | `branches/generate-auth-codes` | Generate auth codes for all permitted branches (2-min expiry) |
| GET | `branches/{id}` | Get branch |
| PUT | `branches/{id}` | Update branch |
| DELETE | `branches/{id}` | Delete branch |
| GET | `roles` | List roles |
| POST | `roles` | Create role |
| POST | `roles/addpermission` | Add permission to role |
| POST | `roles/removepermission` | Remove permission from role |
| GET | `roles/{id}` | Get role |
| PUT | `roles/{id}` | Update role |
| DELETE | `roles/{id}` | Delete role |
| POST | `roles/assign` | Assign role to user |
| POST | `roles/remove` | Remove role from user |
| GET | `users/{userId}/roles` | Get user roles in business |
| GET | `business-users` | List business users |
| POST | `business-users` | Add user to business |
| GET | `business-users/{userId}` | Get business user |
| PUT | `business-users/{userId}` | Update business user |
| DELETE | `business-users/{userId}` | Remove user from business |
| GET | `categories` | List categories |
| POST | `categories` | Create category |
| GET | `categories/{id}` | Get category |
| PUT | `categories/{id}` | Update category |
| DELETE | `categories/{id}` | Delete category |
| GET | `categories/{id}/breadcrumb` | Category breadcrumb |
| GET | `products` | List products |
| POST | `products` | Create product |
| GET | `products/{id}` | Get product |
| PUT | `products/{id}` | Update product |
| DELETE | `products/{id}` | Delete product |
| POST | `products/{id}/branches` | Add product to branch |
| DELETE | `products/{id}/branches` | Remove product from branch |
| PATCH | `products/{id}/price` | Update product price |
| GET | `branches/{branchId}/products` | Products by branch |
| GET | `branch-products` | List branch products |
| GET | `branch-products/by-category` | Branch products by category |
| POST | `branch-products` | Create branch product |
| POST | `branch-products/assign-multiple` | Assign multiple products to branch |
| GET | `branch-products/{id}` | Get branch product |
| PUT | `branch-products/{id}` | Update branch product |
| PATCH | `branch-products/{id}/selling-price` | Update selling price |
| DELETE | `branch-products/{id}` | Delete branch product |
| POST | `branch-products/{id}/stock` | Update stock (add/subtract/set) |
| POST | `branch-products/{id}/move-to-shelf` | Move quantity to shelf |
| POST | `branch-products/{id}/move-to-store` | Move quantity to store |
| GET | `branch-products/summary/stock` | Stock summary |
| POST | `branch-products/bulk-update` | Bulk update branch products |
| GET | `inventory/transactions` | List inventory transactions |
| POST | `inventory/transactions` | Create inventory transaction |
| GET | `inventory/transactions/{id}` | Get transaction |
| GET | `inventory/stock-summary` | Stock summary |
| GET | `customers` | List customers |
| POST | `customers` | Create customer |
| GET | `customers/{id}` | Get customer |
| PUT | `customers/{id}` | Update customer |
| DELETE | `customers/{id}` | Delete customer |
| GET | `payment-methods` | List payment methods |
| POST | `payment-methods` | Create payment method |
| GET | `payment-methods/{id}` | Get payment method |
| PUT | `payment-methods/{id}` | Update payment method |
| DELETE | `payment-methods/{id}` | Delete payment method |
| GET | `sales` | List sales |
| POST | `sales` | Create sale |
| GET | `sales/{id}` | Get sale |
| POST | `sales/{id}/payments` | Add payment to sale |
| POST | `sales/{id}/cancel` | Cancel sale |
| GET | `shifts` | List shifts |
| POST | `shifts` | Open shift |
| GET | `shifts/current` | Current open/paused shift |
| GET | `shifts/{id}` | Get shift details |
| GET | `shifts/{id}/sales` | Shift sales (paginated) |
| POST | `shifts/{id}/close` | Close shift |
| POST | `shifts/{id}/pause` | Pause shift |
| POST | `shifts/{id}/resume` | Resume shift (pin_code required) |
| POST | `shifts/{id}/resolve-discrepancy` | Resolve cash discrepancy |
| GET | `batches` | List batches |
| GET | `batches/near-expiry` | Near-expiry batches |
| GET | `batches/expired` | Expired batches |
| GET | `batches/{id}` | Get batch |
| PATCH | `batches/{id}` | Update batch |
| GET | `products/{id}/batches` | Batches for product |
| GET | `analytics/organization` | Organization analytics |
| GET | `analytics/branches` | Branch analytics |
| GET | `analytics/products` | Product analytics |
| GET | `analytics/profit-loss` | Profit & loss |
| GET | `analytics/growth-trends` | Growth trends |
| GET | `stock-transfer-requests` | List stock transfer requests |
| POST | `stock-transfer-requests` | Create request |
| GET | `stock-transfer-requests/{id}` | Get request |
| POST | `stock-transfer-requests/{id}/approve` | Approve |
| POST | `stock-transfer-requests/{id}/reject` | Reject |
| POST | `stock-transfer-requests/{id}/confirm` | Confirm receipt |
| POST | `stock-transfer-requests/{id}/cancel` | Cancel |
| GET | `shelf-store-move-requests` | List shelf/store move requests |
| POST | `shelf-store-move-requests` | Create move request |
| GET | `shelf-store-move-requests/{id}` | Get move request |
| POST | `shelf-store-move-requests/{id}/approve` | Approve and perform move |
| POST | `shelf-store-move-requests/{id}/reject` | Reject move request |
| GET | `stock-writeoffs` | List stock write-offs |
| POST | `stock-writeoffs` | Create write-off |
| GET | `stock-writeoffs/{id}` | Get write-off |
| GET | `refund-requests` | List refund requests |
| POST | `refund-requests` | Create refund request |
| GET | `refund-requests/{id}` | Get request |
| POST | `refund-requests/{id}/approve` | Approve |
| POST | `refund-requests/{id}/reject` | Reject |
| GET | `quick-sales` | List quick sales |
| POST | `quick-sales` | Request quick sale |
| GET | `quick-sales/{id}` | Get quick sale |
| POST | `quick-sales/{id}/approve` | Approve |
| POST | `quick-sales/{id}/reject` | Reject |
| POST | `quick-sales/{id}/end` | End active quick sale |
| POST | `sync/register-device` | Register device (X-Device-Id) |
| POST | `sync/bootstrap` | Bootstrap initial data |
| POST | `sync/pull` | Pull changes since last_sync_at |
| POST | `sync/push` | Push client changes |
| POST | `sync/resolve-conflicts` | Resolve sync conflicts |
| GET | `sync/status` | Sync status |
| POST | `sync/heartbeat` | Heartbeat (X-Device-Id) |
| POST | `server-sync/push` | Edge push to cloud |
| POST | `server-sync/pull` | Edge pull from cloud |
| GET | `server-sync/status` | Server-sync status |
| GET | `server-sync/health` | Server-sync health |
| POST | `server-sync/receive` | Cloud receive from edge |
| POST | `server-sync/provide-changes` | Cloud provide changes to edge |

---

**Last Updated:** February 2026  
**API Version:** 1.0  
**Documentation Version:** 1.0
