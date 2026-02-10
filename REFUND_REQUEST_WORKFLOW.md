# Refund Request Workflow API

## Overview

The Refund Request System implements a secure, role-based workflow for processing sales refunds with proper audit logging, inventory restoration, and authorization controls.

## Features

- ✅ **Role-Based Access Control**: Separate permissions for requesting vs approving refunds
- ✅ **Workflow States**: pending → approved/rejected → processed
- ✅ **Duplicate Prevention**: No duplicate pending requests for the same sale
- ✅ **Self-Approval Prevention**: Requesters cannot approve their own requests
- ✅ **Inventory Restoration**: Automatically restores stock when refunds are approved
- ✅ **Audit Trail**: Tracks who requested, who approved/rejected, and when
- ✅ **Business Isolation**: All requests scoped to business context
- ✅ **Branch Access Control**: Respects user branch permissions

## Permissions

| Permission | Description | Actions Allowed |
|------------|-------------|-----------------|
| `request refund` | Can initiate refund requests | Create refund request, view own requests |
| `approve refund` | Can review and approve/reject requests | View all requests, approve/reject requests |

## Refund Request States

```
pending → approved → processed
       ↓
    rejected
```

- **pending**: Request created, awaiting review
- **approved**: Request approved by authorized user
- **rejected**: Request denied by authorized user
- **processed**: Refund completed, inventory restored, sale marked as refunded

## API Endpoints

### 1. List Refund Requests

```http
GET /api/refund-requests
```

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters:**
- `status` (optional): Filter by status (pending, approved, rejected, processed)
- `branch_id` (optional): Filter by branch
- `sale_id` (optional): Filter by sale

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "sale_id": 123,
      "business_id": 1,
      "branch_id": 5,
      "requested_by": 10,
      "reviewed_by": 12,
      "amount": "150.00",
      "reason": "Customer returned damaged product",
      "rejection_reason": null,
      "status": "approved",
      "reviewed_at": "2026-02-07T15:30:00.000000Z",
      "created_at": "2026-02-07T14:00:00.000000Z",
      "updated_at": "2026-02-07T15:30:00.000000Z",
      "sale": {
        "id": 123,
        "sale_number": "SAL-20260207-0001",
        "total_amount": "150.00",
        "customer": {...},
        "branch": {...}
      },
      "requested_by_user": {
        "id": 10,
        "name": "John Cashier"
      },
      "reviewed_by_user": {
        "id": 12,
        "name": "Jane Manager"
      }
    }
  ],
  "links": {...},
  "meta": {...}
}
```

**Authorization:**
- Users with `request refund` permission see only their own requests
- Users with `approve refund` permission see all requests
- Branch filtering respects user's branch access

---

### 2. Create Refund Request

```http
POST /api/refund-requests
```

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
Content-Type: application/json
```

**Request Body:**
```json
{
  "sale_id": 123,
  "reason": "Customer returned damaged product - packaging was torn and product was defective"
}
```

**Validation Rules:**
- `sale_id`: required, must exist
- `reason`: required, string, minimum 10 characters, maximum 1000 characters

**Response (201 Created):**
```json
{
  "message": "Refund request submitted successfully",
  "refund_request": {
    "id": 1,
    "sale_id": 123,
    "business_id": 1,
    "branch_id": 5,
    "requested_by": 10,
    "amount": "150.00",
    "reason": "Customer returned damaged product - packaging was torn and product was defective",
    "status": "pending",
    "created_at": "2026-02-07T14:00:00.000000Z",
    "sale": {...},
    "requested_by_user": {...}
  }
}
```

**Error Responses:**

*Sale already refunded (400):*
```json
{
  "message": "Sale is not eligible for refund",
  "reason": "Sale has already been refunded"
}
```

*Pending request exists (400):*
```json
{
  "message": "A pending refund request already exists for this sale"
}
```

*Unauthorized (403):*
```json
{
  "message": "Unauthorized"
}
```

**Business Rules:**
- Sale must exist and belong to the business
- Sale must have `completed` status
- Sale must not already be refunded
- Sale must not be soft-deleted
- No other pending refund request for the same sale
- User must have access to the sale's branch

---

### 3. View Refund Request Details

```http
GET /api/refund-requests/{id}
```

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response (200 OK):**
```json
{
  "id": 1,
  "sale_id": 123,
  "business_id": 1,
  "branch_id": 5,
  "requested_by": 10,
  "reviewed_by": 12,
  "amount": "150.00",
  "reason": "Customer returned damaged product",
  "rejection_reason": null,
  "status": "processed",
  "reviewed_at": "2026-02-07T15:30:00.000000Z",
  "created_at": "2026-02-07T14:00:00.000000Z",
  "sale": {
    "id": 123,
    "sale_number": "SAL-20260207-0001",
    "total_amount": "150.00",
    "is_refunded": true,
    "refunded_at": "2026-02-07T15:30:00.000000Z",
    "items": [
      {
        "id": 1,
        "product_id": 50,
        "product_name": "Widget Pro",
        "quantity": 10,
        "unit_price": "15.00",
        "total": "150.00",
        "product": {...}
      }
    ],
    "payments": [...],
    "customer": {...}
  },
  "requested_by_user": {
    "id": 10,
    "name": "John Cashier",
    "email": "john@example.com"
  },
  "reviewed_by_user": {
    "id": 12,
    "name": "Jane Manager",
    "email": "jane@example.com"
  }
}
```

**Authorization:**
- Users with `request refund` only: Can only view their own requests
- Users with `approve refund`: Can view all requests
- Must have branch access

---

### 4. Approve Refund Request

```http
POST /api/refund-requests/{id}/approve
```

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Response (200 OK):**
```json
{
  "message": "Refund request approved and processed successfully",
  "refund_request": {
    "id": 1,
    "sale_id": 123,
    "status": "processed",
    "reviewed_by": 12,
    "reviewed_at": "2026-02-07T15:30:00.000000Z",
    "sale": {
      "is_refunded": true,
      "refunded_at": "2026-02-07T15:30:00.000000Z"
    }
  }
}
```

**Error Responses:**

*Self-approval attempt (403):*
```json
{
  "message": "You cannot approve your own refund request"
}
```

*Already processed (400):*
```json
{
  "message": "Only pending refund requests can be approved",
  "current_status": "approved"
}
```

**Processing Steps:**
1. Validates request is pending
2. Prevents self-approval
3. Restores inventory for each sale item
4. Creates inventory transaction records
5. Marks refund request as approved
6. Marks sale as refunded
7. Updates refund request to processed

**Inventory Restoration:**
- Increments `stock_quantity` for each product
- Creates `adjustment` type inventory transaction
- Records before/after quantities
- Includes reference to original sale number

---

### 5. Reject Refund Request

```http
POST /api/refund-requests/{id}/reject
```

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
Content-Type: application/json
```

**Request Body:**
```json
{
  "rejection_reason": "Product was not actually damaged - customer misuse"
}
```

**Validation Rules:**
- `rejection_reason`: required, string, minimum 10 characters, maximum 1000 characters

**Response (200 OK):**
```json
{
  "message": "Refund request rejected",
  "refund_request": {
    "id": 1,
    "sale_id": 123,
    "status": "rejected",
    "reviewed_by": 12,
    "reviewed_at": "2026-02-07T15:45:00.000000Z",
    "rejection_reason": "Product was not actually damaged - customer misuse",
    "sale": {...}
  }
}
```

**Error Responses:**

*Self-rejection attempt (403):*
```json
{
  "message": "You cannot reject your own refund request"
}
```

*Already processed (400):*
```json
{
  "message": "Only pending refund requests can be rejected",
  "current_status": "rejected"
}
```

**Processing:**
- Validates request is pending
- Prevents self-rejection
- Updates status to rejected
- Records reviewer and rejection reason
- Sale remains unchanged (not refunded)

---

## Workflow Example

### Scenario: Customer Returns Damaged Product

**Step 1: Cashier Creates Refund Request**
```bash
POST /api/refund-requests
{
  "sale_id": 123,
  "reason": "Customer returned damaged product - box was crushed during shipping"
}
```

**Step 2: Manager Reviews Request**
```bash
GET /api/refund-requests?status=pending
# Manager sees the pending request
```

**Step 3: Manager Approves (or Rejects)**

*If approved:*
```bash
POST /api/refund-requests/1/approve
# System automatically:
# - Restores inventory
# - Marks sale as refunded
# - Updates request to processed
```

*If rejected:*
```bash
POST /api/refund-requests/1/reject
{
  "rejection_reason": "Product condition acceptable - normal wear and tear"
}
```

---

## Database Schema

### refund_requests Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| sale_id | bigint | Foreign key to sales |
| business_id | bigint | Foreign key to businesses |
| branch_id | bigint | Foreign key to branches |
| requested_by | bigint | User who created the request |
| reviewed_by | bigint | User who approved/rejected (nullable) |
| amount | decimal(10,2) | Refund amount (from sale total) |
| reason | text | Reason for refund request |
| rejection_reason | text | Reason for rejection (nullable) |
| status | enum | pending/approved/rejected/processed |
| reviewed_at | timestamp | When request was reviewed (nullable) |
| created_at | timestamp | When request was created |
| updated_at | timestamp | When request was last updated |

**Indexes:**
- `(business_id, status)` - Fast filtering by business and status
- `(branch_id, status)` - Fast filtering by branch and status
- `(sale_id)` - Fast lookup by sale

### sales Table Updates

New fields added:
- `is_refunded` (boolean) - Whether sale has been refunded
- `refunded_at` (timestamp) - When sale was refunded

---

## Security & Authorization

### Permission Checks
1. **Create Request**: Requires `request refund` permission
2. **View Requests**: Requires either `request refund` OR `approve refund`
3. **Approve/Reject**: Requires `approve refund` permission

### Additional Security Rules
- Users can only create requests for sales in branches they have access to
- Users with only `request refund` can only view their own requests
- Users cannot approve or reject their own requests
- All operations are scoped to the current business context

### Branch Access Control
- Respects user's assigned branches (from `user_business` pivot)
- Empty branch list = access to all branches
- Specific branches = restricted access

---

## Testing

All functionality is covered by comprehensive tests:

```bash
php artisan test --filter=RefundRequestTest
```

**Test Coverage:**
- ✅ User with permission can create refund request
- ✅ User without permission cannot create refund request
- ✅ Cannot create refund request for already refunded sale
- ✅ Cannot create duplicate pending refund request
- ✅ Approver can approve refund request
- ✅ Approver can reject refund request
- ✅ Requester cannot approve their own request
- ✅ Requester cannot reject their own request
- ✅ Cannot approve already processed request
- ✅ Requester can view their own requests
- ✅ Approver can view all requests
- ✅ Can filter refund requests by status

---

## Integration with Existing Systems

### Inventory Management
When a refund is approved:
1. `BranchProduct.stock_quantity` is incremented
2. `InventoryTransaction` record created with:
   - Type: `adjustment`
   - Quantity: positive value (stock restored)
   - Reference: original sale number
   - Notes: "Refund approved for sale: {sale_number}"

### Sales System
- Sale status remains `completed`
- New `is_refunded` flag set to `true`
- `refunded_at` timestamp recorded
- Sale relationship to refund requests maintained

### Audit Trail
Every action is logged:
- Who requested the refund (requested_by)
- When it was requested (created_at)
- Who reviewed it (reviewed_by)
- When it was reviewed (reviewed_at)
- Rejection reason if applicable

---

## Setup Instructions

1. **Run Migrations:**
```bash
php artisan migrate
```

2. **Seed Permissions:**
```bash
php artisan db:seed --class=RefundPermissionSeeder
```

3. **Assign Permissions to Roles:**
```php
// For cashiers/staff who can request refunds
$role->givePermissionTo('request refund');

// For managers who can approve refunds
$role->givePermissionTo('approve refund');
```

---

## Error Handling

All endpoints return consistent error responses:

**Validation Errors (422):**
```json
{
  "message": "Validation failed",
  "errors": {
    "reason": ["The reason field must be at least 10 characters."]
  }
}
```

**Authorization Errors (403):**
```json
{
  "message": "Unauthorized"
}
```

**Business Logic Errors (400):**
```json
{
  "message": "Descriptive error message",
  "reason": "Additional context if applicable"
}
```

**Not Found (404):**
```json
{
  "message": "Refund request not found"
}
```

---

## Best Practices

1. **Request Creation**: Always provide detailed reasons (minimum 10 characters)
2. **Review Process**: Managers should verify product condition before approving
3. **Rejection Reasons**: Provide clear explanations when rejecting requests
4. **Monitoring**: Track pending requests regularly to avoid delays
5. **Audit**: Periodically review refund patterns for fraud detection

---

## Future Enhancements

Potential improvements:
- [ ] Partial refunds (refund specific items, not entire sale)
- [ ] Refund amount adjustments (restocking fees, etc.)
- [ ] Multi-step approval workflow (require multiple approvers)
- [ ] Email notifications for request status changes
- [ ] Refund analytics and reporting
- [ ] Integration with payment gateway for automatic refunds
- [ ] Time limits for refund requests (e.g., 30 days after sale)
