# Revenue Statistics & Analytics API

## Overview

The Analytics module provides comprehensive revenue statistics and financial reporting across multiple organizational levels (organization-wide, branch-level, and product-level). It includes time-based analysis, period comparisons, profit & loss statements, and growth trend analysis.

## Features

- **Organization-wide Analytics**: Total revenue, P&L, growth trends, branch contributions
- **Branch-level Analytics**: Revenue, profit, period comparisons, performance rankings
- **Product-level Analytics**: Top/underperforming products, margins, contribution percentages
- **Time-based Analysis**: Filters for today/week/month/year/custom periods
- **Period Comparisons**: Compare current period with previous period
- **Profit & Loss Statements**: Detailed financial reporting with margins
- **Growth Trends**: Revenue growth analysis with customizable intervals
- **Performance Optimization**: 15-30 minute caching for dashboard queries
- **Role-based Access Control**: Permissions for viewing analytics and financial reports

## Permissions

```
view analytics           - View all analytics endpoints
view financial reports   - View profit & loss statements
view branch analytics    - View branch-specific analytics (optional)
export analytics         - Export analytics data (future feature)
```

## Endpoints

### 1. Organization Analytics

Get organization-wide analytics with optional period comparison.

**Endpoint**: `GET /api/analytics/organization`

**Headers**:
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters**:
```
period          - optional, enum: today|week|month|year|custom (default: month)
start_date      - required if period=custom, format: Y-m-d
end_date        - required if period=custom, format: Y-m-d
compare_previous - optional, boolean (default: true)
```

**Example Request**:
```bash
curl -X GET "http://localhost:8000/api/analytics/organization?period=month&compare_previous=true" \
  -H "Authorization: Bearer {token}" \
  -H "X-Business-Id: {business_id}"
```

**Example Response**:
```json
{
  "period": {
    "start_date": "2026-02-01",
    "end_date": "2026-02-28",
    "days": 28
  },
  "current": {
    "revenue": "125450.75",
    "cost": "78220.50",
    "profit": "47230.25",
    "margin_percentage": "37.65",
    "transaction_count": 1245,
    "average_order_value": "100.72"
  },
  "previous": {
    "revenue": "112300.50",
    "cost": "70150.25",
    "profit": "42150.25",
    "margin_percentage": "37.53",
    "transaction_count": 1156,
    "average_order_value": "97.15"
  },
  "comparison": {
    "revenue_change_percentage": "11.71",
    "profit_change_percentage": "12.05",
    "transaction_change_percentage": "7.70",
    "revenue_trend": "up",
    "profit_trend": "up"
  },
  "branch_contributions": [
    {
      "branch_id": 1,
      "branch_name": "Main Branch",
      "revenue": "75270.45",
      "profit": "28338.17",
      "transaction_count": 748,
      "contribution_percentage": "60.00"
    },
    {
      "branch_id": 2,
      "branch_name": "Downtown Branch",
      "revenue": "50180.30",
      "profit": "18892.08",
      "transaction_count": 497,
      "contribution_percentage": "40.00"
    }
  ],
  "revenue_trend": [
    {
      "date": "2026-02-01",
      "revenue": "4120.50",
      "transactions": 42
    },
    {
      "date": "2026-02-02",
      "revenue": "4580.75",
      "transactions": 46
    }
    // ... daily breakdown
  ]
}
```

---

### 2. Branch Analytics

Get analytics for specific branch or all permitted branches.

**Endpoint**: `GET /api/analytics/branches`

**Headers**:
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters**:
```
branch_id        - optional, specific branch ID (if omitted, returns all permitted branches)
period           - optional, enum: today|week|month|year|custom (default: month)
start_date       - required if period=custom, format: Y-m-d
end_date         - required if period=custom, format: Y-m-d
compare_previous - optional, boolean (default: true)
```

**Example Request**:
```bash
curl -X GET "http://localhost:8000/api/analytics/branches?branch_id=1&period=week" \
  -H "Authorization: Bearer {token}" \
  -H "X-Business-Id: {business_id}"
```

**Example Response**:
```json
{
  "branches": [
    {
      "branch_id": 1,
      "branch_name": "Main Branch",
      "period": {
        "start_date": "2026-02-03",
        "end_date": "2026-02-09",
        "days": 7
      },
      "current": {
        "revenue": "18750.25",
        "cost": "11685.15",
        "profit": "7065.10",
        "margin_percentage": "37.68",
        "transaction_count": 186,
        "average_order_value": "100.81"
      },
      "previous": {
        "revenue": "16820.50",
        "cost": "10512.30",
        "profit": "6308.20",
        "margin_percentage": "37.50",
        "transaction_count": 168,
        "average_order_value": "100.12"
      },
      "comparison": {
        "revenue_change_percentage": "11.48",
        "profit_change_percentage": "12.00",
        "transaction_change_percentage": "10.71",
        "revenue_trend": "up",
        "profit_trend": "up"
      },
      "revenue_trend": [
        {
          "date": "2026-02-03",
          "revenue": "2678.50",
          "transactions": 27
        }
        // ... daily breakdown
      ]
    }
  ]
}
```

---

### 3. Product Analytics

Get product performance analytics with top and bottom performers.

**Endpoint**: `GET /api/analytics/products`

**Headers**:
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters**:
```
branch_id   - optional, filter by specific branch
period      - optional, enum: today|week|month|year|custom (default: month)
start_date  - required if period=custom, format: Y-m-d
end_date    - required if period=custom, format: Y-m-d
limit       - optional, number of top products (1-100, default: 20)
sort_by     - optional, enum: revenue|quantity|profit|margin (default: revenue)
direction   - optional, enum: asc|desc (default: desc)
```

**Example Request**:
```bash
curl -X GET "http://localhost:8000/api/analytics/products?period=month&limit=10&sort_by=revenue" \
  -H "Authorization: Bearer {token}" \
  -H "X-Business-Id: {business_id}"
```

**Example Response**:
```json
{
  "period": {
    "start_date": "2026-02-01",
    "end_date": "2026-02-28"
  },
  "summary": {
    "total_products": 156,
    "total_revenue": "125450.75",
    "total_cost": "78220.50",
    "total_profit": "47230.25",
    "average_margin": "37.65"
  },
  "top_products": [
    {
      "product_id": 42,
      "product_name": "Premium Widget",
      "product_sku": "WIDGET-001",
      "quantity_sold": 245,
      "revenue": "12250.00",
      "cost": "7350.00",
      "profit": "4900.00",
      "margin_percentage": "40.00",
      "transaction_count": 156,
      "contribution_percentage": "9.76"
    },
    {
      "product_id": 18,
      "product_name": "Standard Gadget",
      "product_sku": "GADGET-005",
      "quantity_sold": 412,
      "revenue": "10300.00",
      "cost": "6798.00",
      "profit": "3502.00",
      "margin_percentage": "34.00",
      "transaction_count": 203,
      "contribution_percentage": "8.21"
    }
    // ... more products
  ],
  "bottom_products": [
    {
      "product_id": 89,
      "product_name": "Slow Moving Item",
      "product_sku": "SLOW-003",
      "quantity_sold": 3,
      "revenue": "45.00",
      "cost": "30.00",
      "profit": "15.00",
      "margin_percentage": "33.33",
      "transaction_count": 3,
      "contribution_percentage": "0.04"
    }
    // ... more products
  ]
}
```

---

### 4. Profit & Loss Statement

Get comprehensive P&L statement with revenue breakdown, costs, and margins.

**Endpoint**: `GET /api/analytics/profit-loss`

**Headers**:
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters**:
```
branch_id   - optional, filter by specific branch
period      - optional, enum: today|week|month|quarter|year|custom (default: month)
start_date  - required if period=custom, format: Y-m-d
end_date    - required if period=custom, format: Y-m-d
```

**Permission Required**: `view financial reports`

**Example Request**:
```bash
curl -X GET "http://localhost:8000/api/analytics/profit-loss?period=month" \
  -H "Authorization: Bearer {token}" \
  -H "X-Business-Id: {business_id}"
```

**Example Response**:
```json
{
  "period": {
    "start_date": "2026-02-01",
    "end_date": "2026-02-28"
  },
  "revenue": {
    "gross_revenue": "130550.75",
    "discounts": "5100.00",
    "net_revenue": "125450.75"
  },
  "costs": {
    "cost_of_goods_sold": "78220.50"
  },
  "profit": {
    "gross_profit": "47230.25",
    "net_profit": "47230.25"
  },
  "margins": {
    "gross_margin_percentage": "37.65",
    "net_margin_percentage": "37.65"
  },
  "metrics": {
    "total_transactions": 1245,
    "average_transaction_value": "100.72"
  }
}
```

---

### 5. Growth Trends

Get revenue growth trends over time with customizable intervals.

**Endpoint**: `GET /api/analytics/growth-trends`

**Headers**:
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
```

**Query Parameters**:
```
branch_id  - optional, filter by specific branch
interval   - optional, enum: daily|weekly|monthly (default: monthly)
periods    - optional, number of periods to analyze (1-24, default: 12)
```

**Example Request**:
```bash
curl -X GET "http://localhost:8000/api/analytics/growth-trends?interval=monthly&periods=6" \
  -H "Authorization: Bearer {token}" \
  -H "X-Business-Id: {business_id}"
```

**Example Response**:
```json
{
  "interval": "monthly",
  "periods": 6,
  "trends": [
    {
      "period": "2025-09",
      "start_date": "2025-09-01",
      "end_date": "2025-09-30",
      "revenue": "98450.50",
      "profit": "36520.18",
      "transactions": 1056,
      "average_order_value": "93.23",
      "revenue_growth_percentage": null
    },
    {
      "period": "2025-10",
      "start_date": "2025-10-01",
      "end_date": "2025-10-31",
      "revenue": "105320.75",
      "profit": "39245.28",
      "transactions": 1124,
      "average_order_value": "93.71",
      "revenue_growth_percentage": "6.98"
    },
    {
      "period": "2025-11",
      "start_date": "2025-11-01",
      "end_date": "2025-11-30",
      "revenue": "112300.50",
      "profit": "42150.25",
      "transactions": 1156,
      "average_order_value": "97.15",
      "revenue_growth_percentage": "6.63"
    },
    {
      "period": "2025-12",
      "start_date": "2025-12-01",
      "end_date": "2025-12-31",
      "revenue": "118750.25",
      "profit": "44562.59",
      "transactions": 1198,
      "average_order_value": "99.12",
      "revenue_growth_percentage": "5.74"
    },
    {
      "period": "2026-01",
      "start_date": "2026-01-01",
      "end_date": "2026-01-31",
      "revenue": "122850.50",
      "profit": "46107.19",
      "transactions": 1223,
      "average_order_value": "100.45",
      "revenue_growth_percentage": "3.45"
    },
    {
      "period": "2026-02",
      "start_date": "2026-02-01",
      "end_date": "2026-02-28",
      "revenue": "125450.75",
      "profit": "47230.25",
      "transactions": 1245,
      "average_order_value": "100.72",
      "revenue_growth_percentage": "2.12"
    }
  ]
}
```

---

## Common Response Fields

### Period Metrics Object
```json
{
  "revenue": "125450.75",           // Total revenue
  "cost": "78220.50",              // Total cost of goods sold
  "profit": "47230.25",            // Gross profit
  "margin_percentage": "37.65",    // Profit margin %
  "transaction_count": 1245,       // Number of completed sales
  "average_order_value": "100.72"  // Average transaction value
}
```

### Comparison Object
```json
{
  "revenue_change_percentage": "11.71",      // % change in revenue
  "profit_change_percentage": "12.05",       // % change in profit
  "transaction_change_percentage": "7.70",   // % change in transactions
  "revenue_trend": "up",                     // up|down|stable
  "profit_trend": "up"                       // up|down|stable
}
```

---

## Calculation Formulas

### Revenue Metrics
- **Gross Revenue**: Sum of all sale final_total + discounts
- **Net Revenue**: Sum of all sale final_total
- **Revenue = Net Revenue**

### Cost & Profit
- **Cost of Goods Sold (COGS)**: Sum of (quantity × cost_price) for all sale items
- **Gross Profit**: Revenue - COGS
- **Net Profit**: Gross Profit (can be extended with operating expenses)

### Margins
- **Gross Margin %**: (Gross Profit / Revenue) × 100
- **Net Margin %**: (Net Profit / Revenue) × 100

### Growth Calculations
- **Growth %**: ((Current - Previous) / Previous) × 100
- **Contribution %**: (Item Value / Total Value) × 100

### Average Metrics
- **Average Order Value**: Total Revenue / Transaction Count

---

## Caching Strategy

Analytics queries are cached for performance optimization:

- **Organization Analytics**: 15 minutes
- **Branch Analytics**: 15 minutes
- **Product Analytics**: 15 minutes
- **Profit & Loss**: 30 minutes
- **Growth Trends**: 30 minutes

Cache keys include business_id, branch_id (if applicable), period, and date range to ensure accurate data isolation.

---

## Error Responses

### 400 Bad Request
```json
{
  "message": "Business context required"
}
```

### 403 Forbidden
```json
{
  "message": "Unauthorized"
}
```

### 422 Validation Error
```json
{
  "message": "Validation error",
  "errors": {
    "period": ["The selected period is invalid."],
    "end_date": ["The end date must be after or equal to start date."]
  }
}
```

---

## Business Rules

1. **Only Completed Sales**: Analytics only include sales with `status = 'completed'`
2. **Business Isolation**: All queries are scoped to the current business context
3. **Branch Access Control**: Users can only view analytics for branches they have access to
4. **Permission Enforcement**: 
   - `view analytics` required for all analytics endpoints
   - `view financial reports` required for P&L statements
5. **Date Ranges**: All date ranges are inclusive (start_date to end_date)
6. **Period Comparison**: Previous period has same duration as current period
7. **Cost Price Handling**: If cost_price is null, defaults to 0
8. **Decimal Precision**: All monetary values formatted to 2 decimal places
9. **Percentage Precision**: All percentages formatted to 2 decimal places

---

## Usage Examples

### Dashboard Overview (Organization-wide)
```bash
GET /api/analytics/organization?period=month&compare_previous=true
```
Use for main business dashboard showing overall performance.

### Branch Comparison
```bash
GET /api/analytics/branches?period=month
```
Returns analytics for all branches to compare performance.

### Top Selling Products This Week
```bash
GET /api/analytics/products?period=week&limit=10&sort_by=revenue&direction=desc
```

### Monthly P&L Report
```bash
GET /api/analytics/profit-loss?period=month
```

### 6-Month Revenue Trend
```bash
GET /api/analytics/growth-trends?interval=monthly&periods=6
```

### Custom Date Range Analysis
```bash
GET /api/analytics/organization?period=custom&start_date=2026-01-15&end_date=2026-02-15
```

---

## Best Practices

1. **Use Caching**: Don't call analytics endpoints too frequently - data is cached
2. **Filter by Branch**: For branch-specific dashboards, always include branch_id parameter
3. **Limit Results**: Use the `limit` parameter for product analytics to improve performance
4. **Choose Appropriate Periods**: Use shorter periods (today/week) for real-time monitoring, longer periods (month/year) for strategic analysis
5. **Period Comparison**: Enable `compare_previous` to show trends and growth
6. **Permission Planning**: Grant `view financial reports` only to authorized personnel
7. **Custom Ranges**: Use custom date ranges for specific reporting periods (quarters, fiscal years, etc.)
8. **Growth Trends**: Use monthly intervals for long-term analysis, daily for short-term monitoring

---

## Future Enhancements

- Export to CSV/Excel
- Email scheduled reports
- Custom dashboard widgets
- Real-time analytics (websockets)
- Predictive analytics
- Customer analytics
- Inventory turnover analysis
- Category performance analysis
- Payment method breakdown
- Hourly sales patterns
