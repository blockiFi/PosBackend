# Frontend Integration Guide
## POS Backend API - Complete Integration Documentation

**Version:** 1.0.0  
**Last Updated:** February 8, 2026  
**API Base URL:** `http://localhost:8000/api` (Development)

---

## Table of Contents

1. [Overview](#overview)
2. [Data Models Reference](#data-models-reference)
3. [Authentication System](#authentication-system)
4. [Business Context Management](#business-context-management)
5. [API Request Patterns](#api-request-patterns)
6. [Error Handling](#error-handling)
7. [Module Integration Guides](#module-integration-guides)
8. [State Management Recommendations](#state-management-recommendations)
9. [Permission System](#permission-system)
10. [Real-time Features](#real-time-features)
11. [Best Practices](#best-practices)

---

## Overview

### System Architecture

This POS Backend API is a **multi-tenant**, **role-based** Laravel application using:
- **Sanctum** for API authentication (Bearer tokens)
- **Spatie Laravel-Permission** for granular access control
- **Business context isolation** via `X-Business-Id` header
- **RESTful endpoints** with JSON responses

### Key Concepts

**Multi-Tenancy:**
- Each user can belong to multiple businesses
- All data is isolated by business context
- Business context is set via HTTP header

**Role-Based Access Control (RBAC):**
- Permissions are scoped to business teams
- Users can have different roles in different businesses
- Frontend must respect permission checks

**Shift-Based Operations:**
- Sales transactions require an active shift
- Shifts track cashier accountability
- One active shift per user at a time

---

## Data Models Reference

### Complete Data Structure

This section provides TypeScript/JavaScript interfaces for all data models in the system. Use these as reference when working with API responses.

### Core Business Models

#### User

```typescript
interface User {
  id: number;
  name: string;
  email: string;
  email_verified_at: string | null;
  pin_code: string | null;  // Hashed, never returned in responses
  current_business_id: number | null;
  created_at: string;
  updated_at: string;
  
  // Relationships (when included)
  businesses?: Business[];
  roles?: Role[];
  permissions?: string[];
}
```

**Notes:**
- Users can belong to multiple businesses
- `pin_code` is for quick POS login (never returned in API)
- Permissions are business-scoped

#### Business

```typescript
interface Business {
  id: number;
  uuid: string;
  owner_id: number;
  name: string;
  legal_name: string | null;
  slug: string;
  email: string;
  phone: string | null;
  address: string | null;
  city: string | null;
  state: string | null;
  postal_code: string | null;
  country: string | null;
  currency: string;  // Default: 'USD'
  time_zone: string | null;
  tax_registration_number: string | null;
  default_tax_rate: number;  // Decimal
  settings: {
    [key: string]: any;
  } | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  
  // Relationships
  owner?: User;
  users?: User[];
  branches?: Branch[];
}
```

**Usage:**
```javascript
// Creating a business
const business = {
  name: "My Retail Store",
  email: "contact@mystore.com",
  currency: "USD",
  default_tax_rate: 7.5
};
```

#### Branch

```typescript
interface Branch {
  id: number;
  uuid: string;
  business_id: number;
  name: string;
  code: string | null;
  is_main: boolean;
  email: string | null;
  phone: string | null;
  address: string | null;
  city: string | null;
  state: string | null;
  postal_code: string | null;
  country: string | null;
  time_zone: string | null;
  tax_rate: number | null;  // Decimal
  settings: {
    [key: string]: any;
  } | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  
  // Relationships
  business?: Business;
  products?: Product[];
  branchProducts?: BranchProduct[];
}
```

### Product Models

#### Product

```typescript
interface Product {
  id: number;
  uuid: string;
  business_id: number;
  category_id: number | null;
  name: string;
  sku: string;
  barcode: string | null;
  description: string | null;
  image: string | null;
  base_cost_price: number;  // Decimal
  base_selling_price: number;  // Decimal
  is_taxable: boolean;
  default_tax_rate: number | null;  // Decimal
  unit_of_measure: string | null;
  weight: number | null;  // Decimal
  weight_unit: string | null;
  stock_tracking: boolean;
  low_stock_threshold: number | null;
  is_active: boolean;
  is_available_online: boolean;
  meta_data: {
    [key: string]: any;
  } | null;
  sort_order: number | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  
  // Relationships
  business?: Business;
  category?: ProductCategory;
  branches?: Branch[];
  branchProducts?: BranchProduct[];
  
  // Computed attributes (when included)
  total_stock?: number;
  stock_by_branch?: {
    branch_id: number;
    stock_quantity: number;
  }[];
}
```

**Example:**
```javascript
const product = {
  name: "Premium Widget XL",
  sku: "WDG-XL-001",
  category_id: 1,
  base_selling_price: 299.99,
  base_cost_price: 150.00,
  stock_tracking: true,
  is_active: true
};
```

#### ProductCategory

```typescript
interface ProductCategory {
  id: number;
  uuid: string;
  business_id: number;
  parent_id: number | null;
  name: string;
  slug: string;
  description: string | null;
  image: string | null;
  sort_order: number | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  
  // Relationships
  business?: Business;
  parent?: ProductCategory;
  children?: ProductCategory[];
  products?: Product[];
}
```

**Hierarchy Support:**
```javascript
// Parent category
{
  id: 1,
  name: "Electronics",
  parent_id: null,
  children: [
    { id: 2, name: "Laptops", parent_id: 1 },
    { id: 3, name: "Phones", parent_id: 1 }
  ]
}
```

#### BranchProduct (Pivot/Junction)

```typescript
interface BranchProduct {
  id: number;
  branch_id: number;
  product_id: number;
  cost_price: number | null;  // Branch-specific override
  selling_price: number | null;  // Branch-specific override
  compare_price: number | null;
  discount_amount: number | null;
  discount_type: string | null;
  tax_rate: number | null;
  stock_quantity: number;  // Total stock
  shelf_quantity: number;  // Stock on shelf (FEFO allocation)
  store_quantity: number;  // Stock in storage/backroom
  low_stock_threshold: number | null;
  allow_backorder: boolean;
  reorder_point: number | null;
  reorder_quantity: number | null;
  is_available: boolean;
  is_featured: boolean;
  display_order: number | null;
  bin_location: string | null;
  shelf_location: string | null;
  branch_meta_data: {
    [key: string]: any;
  } | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  
  // Relationships
  branch?: Branch;
  product?: Product;
}
```

**Usage:**
```javascript
// Product with branch-specific data
{
  id: 5,
  name: "Widget XL",
  base_selling_price: 299.99,
  pivot: {  // BranchProduct data
    branch_id: 1,
    selling_price: 319.99,  // Branch override
    stock_quantity: 45,
    shelf_quantity: 20,
    store_quantity: 25
  }
}
```

### Inventory Models

#### InventoryTransaction

```typescript
interface InventoryTransaction {
  id: number;
  uuid: string;
  business_id: number;
  branch_id: number;
  product_id: number;
  user_id: number | null;
  batch_id: number | null;
  type: 'purchase' | 'sale' | 'adjustment' | 'transfer_out' | 'transfer_in' | 
        'return' | 'damage' | 'initial';
  quantity: number;  // Can be negative for deductions
  shelf_quantity: number | null;
  store_quantity: number | null;
  quantity_before: number;
  shelf_quantity_before: number | null;
  store_quantity_before: number | null;
  quantity_after: number;
  shelf_quantity_after: number | null;
  store_quantity_after: number | null;
  unit_cost: number | null;  // Decimal
  total_cost: number | null;  // Decimal
  related_branch_id: number | null;  // For transfers
  related_transaction_id: number | null;
  reference_number: string | null;
  notes: string | null;
  meta_data: {
    [key: string]: any;
  } | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  
  // Relationships
  business?: Business;
  branch?: Branch;
  product?: Product;
  user?: User;
  batch?: ProductBatch;
}
```

**Transaction Types:**
- `purchase` - Receiving stock from supplier
- `sale` - Stock sold to customer (auto-created by sales)
- `adjustment` - Manual stock corrections
- `transfer_out` / `transfer_in` - Inter-branch transfers
- `return` - Customer returns
- `damage` - Damaged/spoiled goods write-off

#### ProductBatch

```typescript
interface ProductBatch {
  id: number;
  uuid: string;
  business_id: number;
  branch_id: number;
  product_id: number;
  batch_number: string;  // Auto-generated or custom
  lot_number: string | null;
  manufacturing_date: string | null;  // Date
  expiry_date: string | null;  // Date
  received_quantity: number;
  current_quantity: number;  // Remaining quantity
  unit_cost: number;  // Decimal
  supplier_name: string | null;
  supplier_reference: string | null;
  inventory_transaction_id: number | null;
  status: 'active' | 'depleted' | 'expired' | 'recalled';
  meta_data: {
    [key: string]: any;
  } | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  
  // Relationships
  business?: Business;
  branch?: Branch;
  product?: Product;
  inventoryTransaction?: InventoryTransaction;
  
  // Computed attributes
  remaining_quantity?: number;  // Alias for current_quantity
  days_until_expiry?: number;
  is_expired?: boolean;
  is_near_expiry?: boolean;
}
```

**FEFO Allocation:**
```javascript
// System automatically allocates from batches expiring soonest
// Frontend displays expiry information
{
  product_id: 5,
  batches: [
    {
      batch_number: "BATCH-001",
      expiry_date: "2026-03-15",
      current_quantity: 50,
      days_until_expiry: 35
    },
    {
      batch_number: "BATCH-002",
      expiry_date: "2026-06-20",
      current_quantity: 100,
      days_until_expiry: 132
    }
  ]
}
```

### Sales Models

#### Sale

```typescript
interface Sale {
  id: number;
  sale_number: string;  // Auto-generated unique ID
  business_id: number;
  branch_id: number;
  customer_id: number | null;
  user_id: number;  // Cashier/sales person
  shift_id: number | null;
  sale_date: string;  // DateTime
  subtotal: number;  // Decimal
  tax_amount: number;  // Decimal
  discount_amount: number;  // Decimal
  total_amount: number;  // Decimal (subtotal + tax - discount)
  status: 'pending' | 'completed' | 'voided';
  payment_status: 'unpaid' | 'partial' | 'paid' | 'overpaid';
  paid_amount: number;  // Decimal
  is_refunded: boolean;
  refunded_at: string | null;  // DateTime
  sale_type: string | null;
  notes: string | null;
  metadata: {
    [key: string]: any;
  } | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  
  // Relationships
  business?: Business;
  branch?: Branch;
  customer?: Customer;
  user?: User;
  shift?: SalesShift;
  items?: SaleItem[];
  payments?: Payment[];
}
```

**Complete Sale Example:**
```javascript
{
  id: 123,
  sale_number: "SALE-2026-001234",
  branch_id: 1,
  shift_id: 45,
  sale_date: "2026-02-08T14:30:00Z",
  subtotal: 599.98,
  discount_amount: 50.00,
  tax_amount: 0,
  total_amount: 549.98,
  status: "completed",
  payment_status: "paid",
  items: [
    {
      product_id: 5,
      product_name: "Widget XL",
      quantity: 2,
      unit_price: 299.99,
      total: 599.98
    }
  ],
  payments: [
    {
      payment_method_id: 1,
      amount: 549.98,
      reference_number: "CASH-001"
    }
  ]
}
```

#### SaleItem

```typescript
interface SaleItem {
  id: number;
  sale_id: number;
  product_id: number;
  product_name: string;  // Snapshot at time of sale
  product_sku: string | null;
  quantity: number;  // Decimal (supports fractional quantities)
  unit_price: number;  // Decimal
  discount_amount: number;  // Decimal
  discount_percentage: number | null;  // Decimal
  tax_rate: number | null;  // Decimal
  tax_amount: number;  // Decimal
  subtotal: number;  // Decimal (quantity * unit_price)
  total: number;  // Decimal (subtotal - discount + tax)
  metadata: {
    [key: string]: any;
  } | null;
  created_at: string;
  updated_at: string;
  
  // Relationships
  sale?: Sale;
  product?: Product;
}
```

#### Payment

```typescript
interface Payment {
  id: number;
  sale_id: number;
  payment_method_id: number;
  amount: number;  // Decimal
  reference_number: string | null;
  payment_date: string;  // DateTime
  status: 'pending' | 'completed' | 'failed' | 'refunded';
  notes: string | null;
  metadata: {
    [key: string]: any;
  } | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  
  // Relationships
  sale?: Sale;
  paymentMethod?: PaymentMethod;
}
```

**Multi-Payment Example:**
```javascript
// Sale with split payment
{
  total_amount: 500.00,
  payments: [
    {
      payment_method_id: 1,  // Cash
      amount: 300.00,
      status: "completed"
    },
    {
      payment_method_id: 2,  // Card
      amount: 200.00,
      reference_number: "TXN-12345",
      status: "completed"
    }
  ]
}
```

#### PaymentMethod

```typescript
interface PaymentMethod {
  id: number;
  business_id: number;
  name: string;  // "Cash", "Credit Card", "Mobile Money"
  type: string;  // "cash", "card", "digital", "other"
  description: string | null;
  account_details: {
    [key: string]: any;
  } | null;
  is_active: boolean;
  sort_order: number | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  
  // Relationships
  business?: Business;
  payments?: Payment[];
}
```

### Shift Management

#### SalesShift

```typescript
interface SalesShift {
  id: number;
  shift_number: string;  // Auto-generated
  business_id: number;
  branch_id: number;
  user_id: number;  // Cashier
  start_time: string;  // DateTime
  end_time: string | null;  // DateTime
  opening_balance: number;  // Decimal - Cash at start
  expected_cash: number | null;  // Decimal - Calculated
  actual_cash: number | null;  // Decimal - Counted at close
  cash_sales: number;  // Decimal
  card_sales: number;  // Decimal
  other_sales: number;  // Decimal
  total_sales: number;  // Decimal
  transactions_count: number;
  variance: number | null;  // Decimal (actual - expected)
  status: 'open' | 'closed' | 'reconciled';
  opening_notes: string | null;
  closing_notes: string | null;
  metadata: {
    [key: string]: any;
  } | null;
  discrepancy_resolved: boolean;
  discrepancy_resolved_at: string | null;
  discrepancy_resolved_by: number | null;
  resolution_notes: string | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  
  // Relationships
  business?: Business;
  branch?: Branch;
  user?: User;
  resolvedBy?: User;
  sales?: Sale[];
  
  // Computed
  duration?: number;  // Minutes
  payment_breakdown?: {
    method_name: string;
    total: number;
  }[];
}
```

**Shift Lifecycle:**
```javascript
// Opening
{
  shift_number: "SHIFT-2026-02-08-001",
  branch_id: 1,
  opening_balance: 500.00,
  status: "open"
}

// Active (during sales)
{
  cash_sales: 1250.00,
  card_sales: 890.00,
  total_sales: 2140.00,
  transactions_count: 45
}

// Closing
{
  status: "closed",
  end_time: "2026-02-08T18:00:00Z",
  expected_cash: 1750.00,  // opening_balance + cash_sales
  actual_cash: 1765.00,    // Counted
  variance: 15.00          // Overage
}
```

### Customer Management

#### Customer

```typescript
interface Customer {
  id: number;
  business_id: number;
  customer_code: string | null;
  name: string;
  email: string | null;
  phone: string | null;
  address: string | null;
  type: 'retail' | 'wholesale' | 'vip' | null;
  credit_limit: number | null;  // Decimal
  outstanding_balance: number;  // Decimal
  loyalty_points: number;
  metadata: {
    [key: string]: any;
  } | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  
  // Relationships
  business?: Business;
  sales?: Sale[];
  
  // Computed attributes
  total_purchases?: number;
  last_purchase_date?: string;
  lifetime_value?: number;
}
```

### Permission & Role Models

#### Role

```typescript
interface Role {
  id: number;
  name: string;
  guard_name: string;
  team_id: number | null;  // Business ID (team-scoped)
  created_at: string;
  updated_at: string;
  
  // Relationships
  permissions?: Permission[];
  users?: User[];
}
```

**Common Roles:**
- `Owner` - Full access
- `Manager` - Management operations
- `Cashier` - POS operations only
- `Inventory Manager` - Stock management
- `Accountant` - Financial reports

#### Permission

```typescript
interface Permission {
  id: number;
  name: string;
  guard_name: string;
  created_at: string;
  updated_at: string;
  
  // Relationships
  roles?: Role[];
}
```

**Permission List:**
```typescript
type PermissionName = 
  // Products
  | 'view products'
  | 'manage products'
  | 'view categories'
  | 'manage categories'
  
  // Sales
  | 'view sales'
  | 'create sales'
  | 'void sales'
  
  // Inventory
  | 'view inventory'
  | 'manage inventory'
  | 'view batches'
  | 'manage batches'
  
  // Shifts
  | 'manage shifts'
  | 'view shift reports'
  
  // Analytics
  | 'view analytics'
  | 'view financial reports'
  
  // Approvals
  | 'request quick sale'
  | 'approve quick sale'
  | 'request refund'
  | 'approve refund'
  
  // Business
  | 'manage business'
  | 'manage branches'
  | 'manage users';
```

### Response Wrappers

#### Paginated Response

```typescript
interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number;
  to: number;
  links: {
    first: string;
    last: string;
    prev: string | null;
    next: string | null;
  };
}
```

**Example:**
```javascript
{
  data: [...],  // Array of resources
  current_page: 2,
  last_page: 10,
  per_page: 20,
  total: 195
}
```

#### Single Resource Response

```typescript
interface ResourceResponse<T> {
  message?: string;
  [resourceName: string]: T | string | undefined;
}

// Examples:
interface ProductResponse {
  message: string;
  product: Product;
}

interface SaleResponse {
  message: string;
  sale: Sale;
}
```

#### Validation Error Response

```typescript
interface ValidationErrorResponse {
  message: string;
  errors: {
    [fieldName: string]: string[];
  };
}
```

**Example:**
```javascript
{
  message: "The given data was invalid.",
  errors: {
    email: ["The email field is required."],
    password: ["The password must be at least 8 characters."]
  }
}
```

### Analytics Response Models

#### Organization Analytics

```typescript
interface OrganizationAnalytics {
  period: string;
  start_date: string;
  end_date: string;
  revenue: number;
  cost: number;
  gross_profit: number;
  gross_margin: number;  // Percentage
  transaction_count: number;
  average_transaction_value: number;
  unique_customers: number;
  
  // Period comparison
  comparison?: {
    revenue_growth: number;  // Percentage
    profit_growth: number;
    transaction_growth: number;
  };
  
  // Branch breakdown
  branch_contributions?: {
    branch_id: number;
    branch_name: string;
    revenue: number;
    percentage: number;
  }[];
  
  // Daily trends
  revenue_trends?: {
    date: string;
    revenue: number;
    transactions: number;
  }[];
}
```

#### Product Analytics

```typescript
interface ProductAnalytics {
  product_id: number;
  product_name: string;
  product_sku: string;
  category_name: string | null;
  quantity_sold: number;
  revenue: number;
  cost: number;
  profit: number;
  profit_margin: number;  // Percentage
  average_sale_price: number;
}
```

### Usage Examples

#### Creating a Complete Sale

```typescript
interface CreateSaleRequest {
  branch_id: number;
  shift_id: number;
  customer_id?: number;
  items: {
    product_id: number;
    quantity: number;
    price: number;
    discount_amount?: number;
  }[];
  payments: {
    payment_method_id: number;
    amount: number;
    reference?: string;
  }[];
  discount_amount?: number;
  tax_amount?: number;
  notes?: string;
}

const saleData: CreateSaleRequest = {
  branch_id: 1,
  shift_id: 45,
  items: [
    {
      product_id: 5,
      quantity: 2,
      price: 299.99
    }
  ],
  payments: [
    {
      payment_method_id: 1,
      amount: 599.98
    }
  ]
};
```

#### Recording Inventory Purchase

```typescript
interface InventoryPurchaseRequest {
  branch_id: number;
  product_id: number;
  type: 'purchase';
  quantity: number;
  unit_cost: number;
  batch_number?: string;
  lot_number?: string;
  manufacturing_date?: string;
  expiry_date?: string;
  supplier_name?: string;
  supplier_reference?: string;
  notes?: string;
}
```

---

## Authentication System

### Flow Overview

```
┌─────────────┐      ┌──────────────┐      ┌─────────────┐
│  Frontend   │─────▶│ POST /login  │─────▶│   Backend   │
│             │◀─────│  Response    │◀─────│             │
└─────────────┘      └──────────────┘      └─────────────┘
      │                                            │
      │  Store: token, user, business_id          │
      └───────────────────────────────────────────┘
```

### 1. Registration

**Endpoint:** `POST /api/register`

```javascript
// JavaScript/TypeScript Example
async function registerUser(userData) {
  const response = await fetch('http://localhost:8000/api/register', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: JSON.stringify({
      name: userData.name,
      email: userData.email,
      password: userData.password,
      password_confirmation: userData.passwordConfirmation
    })
  });

  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message);
  }

  const data = await response.json();
  return data;
}
```

**Response:**
```json
{
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "current_business_id": null,
    "created_at": "2026-02-08T10:00:00.000000Z"
  },
  "token": "1|abc123def456...",
  "message": "User registered successfully"
}
```

### 2. Login

**Endpoint:** `POST /api/login`

```javascript
async function login(email, password) {
  const response = await fetch('http://localhost:8000/api/login', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: JSON.stringify({ email, password })
  });

  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message || 'Login failed');
  }

  const data = await response.json();
  
  // Store authentication data
  localStorage.setItem('auth_token', data.token);
  localStorage.setItem('user', JSON.stringify(data.user));
  if (data.user.current_business_id) {
    localStorage.setItem('business_id', data.user.current_business_id);
  }
  
  return data;
}
```

**Response:**
```json
{
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "current_business_id": 1
  },
  "token": "1|abc123def456ghi789...",
  "message": "Login successful"
}
```

### 3. Logout

**Endpoint:** `POST /api/logout`

```javascript
async function logout() {
  const token = localStorage.getItem('auth_token');
  
  await fetch('http://localhost:8000/api/logout', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json'
    }
  });
  
  // Clear local storage
  localStorage.removeItem('auth_token');
  localStorage.removeItem('user');
  localStorage.removeItem('business_id');
  
  // Redirect to login
  window.location.href = '/login';
}
```

### 4. Get Current User

**Endpoint:** `GET /api/user`

```javascript
async function getCurrentUser() {
  const token = localStorage.getItem('auth_token');
  
  const response = await fetch('http://localhost:8000/api/user', {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json'
    }
  });
  
  return await response.json();
}
```

---

## Business Context Management

### Understanding Business Context

Every API request (except authentication) requires the `X-Business-Id` header to establish business context. This ensures data isolation in the multi-tenant system.

### Setting Business Context

```javascript
// API Client Setup
class ApiClient {
  constructor() {
    this.baseURL = 'http://localhost:8000/api';
  }

  getHeaders() {
    const token = localStorage.getItem('auth_token');
    const businessId = localStorage.getItem('business_id');
    
    const headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    };
    
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }
    
    if (businessId) {
      headers['X-Business-Id'] = businessId;
    }
    
    return headers;
  }

  async get(endpoint) {
    const response = await fetch(`${this.baseURL}${endpoint}`, {
      method: 'GET',
      headers: this.getHeaders()
    });
    
    return this.handleResponse(response);
  }

  async post(endpoint, data) {
    const response = await fetch(`${this.baseURL}${endpoint}`, {
      method: 'POST',
      headers: this.getHeaders(),
      body: JSON.stringify(data)
    });
    
    return this.handleResponse(response);
  }

  async put(endpoint, data) {
    const response = await fetch(`${this.baseURL}${endpoint}`, {
      method: 'PUT',
      headers: this.getHeaders(),
      body: JSON.stringify(data)
    });
    
    return this.handleResponse(response);
  }

  async delete(endpoint) {
    const response = await fetch(`${this.baseURL}${endpoint}`, {
      method: 'DELETE',
      headers: this.getHeaders()
    });
    
    return this.handleResponse(response);
  }

  async handleResponse(response) {
    if (!response.ok) {
      const error = await response.json();
      throw new ApiError(error.message, response.status, error);
    }
    
    return await response.json();
  }
}

// Custom error class
class ApiError extends Error {
  constructor(message, status, data) {
    super(message);
    this.status = status;
    this.data = data;
  }
}

// Create singleton instance
const api = new ApiClient();
export default api;
```

### Multi-Business Support

```javascript
// React Example - Business Switcher Component
import { useState, useEffect } from 'react';
import api from './api-client';

function BusinessSwitcher() {
  const [businesses, setBusinesses] = useState([]);
  const [currentBusinessId, setCurrentBusinessId] = useState(
    localStorage.getItem('business_id')
  );

  useEffect(() => {
    loadBusinesses();
  }, []);

  async function loadBusinesses() {
    try {
      const response = await api.get('/businesses');
      setBusinesses(response.data);
    } catch (error) {
      console.error('Failed to load businesses:', error);
    }
  }

  function switchBusiness(businessId) {
    localStorage.setItem('business_id', businessId);
    setCurrentBusinessId(businessId);
    
    // Reload the page or trigger global state update
    window.location.reload();
  }

  return (
    <div className="business-switcher">
      <select 
        value={currentBusinessId} 
        onChange={(e) => switchBusiness(e.target.value)}
      >
        {businesses.map(business => (
          <option key={business.id} value={business.id}>
            {business.name}
          </option>
        ))}
      </select>
    </div>
  );
}
```

---

## API Request Patterns

### Common Patterns

#### 1. List Resources (Pagination)

```javascript
async function getProducts(page = 1, search = '', categoryId = null) {
  let endpoint = `/products?page=${page}&per_page=20`;
  
  if (search) {
    endpoint += `&search=${encodeURIComponent(search)}`;
  }
  
  if (categoryId) {
    endpoint += `&category_id=${categoryId}`;
  }
  
  const response = await api.get(endpoint);
  
  return {
    products: response.data,
    pagination: {
      current_page: response.current_page,
      last_page: response.last_page,
      per_page: response.per_page,
      total: response.total
    }
  };
}
```

**Response Structure:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Product Name",
      "sku": "SKU-001",
      "base_selling_price": 299.99
    }
  ],
  "current_page": 1,
  "last_page": 5,
  "per_page": 20,
  "total": 95,
  "links": {
    "first": "http://localhost:8000/api/products?page=1",
    "last": "http://localhost:8000/api/products?page=5",
    "prev": null,
    "next": "http://localhost:8000/api/products?page=2"
  }
}
```

#### 2. Create Resource

```javascript
async function createProduct(productData) {
  const response = await api.post('/products', {
    name: productData.name,
    sku: productData.sku,
    category_id: productData.categoryId,
    base_selling_price: parseFloat(productData.price),
    minimum_selling_price: parseFloat(productData.minPrice),
    cost_price: parseFloat(productData.cost),
    track_inventory: true,
    is_active: true
  });
  
  return response.product;
}
```

**Success Response (201 Created):**
```json
{
  "message": "Product created successfully",
  "product": {
    "id": 15,
    "name": "New Product",
    "sku": "SKU-015",
    "base_selling_price": 299.99,
    "created_at": "2026-02-08T10:30:00.000000Z"
  }
}
```

#### 3. Update Resource

```javascript
async function updateProduct(productId, updates) {
  const response = await api.put(`/products/${productId}`, updates);
  return response.product;
}
```

#### 4. Delete Resource

```javascript
async function deleteProduct(productId) {
  await api.delete(`/products/${productId}`);
}
```

---

## Error Handling

### Error Response Format

All errors return a consistent JSON structure:

```json
{
  "message": "Validation failed",
  "errors": {
    "email": ["The email field is required."],
    "password": ["The password must be at least 8 characters."]
  }
}
```

### HTTP Status Codes

| Code | Meaning | Common Causes |
|------|---------|---------------|
| **400** | Bad Request | Missing `X-Business-Id` header, invalid business context |
| **401** | Unauthorized | Missing/invalid authentication token |
| **403** | Forbidden | User lacks required permission |
| **404** | Not Found | Resource doesn't exist or doesn't belong to business |
| **422** | Unprocessable Entity | Validation errors |
| **500** | Server Error | Internal server error |

### Error Handling Implementation

```javascript
// Enhanced API Client with Error Handling
class ApiClient {
  // ... previous code ...

  async handleResponse(response) {
    const data = await response.json();
    
    if (!response.ok) {
      switch (response.status) {
        case 400:
          throw new ApiError(
            'Business context required. Please select a business.',
            400,
            data
          );
        
        case 401:
          // Token expired or invalid
          localStorage.removeItem('auth_token');
          window.location.href = '/login';
          throw new ApiError('Session expired. Please login again.', 401, data);
        
        case 403:
          throw new ApiError(
            'You do not have permission to perform this action.',
            403,
            data
          );
        
        case 404:
          throw new ApiError('Resource not found.', 404, data);
        
        case 422:
          // Validation errors
          throw new ValidationError(data.message, data.errors);
        
        default:
          throw new ApiError(
            data.message || 'An unexpected error occurred.',
            response.status,
            data
          );
      }
    }
    
    return data;
  }
}

// Validation Error Class
class ValidationError extends Error {
  constructor(message, errors) {
    super(message);
    this.errors = errors; // { field: [errors] }
  }

  getFieldErrors(field) {
    return this.errors[field] || [];
  }

  getAllErrors() {
    return Object.values(this.errors).flat();
  }
}

// React Hook for Error Display
function useApiError() {
  const [error, setError] = useState(null);

  const handleError = (err) => {
    if (err instanceof ValidationError) {
      setError({
        type: 'validation',
        message: err.message,
        fields: err.errors
      });
    } else if (err instanceof ApiError) {
      setError({
        type: 'api',
        message: err.message,
        status: err.status
      });
    } else {
      setError({
        type: 'unknown',
        message: 'An unexpected error occurred.'
      });
    }
  };

  const clearError = () => setError(null);

  return { error, handleError, clearError };
}
```

---

## Module Integration Guides

### 1. Product Management

#### Product List with Search

```javascript
// React Component Example
import { useState, useEffect } from 'react';
import api from './api-client';

function ProductList() {
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [pagination, setPagination] = useState({});

  useEffect(() => {
    loadProducts();
  }, [search]);

  async function loadProducts(page = 1) {
    setLoading(true);
    try {
      const response = await api.get(
        `/products?page=${page}&search=${search}&per_page=20`
      );
      
      setProducts(response.data);
      setPagination({
        currentPage: response.current_page,
        lastPage: response.last_page,
        total: response.total
      });
    } catch (error) {
      console.error('Failed to load products:', error);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div>
      <input
        type="text"
        placeholder="Search products..."
        value={search}
        onChange={(e) => setSearch(e.target.value)}
      />
      
      {loading ? (
        <div>Loading...</div>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>SKU</th>
              <th>Price</th>
              <th>Stock</th>
            </tr>
          </thead>
          <tbody>
            {products.map(product => (
              <tr key={product.id}>
                <td>{product.name}</td>
                <td>{product.sku}</td>
                <td>${product.base_selling_price}</td>
                <td>{product.total_stock || 0}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      
      {/* Pagination */}
      <div>
        Page {pagination.currentPage} of {pagination.lastPage}
      </div>
    </div>
  );
}
```

#### Create Product Form

```javascript
function ProductForm() {
  const [formData, setFormData] = useState({
    name: '',
    sku: '',
    category_id: '',
    base_selling_price: '',
    minimum_selling_price: '',
    cost_price: '',
    track_inventory: true,
    has_expiry: false
  });
  const [categories, setCategories] = useState([]);
  const { error, handleError, clearError } = useApiError();

  useEffect(() => {
    loadCategories();
  }, []);

  async function loadCategories() {
    try {
      const response = await api.get('/categories');
      setCategories(response.data);
    } catch (err) {
      handleError(err);
    }
  }

  async function handleSubmit(e) {
    e.preventDefault();
    clearError();
    
    try {
      const product = await api.post('/products', formData);
      alert('Product created successfully!');
      // Reset form or redirect
    } catch (err) {
      handleError(err);
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      {error && (
        <div className="error-banner">
          {error.message}
          {error.type === 'validation' && (
            <ul>
              {Object.entries(error.fields).map(([field, errors]) => (
                <li key={field}>{errors.join(', ')}</li>
              ))}
            </ul>
          )}
        </div>
      )}
      
      <input
        type="text"
        placeholder="Product Name"
        value={formData.name}
        onChange={(e) => setFormData({...formData, name: e.target.value})}
        required
      />
      
      <input
        type="text"
        placeholder="SKU"
        value={formData.sku}
        onChange={(e) => setFormData({...formData, sku: e.target.value})}
        required
      />
      
      <select
        value={formData.category_id}
        onChange={(e) => setFormData({...formData, category_id: e.target.value})}
        required
      >
        <option value="">Select Category</option>
        {categories.map(cat => (
          <option key={cat.id} value={cat.id}>{cat.name}</option>
        ))}
      </select>
      
      <input
        type="number"
        step="0.01"
        placeholder="Selling Price"
        value={formData.base_selling_price}
        onChange={(e) => setFormData({...formData, base_selling_price: e.target.value})}
        required
      />
      
      <button type="submit">Create Product</button>
    </form>
  );
}
```

### 2. Inventory Management

#### Recording Purchase (Stock In)

```javascript
async function recordPurchase(purchaseData) {
  return await api.post('/inventory/transactions', {
    branch_id: purchaseData.branchId,
    product_id: purchaseData.productId,
    type: 'purchase',
    quantity: parseInt(purchaseData.quantity),
    unit_cost: parseFloat(purchaseData.unitCost),
    batch_number: purchaseData.batchNumber || null,
    lot_number: purchaseData.lotNumber || null,
    expiry_date: purchaseData.expiryDate || null,
    manufacturing_date: purchaseData.manufacturingDate || null,
    supplier_name: purchaseData.supplierName || null,
    supplier_reference: purchaseData.supplierReference || null,
    notes: purchaseData.notes || ''
  });
}

// Usage Example
const purchase = {
  branchId: 1,
  productId: 5,
  quantity: 100,
  unitCost: 150.00,
  expiryDate: '2027-02-08',
  supplierName: 'ABC Suppliers'
};

await recordPurchase(purchase);
```

#### Get Stock Levels

```javascript
async function getStockLevels(branchId, lowStockOnly = false) {
  let endpoint = `/inventory/stock?branch_id=${branchId}`;
  
  if (lowStockOnly) {
    endpoint += '&low_stock=true';
  }
  
  const response = await api.get(endpoint);
  return response.data;
}
```

### 3. Sales (POS) Integration

#### Complete Sale Flow

```javascript
class POSCart {
  constructor(branchId, shiftId) {
    this.branchId = branchId;
    this.shiftId = shiftId;
    this.items = [];
    this.payments = [];
    this.discountAmount = 0;
  }

  addItem(product, quantity, price) {
    const existingItem = this.items.find(item => item.product_id === product.id);
    
    if (existingItem) {
      existingItem.quantity += quantity;
    } else {
      this.items.push({
        product_id: product.id,
        product_name: product.name,
        quantity: quantity,
        price: price || product.base_selling_price,
        discount_amount: 0
      });
    }
  }

  removeItem(productId) {
    this.items = this.items.filter(item => item.product_id !== productId);
  }

  addPayment(paymentMethodId, amount, reference = '') {
    this.payments.push({
      payment_method_id: paymentMethodId,
      amount: parseFloat(amount),
      reference: reference
    });
  }

  getSubtotal() {
    return this.items.reduce((sum, item) => {
      return sum + (item.quantity * item.price);
    }, 0);
  }

  getTotal() {
    return this.getSubtotal() - this.discountAmount;
  }

  getTotalPaid() {
    return this.payments.reduce((sum, payment) => sum + payment.amount, 0);
  }

  getChange() {
    return this.getTotalPaid() - this.getTotal();
  }

  async completeSale() {
    if (this.items.length === 0) {
      throw new Error('Cart is empty');
    }

    if (this.getTotalPaid() < this.getTotal()) {
      throw new Error('Insufficient payment');
    }

    const saleData = {
      branch_id: this.branchId,
      shift_id: this.shiftId,
      items: this.items,
      payments: this.payments,
      discount_amount: this.discountAmount,
      tax_amount: 0
    };

    const response = await api.post('/sales', saleData);
    return response.sale;
  }
}

// Usage in POS Component
function POSScreen() {
  const [cart, setCart] = useState(null);
  const [products, setProducts] = useState([]);
  const [currentShift, setCurrentShift] = useState(null);

  useEffect(() => {
    initializePOS();
  }, []);

  async function initializePOS() {
    // Load current shift
    const branchId = localStorage.getItem('branch_id');
    const shiftResponse = await api.get(`/shifts/current?branch_id=${branchId}`);
    
    if (!shiftResponse.shift) {
      alert('No active shift. Please open a shift first.');
      return;
    }
    
    setCurrentShift(shiftResponse.shift);
    setCart(new POSCart(branchId, shiftResponse.shift.id));
    
    // Load products
    const productsResponse = await api.get('/products?per_page=100');
    setProducts(productsResponse.data);
  }

  async function handleCompleteSale() {
    try {
      const sale = await cart.completeSale();
      
      alert(`Sale completed! Sale #${sale.sale_number}`);
      alert(`Change: $${cart.getChange().toFixed(2)}`);
      
      // Reset cart
      setCart(new POSCart(cart.branchId, cart.shiftId));
    } catch (error) {
      alert(`Error: ${error.message}`);
    }
  }

  return (
    <div className="pos-screen">
      <div className="product-grid">
        {products.map(product => (
          <button
            key={product.id}
            onClick={() => cart.addItem(product, 1, product.base_selling_price)}
          >
            {product.name} - ${product.base_selling_price}
          </button>
        ))}
      </div>
      
      <div className="cart">
        <h3>Current Sale</h3>
        {cart?.items.map((item, index) => (
          <div key={index}>
            {item.product_name} x {item.quantity} = ${(item.quantity * item.price).toFixed(2)}
          </div>
        ))}
        
        <div>
          <strong>Total: ${cart?.getTotal().toFixed(2)}</strong>
        </div>
        
        <button onClick={handleCompleteSale}>Complete Sale</button>
      </div>
    </div>
  );
}
```

### 4. Shift Management

#### Opening a Shift

```javascript
async function openShift(branchId, openingBalance, notes = '') {
  const response = await api.post('/shifts', {
    branch_id: branchId,
    opening_balance: parseFloat(openingBalance),
    opening_notes: notes
  });
  
  return response.shift;
}

// React Component
function ShiftManager() {
  const [currentShift, setCurrentShift] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    loadCurrentShift();
  }, []);

  async function loadCurrentShift() {
    const branchId = localStorage.getItem('branch_id');
    
    try {
      const response = await api.get(`/shifts/current?branch_id=${branchId}`);
      setCurrentShift(response.shift);
    } catch (error) {
      console.error('Failed to load shift:', error);
    } finally {
      setLoading(false);
    }
  }

  async function handleOpenShift(openingBalance) {
    const branchId = localStorage.getItem('branch_id');
    
    try {
      const shift = await openShift(branchId, openingBalance);
      setCurrentShift(shift);
      alert(`Shift opened: ${shift.shift_number}`);
    } catch (error) {
      alert(`Error: ${error.message}`);
    }
  }

  async function handleCloseShift(closingBalance) {
    try {
      const response = await api.post(`/shifts/${currentShift.id}/close`, {
        closing_balance: parseFloat(closingBalance),
        closing_notes: 'End of shift'
      });
      
      const closedShift = response.shift;
      
      // Show variance
      const variance = closedShift.closing_balance - closedShift.expected_balance;
      alert(`Shift closed!\nVariance: $${variance.toFixed(2)}`);
      
      setCurrentShift(null);
    } catch (error) {
      alert(`Error: ${error.message}`);
    }
  }

  if (loading) return <div>Loading...</div>;

  return (
    <div>
      {currentShift ? (
        <div>
          <h3>Active Shift: {currentShift.shift_number}</h3>
          <p>Opened: {new Date(currentShift.opened_at).toLocaleString()}</p>
          <p>Opening Balance: ${currentShift.opening_balance}</p>
          
          <button onClick={() => {
            const balance = prompt('Enter closing cash count:');
            if (balance) handleCloseShift(balance);
          }}>
            Close Shift
          </button>
        </div>
      ) : (
        <div>
          <h3>No Active Shift</h3>
          <button onClick={() => {
            const balance = prompt('Enter opening cash amount:');
            if (balance) handleOpenShift(balance);
          }}>
            Open Shift
          </button>
        </div>
      )}
    </div>
  );
}
```

### 5. Analytics Integration

#### Dashboard Statistics

```javascript
async function getDashboardAnalytics(period = 'month') {
  const [orgAnalytics, branchAnalytics, productAnalytics] = await Promise.all([
    api.get(`/analytics/organization?period=${period}&compare_previous=true`),
    api.get(`/analytics/branches?period=${period}`),
    api.get(`/analytics/products?period=${period}&limit=10&sort_by=revenue`)
  ]);

  return {
    organization: orgAnalytics,
    branches: branchAnalytics,
    topProducts: productAnalytics
  };
}

// React Dashboard Component
function Dashboard() {
  const [analytics, setAnalytics] = useState(null);
  const [period, setPeriod] = useState('month');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    loadAnalytics();
  }, [period]);

  async function loadAnalytics() {
    setLoading(true);
    try {
      const data = await getDashboardAnalytics(period);
      setAnalytics(data);
    } catch (error) {
      console.error('Failed to load analytics:', error);
    } finally {
      setLoading(false);
    }
  }

  if (loading) return <div>Loading analytics...</div>;

  const org = analytics.organization;

  return (
    <div className="dashboard">
      <div className="period-selector">
        <select value={period} onChange={(e) => setPeriod(e.target.value)}>
          <option value="today">Today</option>
          <option value="week">This Week</option>
          <option value="month">This Month</option>
          <option value="year">This Year</option>
        </select>
      </div>

      <div className="stats-grid">
        <div className="stat-card">
          <h4>Total Revenue</h4>
          <p className="stat-value">${org.revenue.toLocaleString()}</p>
          {org.comparison && (
            <p className={org.comparison.revenue_growth >= 0 ? 'positive' : 'negative'}>
              {org.comparison.revenue_growth >= 0 ? '↑' : '↓'} 
              {Math.abs(org.comparison.revenue_growth).toFixed(1)}%
            </p>
          )}
        </div>

        <div className="stat-card">
          <h4>Gross Profit</h4>
          <p className="stat-value">${org.gross_profit.toLocaleString()}</p>
          <p className="stat-detail">{org.gross_margin.toFixed(1)}% margin</p>
        </div>

        <div className="stat-card">
          <h4>Transactions</h4>
          <p className="stat-value">{org.transaction_count.toLocaleString()}</p>
          <p className="stat-detail">
            Avg: ${org.average_transaction_value.toFixed(2)}
          </p>
        </div>
      </div>

      <div className="top-products">
        <h3>Top Products</h3>
        <table>
          <thead>
            <tr>
              <th>Product</th>
              <th>Revenue</th>
              <th>Qty Sold</th>
              <th>Profit</th>
            </tr>
          </thead>
          <tbody>
            {analytics.topProducts.data.map(product => (
              <tr key={product.product_id}>
                <td>{product.product_name}</td>
                <td>${product.revenue.toLocaleString()}</td>
                <td>{product.quantity_sold}</td>
                <td>${product.profit.toFixed(2)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
```

### 6. Batch & Expiry Management

#### Near-Expiry Alerts

```javascript
async function getNearExpiryProducts(days = 30) {
  const branchId = localStorage.getItem('branch_id');
  const response = await api.get(
    `/batches/near-expiry?days=${days}&branch_id=${branchId}`
  );
  
  return response.data;
}

function ExpiryAlerts() {
  const [nearExpiry, setNearExpiry] = useState([]);
  const [expired, setExpired] = useState([]);

  useEffect(() => {
    loadExpiryData();
  }, []);

  async function loadExpiryData() {
    const branchId = localStorage.getItem('branch_id');
    
    const [nearExpiryData, expiredData] = await Promise.all([
      api.get(`/batches/near-expiry?days=30&branch_id=${branchId}`),
      api.get(`/batches/expired?branch_id=${branchId}`)
    ]);
    
    setNearExpiry(nearExpiryData.data);
    setExpired(expiredData.data);
  }

  return (
    <div>
      {expired.length > 0 && (
        <div className="alert alert-danger">
          <h4>⚠️ Expired Products ({expired.length})</h4>
          <ul>
            {expired.map(batch => (
              <li key={batch.id}>
                {batch.product.name} - Batch #{batch.batch_number}
                (Expired: {batch.expiry_date})
                - {batch.remaining_quantity} units
              </li>
            ))}
          </ul>
        </div>
      )}

      {nearExpiry.length > 0 && (
        <div className="alert alert-warning">
          <h4>⏰ Expiring Soon ({nearExpiry.length})</h4>
          <ul>
            {nearExpiry.map(batch => (
              <li key={batch.id}>
                {batch.product.name} - Batch #{batch.batch_number}
                (Expires: {batch.expiry_date})
                - {batch.remaining_quantity} units
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
```

---

## State Management Recommendations

### Using React Context (Simple Apps)

```javascript
// AuthContext.js
import { createContext, useState, useContext, useEffect } from 'react';
import api from './api-client';

const AuthContext = createContext();

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [token, setToken] = useState(localStorage.getItem('auth_token'));
  const [businessId, setBusinessId] = useState(localStorage.getItem('business_id'));
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (token) {
      loadUser();
    } else {
      setLoading(false);
    }
  }, [token]);

  async function loadUser() {
    try {
      const userData = await api.get('/user');
      setUser(userData);
    } catch (error) {
      // Token invalid
      logout();
    } finally {
      setLoading(false);
    }
  }

  async function login(email, password) {
    const response = await api.post('/login', { email, password });
    
    setToken(response.token);
    setUser(response.user);
    
    if (response.user.current_business_id) {
      setBusinessId(response.user.current_business_id);
      localStorage.setItem('business_id', response.user.current_business_id);
    }
    
    localStorage.setItem('auth_token', response.token);
    localStorage.setItem('user', JSON.stringify(response.user));
    
    return response;
  }

  function logout() {
    setToken(null);
    setUser(null);
    setBusinessId(null);
    localStorage.removeItem('auth_token');
    localStorage.removeItem('user');
    localStorage.removeItem('business_id');
  }

  function switchBusiness(newBusinessId) {
    setBusinessId(newBusinessId);
    localStorage.setItem('business_id', newBusinessId);
  }

  return (
    <AuthContext.Provider value={{
      user,
      token,
      businessId,
      loading,
      login,
      logout,
      switchBusiness,
      isAuthenticated: !!token
    }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  return useContext(AuthContext);
}
```

### Using Redux (Complex Apps)

```javascript
// store/slices/authSlice.js
import { createSlice, createAsyncThunk } from '@reduxjs/toolkit';
import api from '../../api-client';

export const login = createAsyncThunk(
  'auth/login',
  async ({ email, password }) => {
    const response = await api.post('/login', { email, password });
    
    localStorage.setItem('auth_token', response.token);
    localStorage.setItem('user', JSON.stringify(response.user));
    
    if (response.user.current_business_id) {
      localStorage.setItem('business_id', response.user.current_business_id);
    }
    
    return response;
  }
);

const authSlice = createSlice({
  name: 'auth',
  initialState: {
    user: JSON.parse(localStorage.getItem('user')),
    token: localStorage.getItem('auth_token'),
    businessId: localStorage.getItem('business_id'),
    loading: false,
    error: null
  },
  reducers: {
    logout: (state) => {
      state.user = null;
      state.token = null;
      state.businessId = null;
      localStorage.clear();
    },
    switchBusiness: (state, action) => {
      state.businessId = action.payload;
      localStorage.setItem('business_id', action.payload);
    }
  },
  extraReducers: (builder) => {
    builder
      .addCase(login.pending, (state) => {
        state.loading = true;
        state.error = null;
      })
      .addCase(login.fulfilled, (state, action) => {
        state.loading = false;
        state.user = action.payload.user;
        state.token = action.payload.token;
        state.businessId = action.payload.user.current_business_id;
      })
      .addCase(login.rejected, (state, action) => {
        state.loading = false;
        state.error = action.error.message;
      });
  }
});

export const { logout, switchBusiness } = authSlice.actions;
export default authSlice.reducer;
```

---

## Permission System

### Understanding Permissions

The backend uses **Spatie Laravel-Permission** with **team (business) scoping**. Users can have different permissions in different businesses.

### Common Permissions

| Permission | Description |
|------------|-------------|
| `view products` | View product list |
| `manage products` | Create, edit, delete products |
| `view sales` | View sales transactions |
| `create sales` | Process POS transactions |
| `void sales` | Void/cancel sales |
| `manage shifts` | Open and close shifts |
| `view analytics` | View basic analytics |
| `view financial reports` | View P&L and financial data |
| `request quick sale` | Request below-min-price approval |
| `approve quick sale` | Approve quick sale requests |
| `request refund` | Request refund approval |
| `approve refund` | Approve refund requests |
| `view batches` | View batch information |
| `manage batches` | Update batch status |

### Checking Permissions in Frontend

```javascript
// Permission Check Hook
function usePermission() {
  const [permissions, setPermissions] = useState([]);

  useEffect(() => {
    loadPermissions();
  }, []);

  async function loadPermissions() {
    try {
      const response = await api.get('/user/permissions');
      setPermissions(response.permissions);
    } catch (error) {
      console.error('Failed to load permissions:', error);
    }
  }

  function hasPermission(permission) {
    return permissions.includes(permission);
  }

  function hasAnyPermission(permissionsList) {
    return permissionsList.some(p => permissions.includes(p));
  }

  function hasAllPermissions(permissionsList) {
    return permissionsList.every(p => permissions.includes(p));
  }

  return { permissions, hasPermission, hasAnyPermission, hasAllPermissions };
}

// Usage in Components
function ProductManagement() {
  const { hasPermission } = usePermission();

  return (
    <div>
      <h2>Products</h2>
      
      {hasPermission('manage products') && (
        <button onClick={handleCreateProduct}>
          Create New Product
        </button>
      )}
      
      <ProductList readOnly={!hasPermission('manage products')} />
    </div>
  );
}

// Protected Route Component
function ProtectedRoute({ permission, children }) {
  const { hasPermission } = usePermission();
  
  if (!hasPermission(permission)) {
    return <div>Access Denied. You don't have permission to view this page.</div>;
  }
  
  return children;
}

// Usage
<ProtectedRoute permission="view analytics">
  <AnalyticsDashboard />
</ProtectedRoute>
```

### Pre-loading User Permissions

```javascript
// Load permissions on login
async function loadUserPermissions() {
  const response = await api.get('/user/permissions');
  localStorage.setItem('permissions', JSON.stringify(response.permissions));
  return response.permissions;
}

// Quick permission check without API call
function hasPermissionLocal(permission) {
  const permissions = JSON.parse(localStorage.getItem('permissions') || '[]');
  return permissions.includes(permission);
}
```

---

## Real-time Features

### Polling for Updates

For real-time-like features without WebSockets:

```javascript
// Polling Hook
function usePolling(fetchFunction, interval = 30000) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    async function poll() {
      try {
        const result = await fetchFunction();
        setData(result);
      } catch (err) {
        setError(err);
      }
    }

    // Initial fetch
    poll();

    // Set up polling
    const intervalId = setInterval(poll, interval);

    return () => clearInterval(intervalId);
  }, [fetchFunction, interval]);

  return { data, error };
}

// Usage - Real-time shift status
function ShiftStatus() {
  const branchId = localStorage.getItem('branch_id');
  
  const { data: shift } = usePolling(
    async () => {
      const response = await api.get(`/shifts/current?branch_id=${branchId}`);
      return response.shift;
    },
    10000 // Poll every 10 seconds
  );

  return (
    <div>
      {shift ? (
        <div>Active Shift: {shift.shift_number}</div>
      ) : (
        <div>No active shift</div>
      )}
    </div>
  );
}
```

### Server-Sent Events (SSE) for Notifications

If the backend implements SSE endpoints:

```javascript
function useSSE(endpoint) {
  const [events, setEvents] = useState([]);

  useEffect(() => {
    const token = localStorage.getItem('auth_token');
    const eventSource = new EventSource(
      `${API_BASE_URL}${endpoint}?token=${token}`
    );

    eventSource.onmessage = (event) => {
      const data = JSON.parse(event.data);
      setEvents(prev => [...prev, data]);
    };

    eventSource.onerror = (error) => {
      console.error('SSE Error:', error);
      eventSource.close();
    };

    return () => eventSource.close();
  }, [endpoint]);

  return events;
}
```

---

## Best Practices

### 1. API Client Singleton

Create a single API client instance to:
- Centralize configuration
- Handle authentication globally
- Implement request/response interceptors
- Cache responses when appropriate

### 2. Error Handling

```javascript
// Global error handler
window.addEventListener('unhandledrejection', (event) => {
  if (event.reason instanceof ApiError) {
    if (event.reason.status === 401) {
      // Redirect to login
      window.location.href = '/login';
    }
  }
});
```

### 3. Request Debouncing for Search

```javascript
import { useState, useEffect } from 'react';
import { debounce } from 'lodash';

function SearchableProductList() {
  const [search, setSearch] = useState('');
  const [products, setProducts] = useState([]);

  useEffect(() => {
    const debouncedSearch = debounce(async (query) => {
      const response = await api.get(`/products?search=${query}`);
      setProducts(response.data);
    }, 500);

    debouncedSearch(search);

    return () => debouncedSearch.cancel();
  }, [search]);

  return (
    <input
      type="text"
      value={search}
      onChange={(e) => setSearch(e.target.value)}
      placeholder="Search products..."
    />
  );
}
```

### 4. Optimistic Updates

```javascript
async function deleteProduct(productId) {
  // Optimistically remove from UI
  setProducts(products.filter(p => p.id !== productId));
  
  try {
    await api.delete(`/products/${productId}`);
  } catch (error) {
    // Rollback on error
    loadProducts();
    alert('Failed to delete product');
  }
}
```

### 5. Caching with React Query

```javascript
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';

function useProducts(search = '') {
  return useQuery({
    queryKey: ['products', search],
    queryFn: async () => {
      const response = await api.get(`/products?search=${search}`);
      return response.data;
    },
    staleTime: 5 * 60 * 1000 // 5 minutes
  });
}

function useCreateProduct() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (productData) => api.post('/products', productData),
    onSuccess: () => {
      // Invalidate and refetch
      queryClient.invalidateQueries({ queryKey: ['products'] });
    }
  });
}

// Usage
function ProductList() {
  const { data: products, isLoading } = useProducts();
  const createProduct = useCreateProduct();

  if (isLoading) return <div>Loading...</div>;

  return <div>{/* Render products */}</div>;
}
```

### 6. Offline Support

```javascript
// Queue for offline operations
class OfflineQueue {
  constructor() {
    this.queue = JSON.parse(localStorage.getItem('offline_queue') || '[]');
  }

  add(operation) {
    this.queue.push({
      ...operation,
      timestamp: Date.now(),
      id: Math.random().toString(36)
    });
    this.save();
  }

  async processQueue() {
    if (!navigator.onLine) return;

    for (const operation of this.queue) {
      try {
        await api[operation.method](operation.endpoint, operation.data);
        this.remove(operation.id);
      } catch (error) {
        console.error('Failed to process queued operation:', error);
      }
    }
  }

  remove(id) {
    this.queue = this.queue.filter(op => op.id !== id);
    this.save();
  }

  save() {
    localStorage.setItem('offline_queue', JSON.stringify(this.queue));
  }
}

const offlineQueue = new OfflineQueue();

// Process queue when online
window.addEventListener('online', () => {
  offlineQueue.processQueue();
});
```

### 7. Type Safety with TypeScript

```typescript
// types.ts
export interface Product {
  id: number;
  name: string;
  sku: string;
  category_id: number;
  base_selling_price: number;
  minimum_selling_price: number;
  cost_price: number;
  track_inventory: boolean;
  has_expiry: boolean;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface Sale {
  id: number;
  business_id: number;
  branch_id: number;
  shift_id: number;
  sale_number: string;
  sale_date: string;
  status: 'pending' | 'completed' | 'voided';
  subtotal: number;
  discount_amount: number;
  tax_amount: number;
  final_total: number;
  items: SaleItem[];
  payments: Payment[];
}

export interface ApiResponse<T> {
  data: T;
  message?: string;
}

export interface PaginatedResponse<T> extends ApiResponse<T[]> {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

// api-client.ts
async function getProducts(search = ''): Promise<PaginatedResponse<Product>> {
  return api.get(`/products?search=${search}`);
}

async function createProduct(data: Partial<Product>): Promise<ApiResponse<Product>> {
  return api.post('/products', data);
}
```

---

## Testing Your Integration

### Testing Checklist

- [ ] **Authentication Flow**
  - Registration works
  - Login returns token
  - Token persists across page reloads
  - Logout clears all data
  - Invalid token redirects to login

- [ ] **Business Context**
  - X-Business-Id header sent on all requests
  - Business switcher updates context
  - API rejects requests without business context

- [ ] **Error Handling**
  - 401 errors redirect to login
  - 403 errors show permission message
  - Validation errors display field-specific messages
  - Network errors handled gracefully

- [ ] **Permission System**
  - UI elements hidden based on permissions
  - API requests fail gracefully when unauthorized
  - Permission changes reflect immediately

- [ ] **Data Operations**
  - Create/Read/Update/Delete all working
  - Pagination works correctly
  - Search/filtering returns expected results
  - Multi-item operations succeed

### Example Test Suite (Jest + React Testing Library)

```javascript
// products.test.js
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ProductList from './ProductList';
import api from './api-client';

jest.mock('./api-client');

test('displays products from API', async () => {
  api.get.mockResolvedValue({
    data: [
      { id: 1, name: 'Product 1', sku: 'SKU-001', base_selling_price: 99.99 },
      { id: 2, name: 'Product 2', sku: 'SKU-002', base_selling_price: 149.99 }
    ]
  });

  render(<ProductList />);

  await waitFor(() => {
    expect(screen.getByText('Product 1')).toBeInTheDocument();
    expect(screen.getByText('Product 2')).toBeInTheDocument();
  });
});

test('handles search correctly', async () => {
  api.get.mockResolvedValue({ data: [] });

  render(<ProductList />);

  const searchInput = screen.getByPlaceholderText('Search products...');
  await userEvent.type(searchInput, 'widget');

  await waitFor(() => {
    expect(api.get).toHaveBeenCalledWith('/products?search=widget');
  });
});
```

---

## Additional Resources

### Postman Collection

Import the complete Postman collection for interactive API testing:
- **File:** `POS_Backend_Complete_API.postman_collection.json`
- **Includes:** 100+ endpoints with examples
- **Auto-saves:** Tokens and IDs for easy testing

### API Documentation Files

- **`ANALYTICS_IMPLEMENTATION_SUMMARY.md`** - Analytics endpoints details
- **`ANALYTICS_API.md`** - Analytics API reference
- **`BUSINESS_ISOLATION.md`** - Multi-tenancy architecture
- **`SALES_SHIFT_IMPLEMENTATION.md`** - Shift management details
- **`SHELF_STORE_INVENTORY_SYSTEM.md`** - FEFO inventory system
- **`BRANCH_ACCESS_CONTROL.md`** - Branch access patterns

### Support

For questions or issues:
1. Check error messages in API responses
2. Verify authentication token is valid
3. Confirm X-Business-Id header is set
4. Check user has required permissions
5. Review Laravel logs at `storage/logs/laravel.log`

---

## Quick Reference

### Common Headers

```javascript
{
  'Content-Type': 'application/json',
  'Accept': 'application/json',
  'Authorization': 'Bearer {token}',
  'X-Business-Id': '{business_id}'
}
```

### Common Query Parameters

- `page` - Pagination page number
- `per_page` - Items per page (default: 15)
- `search` - Search query
- `filter` - Predefined filter (today, week, month, etc.)
- `start_date` / `end_date` - Date range filters
- `sort_by` / `direction` - Sorting options

### Response Status Codes

- `200` - Success
- `201` - Created
- `204` - No Content (successful deletion)
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `422` - Validation Error
- `500` - Server Error

---

**Last Updated:** February 8, 2026  
**API Version:** 1.0.0  
**Documentation Version:** 1.0.0
