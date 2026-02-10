# API Data Generator - Complete Usage Guide

## 📋 Table of Contents

1. [Quick Start](#quick-start)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Running the Generator](#running-the-generator)
5. [Data Volume Presets](#data-volume-presets)
6. [Customization](#customization)
7. [API Endpoints](#api-endpoints)
8. [Error Handling](#error-handling)
9. [Performance Tuning](#performance-tuning)
10. [Troubleshooting](#troubleshooting)

---

## 🚀 Quick Start

```bash
# 1. Navigate to scripts folder
cd /Users/samueleke/Documents/PosBackend/scripts

# 2. Install dependencies
npm install

# 3. Create environment file
cp .env.example .env

# 4. Test API connection
npm test

# 5. Generate data
npm run generate
```

---

## 📦 Installation

### Prerequisites

- Node.js 16+ installed
- Laravel API server running
- Valid admin credentials

### Install Dependencies

```bash
cd scripts
npm install
```

This installs:
- `axios` - HTTP client
- `@faker-js/faker` - Realistic fake data
- `dotenv` - Environment variables

---

## ⚙️ Configuration

### Environment Variables

Create `.env` file:

```env
# API Configuration
API_BASE_URL=http://localhost:8000/api

# Authentication
AUTH_EMAIL=admin@acmeretail.com
AUTH_PASSWORD=password

# Data Volume (optional)
DATA_PRESET=medium
```

### Code Configuration

Edit `CONFIG` object in `api-data-generator.js`:

```javascript
const CONFIG = {
  // API Settings
  baseURL: 'http://localhost:8000/api',
  timeout: 30000,
  
  // Auth Credentials
  authEmail: 'admin@acmeretail.com',
  authPassword: 'password',
  
  // Data Volumes
  volumes: {
    categories: 15,
    products: 50,
    customersPerBusiness: 50,
    salesPerBusiness: 1000,
    shiftsPerDay: 3,
    daysOfSales: 60,
    itemsPerSale: { min: 1, max: 8 },
    batchesPerProduct: { min: 2, max: 5 },
    refundRequests: 20,
    quickSales: 15,
    stockTransfers: 30,
  },
  
  // Performance
  delayBetweenRequests: 100,  // ms
  delayBetweenBatches: 500,   // ms
  maxRetries: 3,
  retryDelay: 2000,           // ms
  
  // Behavior
  skipExisting: true,         // Skip existing data
};
```

---

## 🎮 Running the Generator

### Basic Commands

```bash
# Default (medium dataset)
npm run generate

# Small dataset (quick test)
npm run generate:small

# Large dataset (load test)
npm run generate:large

# Test API connection first
npm test
```

### Direct Execution

```bash
node api-data-generator.js
```

### With Custom Environment

```bash
API_BASE_URL=http://staging.example.com/api \
AUTH_EMAIL=test@example.com \
AUTH_PASSWORD=secret \
node api-data-generator.js
```

---

## 📊 Data Volume Presets

### Small Preset

**Use case:** Quick testing, development

```
Categories:  5
Products:    20
Customers:   25
Sales:       100 (over 30 days)
Shifts:      60
```

**Estimated time:** ~2-3 minutes

```bash
npm run generate:small
```

### Medium Preset (Default)

**Use case:** Standard testing, demos

```
Categories:  15
Products:    50
Customers:   50
Sales:       1,000 (over 60 days)
Shifts:      180
```

**Estimated time:** ~10-15 minutes

```bash
npm run generate
```

### Large Preset

**Use case:** Load testing, performance testing

```
Categories:  20
Products:    100
Customers:   200
Sales:       10,000 (over 180 days)
Shifts:      720
```

**Estimated time:** ~1-2 hours

```bash
npm run generate:large
```

### XLarge Preset

**Use case:** Stress testing

```
Categories:  30
Products:    500
Customers:   1,000
Sales:       50,000 (over 365 days)
Shifts:      1,460
```

**Estimated time:** ~5-10 hours

Edit `api-data-generator.js` and change preset:

```javascript
const preset = require('./presets').getPreset('xlarge');
CONFIG.volumes = preset;
```

---

## 🎨 Customization

### Custom Product Templates

Add your own products to `PRODUCT_TEMPLATES`:

```javascript
const PRODUCT_TEMPLATES = [
  {
    name: 'My Custom Product',
    category: 'Electronics',
    cost: 100.00,
    price: 149.99,
    taxable: true,
    perishable: false
  },
  // ... more products
];
```

### Custom Categories

Modify `CATEGORY_DATA`:

```javascript
const CATEGORY_DATA = [
  {
    name: 'My Category',
    parent: null,
    children: ['Subcategory A', 'Subcategory B']
  },
];
```

### Custom Sale Logic

Modify `createSale()` function:

```javascript
async function createSale(shift, date) {
  // Custom logic here
  const itemCount = randomInt(2, 10); // Always 2-10 items
  
  // Only VIP customers
  const customer = createdData.customers.find(c => c.type === 'vip');
  
  // ... rest of logic
}
```

### Custom Date Ranges

```javascript
// Only create sales for last 30 days
const daysOfSales = 30;

// Or specific date range
const startDate = new Date('2026-01-01');
const endDate = new Date('2026-01-31');
```

---

## 🔌 API Endpoints

### Required Endpoints

Your API must have these endpoints:

#### Authentication
```
POST /api/login
Request:  { email, password }
Response: { token, user }
```

#### Categories
```
GET  /api/categories
POST /api/categories
Request: { name, description, parent_id?, is_active }
```

#### Products
```
GET  /api/products
POST /api/products
Request: {
  category_id, name, sku, barcode,
  base_cost_price, base_selling_price,
  is_taxable, default_tax_rate,
  stock_tracking, low_stock_threshold
}
```

#### Customers
```
GET  /api/customers
POST /api/customers
Request: {
  name, email?, phone, address,
  type, credit_limit, is_active
}
```

#### Payment Methods
```
GET  /api/payment-methods
POST /api/payment-methods
Request: { name, type, is_active, sort_order }
```

#### Shifts
```
POST /api/shifts
Request: {
  branch_id, start_time, end_time?,
  opening_balance, status
}
```

#### Sales
```
POST /api/sales
Request: {
  customer_id?, shift_id, sale_date,
  items: [{ product_id, quantity, unit_price, ... }],
  payments: [{ payment_method_id, amount }],
  status, payment_status
}
```

#### Workflows
```
POST /api/refunds
POST /api/quick-sales
POST /api/stock-transfers
```

### Endpoint Customization

If your endpoints differ, update the API calls:

```javascript
// Example: Different endpoint name
async function createCategory(data) {
  const response = await api.post('/product-categories', data); // Changed
  // ...
}
```

---

## 🛡️ Error Handling

### Automatic Retry Logic

Failed requests automatically retry with exponential backoff:

```javascript
// Default: 3 retries, 2 second initial delay
CONFIG.maxRetries = 3;
CONFIG.retryDelay = 2000;

// Custom: 5 retries, 5 second delay
CONFIG.maxRetries = 5;
CONFIG.retryDelay = 5000;
```

### Handling Specific Errors

#### 401 Unauthorized
- Token expired
- Invalid credentials
- Script will exit, fix auth and retry

#### 422 Validation Error
- Invalid data format
- Missing required fields
- Check API validation rules

#### 429 Too Many Requests
- API rate limiting
- Increase delays:
  ```javascript
  CONFIG.delayBetweenRequests = 500;
  ```

#### 500 Server Error
- API server issue
- Check Laravel logs
- Script will retry automatically

### Skip Existing Data

Avoid duplicate errors:

```javascript
CONFIG.skipExisting = true;
```

---

## ⚡ Performance Tuning

### Speed Up Generation

```javascript
// Reduce delays (may overwhelm server)
CONFIG.delayBetweenRequests = 50;    // Down from 100ms
CONFIG.delayBetweenBatches = 200;    // Down from 500ms

// Increase timeout for slow responses
CONFIG.timeout = 60000;              // 60 seconds
```

### Slow Down Generation

```javascript
// Increase delays (reduce server load)
CONFIG.delayBetweenRequests = 500;   // Up from 100ms
CONFIG.delayBetweenBatches = 2000;   // Up from 500ms

// Fewer retries
CONFIG.maxRetries = 2;
```

### Batch Processing

For very large datasets, split into batches:

```javascript
// Generate 10,000 sales in batches of 1,000
for (let batch = 0; batch < 10; batch++) {
  CONFIG.volumes.salesPerBusiness = 1000;
  await createSales();
  console.log(`Batch ${batch + 1}/10 complete`);
  await delay(5000); // 5 second pause between batches
}
```

---

## 🔍 Troubleshooting

### API Not Reachable

**Error:** `ECONNREFUSED`

**Solution:**
```bash
# Make sure Laravel server is running
php artisan serve

# Or check if running on different port
php artisan serve --port=8001
```

### Authentication Fails

**Error:** `401 Unauthorized`

**Solutions:**
1. Check credentials in `.env`
2. Verify `/api/login` endpoint exists
3. Check Laravel logs: `tail -f storage/logs/laravel.log`

### Validation Errors

**Error:** `422 Unprocessable Entity`

**Solutions:**
1. Check API validation rules
2. Ensure required fields are included
3. Verify field types match (string, number, etc.)
4. Check for unique constraints (SKU, email, etc.)

### Duplicate Data

**Error:** `Duplicate entry`

**Solutions:**
1. Enable skip existing:
   ```javascript
   CONFIG.skipExisting = true;
   ```

2. Or reset database:
   ```bash
   php artisan migrate:fresh
   ```

### Slow Performance

**Symptoms:** Script takes too long

**Solutions:**
1. Reduce data volume
2. Decrease delays
3. Check database indexes
4. Monitor server CPU/memory
5. Use database query optimization

### Memory Issues

**Error:** `JavaScript heap out of memory`

**Solution:**
```bash
# Increase Node.js memory
node --max-old-space-size=4096 api-data-generator.js
```

### Network Timeouts

**Error:** `ETIMEDOUT`

**Solutions:**
```javascript
// Increase timeout
CONFIG.timeout = 60000;  // 60 seconds

// Or reduce batch size
CONFIG.volumes.salesPerBusiness = 500;
```

---

## 📈 Monitoring Progress

### Console Output

The script provides detailed logs:

```
[2026-02-08T11:00:00.000Z] 🔐 Authenticating...
[2026-02-08T11:00:01.000Z] ✅ Authentication successful
[2026-02-08T11:00:02.000Z] 📂 Creating categories...
  ✓ Category created: Electronics (ID: 1)
  ✓ Category created: Mobile Phones (ID: 2)
[2026-02-08T11:00:05.000Z] ✅ Created 15 categories

[2026-02-08T11:00:10.000Z] 📦 Creating products...
  ✓ Product created: iPhone 15 Pro (ID: 1)
  ❌ Failed to create product 'Duplicate SKU': SKU already exists
[2026-02-08T11:02:00.000Z] ✅ Created 50 products

[2026-02-08T11:05:00.000Z] 💰 Creating sales...
📅 Creating 17 sales for 2025-12-10...
  Progress: 50/1000 sales created
  Progress: 100/1000 sales created
  Progress: 500/1000 sales created
  Progress: 1000/1000 sales created
[2026-02-08T11:30:00.000Z] ✅ Created 1000 sales

═══════════════════════════════════════════════════════
   DATA GENERATION COMPLETE ✅
═══════════════════════════════════════════════════════

Summary:
  Categories:      15
  Products:        50
  Customers:       50
  Payment Methods: 3
  Shifts:          180
  Sales:           1000
```

### Save Output to File

```bash
npm run generate > output.log 2>&1
```

### Monitor in Real-Time

```bash
# In one terminal
npm run generate

# In another terminal
tail -f output.log
```

---

## 🎯 Best Practices

1. **Test First:** Always run `npm test` before generating data
2. **Start Small:** Use `small` preset initially
3. **Monitor Logs:** Watch for errors during generation
4. **Database Backup:** Backup database before large imports
5. **Resource Monitoring:** Monitor server CPU/memory during generation
6. **Incremental Approach:** Generate data in smaller batches for huge datasets
7. **Validate Results:** Check database after generation
8. **Clean Start:** Use `migrate:fresh` for clean slate

---

## 📞 Support

For issues:
1. Check Laravel logs: `storage/logs/laravel.log`
2. Check script output for error messages
3. Run `npm test` to diagnose connection issues
4. Verify API endpoints match your routes
5. Check database constraints and validation rules
