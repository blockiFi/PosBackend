# Shift Statistics API Documentation

## Overview
The Shift Statistics feature allows authorized users to view sales shifts with comprehensive statistics including gross sales, transaction counts, average basket value, payment method breakdown, and reconciliation status.

## Endpoints

### 1. List Shifts with Statistics
**Endpoint:** `GET /api/shifts`

**Authentication:** Required (Sanctum)

**Permission:** `view shifts`

**Query Parameters:**
- `current_business_id` (required): The business ID
- `branch_id` (optional): Filter by specific branch
- `user_id` (optional): Filter by specific user
- `status` (optional): Filter by status (`open` or `closed`)
- `filter` (optional): Quick date filters
  - `today`: Shifts from today only
  - `last_7_days`: Shifts from the last 7 days
- `start_date` (optional): Custom date range start (format: `YYYY-MM-DD HH:MM:SS`)
- `end_date` (optional): Custom date range end (format: `YYYY-MM-DD HH:MM:SS`)

**Example Requests:**

```bash
# View today's shifts
GET /api/shifts?current_business_id=1&filter=today

# View last 7 days shifts
GET /api/shifts?current_business_id=1&filter=last_7_days

# Custom date range
GET /api/shifts?current_business_id=1&start_date=2026-02-01%2000:00:00&end_date=2026-02-07%2023:59:59

# Filter by branch
GET /api/shifts?current_business_id=1&branch_id=5&filter=today

# Filter by status
GET /api/shifts?current_business_id=1&status=closed&filter=last_7_days
```

**Response Structure:**
```json
{
  "data": [
    {
      "id": 1,
      "shift_number": "SHIFT-20260208-0001",
      "business_id": 1,
      "branch_id": 2,
      "user_id": 3,
      "start_time": "2026-02-08T08:00:00.000000Z",
      "end_time": "2026-02-08T16:00:00.000000Z",
      "opening_balance": "100.00",
      "expected_cash": "400.00",
      "actual_cash": "400.00",
      "cash_sales": "300.00",
      "card_sales": "700.00",
      "other_sales": "0.00",
      "total_sales": "1000.00",
      "transactions_count": 20,
      "variance": "0.00",
      "status": "closed",
      "statistics": {
        "gross_sales": 1000.0,
        "total_transactions": 20,
        "average_basket_value": 50.0,
        "payment_breakdown": {
          "pos_percentage": 70.0,
          "cash_percentage": 30.0,
          "pos_amount": 700.0,
          "cash_amount": 300.0
        },
        "reconciliation_status": "balanced",
        "variance": 0.0
      },
      "user": {
        "id": 3,
        "name": "John Doe",
        "email": "john@example.com"
      },
      "branch": {
        "id": 2,
        "name": "Downtown Branch",
        "address": "123 Main St"
      }
    }
  ],
  "current_page": 1,
  "per_page": 15,
  "total": 1
}
```

### 2. View Single Shift with Statistics
**Endpoint:** `GET /api/shifts/{id}`

**Authentication:** Required (Sanctum)

**Permission:** `view shifts`

**Query Parameters:**
- `current_business_id` (required): The business ID

**Example Request:**
```bash
GET /api/shifts/1?current_business_id=1
```

**Response Structure:**
```json
{
  "id": 1,
  "shift_number": "SHIFT-20260208-0001",
  "business_id": 1,
  "branch_id": 2,
  "user_id": 3,
  "start_time": "2026-02-08T08:00:00.000000Z",
  "end_time": "2026-02-08T16:00:00.000000Z",
  "opening_balance": "100.00",
  "expected_cash": "380.00",
  "actual_cash": "360.00",
  "cash_sales": "280.00",
  "card_sales": "720.00",
  "total_sales": "1000.00",
  "transactions_count": 25,
  "variance": "-20.00",
  "status": "closed",
  "statistics": {
    "gross_sales": 1000.0,
    "total_transactions": 25,
    "average_basket_value": 40.0,
    "payment_breakdown": {
      "pos_percentage": 72.0,
      "cash_percentage": 28.0,
      "pos_amount": 720.0,
      "cash_amount": 280.0
    },
    "reconciliation_status": "discrepancy",
    "variance": -20.0
  },
  "sales": [
    {
      "id": 1,
      "sale_number": "SALE-001",
      "total": "45.00",
      "payments": [...]
    }
  ],
  "user": {...},
  "branch": {...}
}
```

## Statistics Fields Explained

### gross_sales
Total sales amount for the shift (sum of all transactions).

### total_transactions
Number of completed sales transactions during the shift.

### average_basket_value
Average amount per transaction. Calculated as: `gross_sales / total_transactions`

### payment_breakdown
Breakdown of payment methods used:
- **pos_percentage**: Percentage of sales paid by card/POS (0-100)
- **cash_percentage**: Percentage of sales paid by cash (0-100)
- **pos_amount**: Total amount paid via card/POS
- **cash_amount**: Total amount paid via cash

### reconciliation_status
Indicates if the shift cash reconciliation is balanced:
- **balanced**: Cash variance is less than $0.01 (essentially zero)
- **discrepancy**: Cash variance is $0.01 or more

### variance
The difference between expected cash and actual cash counted at shift closing. Negative values indicate shortage, positive values indicate overage.

## Use Cases

### 1. View Today's Shifts
```bash
curl -X GET "https://api.example.com/api/shifts?current_business_id=1&filter=today" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

### 2. View Last Week's Performance
```bash
curl -X GET "https://api.example.com/api/shifts?current_business_id=1&filter=last_7_days" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

### 3. Custom Date Range Report
```bash
curl -X GET "https://api.example.com/api/shifts?current_business_id=1&start_date=2026-02-01%2000:00:00&end_date=2026-02-28%2023:59:59" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

### 4. Branch-Specific Report
```bash
curl -X GET "https://api.example.com/api/shifts?current_business_id=1&branch_id=5&filter=last_7_days" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

## Error Responses

### 403 Unauthorized
```json
{
  "message": "Unauthorized"
}
```
User doesn't have the `view shifts` permission.

### 403 Unauthorized - Branch Access
```json
{
  "message": "Unauthorized access to this branch"
}
```
User doesn't have access to the requested branch.

### 404 Not Found
```json
{
  "message": "No query results for model [App\\Models\\SalesShift] {id}"
}
```
Shift with the specified ID doesn't exist or doesn't belong to the business.

## Notes

- All monetary values are returned as floats with 2 decimal precision
- Dates are returned in ISO 8601 format with timezone
- Pagination is set to 15 items per page by default
- Users can only view shifts for branches they have access to
- Branch access is determined by role assignments and business-wide vs branch-specific permissions
