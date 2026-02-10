# POS API Data Generator

Generate realistic test data for your POS application by calling API endpoints.

## Features

✅ **Realistic Data Generation** - Uses Faker.js to create authentic-looking data  
✅ **API-Based** - Tests your actual API endpoints, not database  
✅ **Restart-Safe** - Avoids creating duplicate data  
✅ **Configurable Volumes** - Scale data up or down easily  
✅ **Error Handling** - Automatic retries and clear error messages  
✅ **Progress Logging** - See exactly what's happening  
✅ **Authenticated Requests** - Handles JWT/Bearer tokens  

## Installation

```bash
cd scripts
npm install
```

## Configuration

Copy the example environment file:

```bash
cp .env.example .env
```

Edit `.env` with your settings:

```env
API_BASE_URL=http://localhost:8000/api
AUTH_EMAIL=admin@acmeretail.com
AUTH_PASSWORD=password
```

## Usage

### Basic Usage

```bash
npm run generate
```

### Volume Presets

**Small Dataset** (for quick testing):
- 10 categories
- 20 products
- 25 customers
- 100 sales

```bash
npm run generate:small
```

**Medium Dataset** (default):
- 15 categories
- 50 products
- 50 customers
- 1,000 sales

```bash
npm run generate
```

**Large Dataset** (for load testing):
- 20 categories
- 100 products
- 200 customers
- 10,000 sales

```bash
npm run generate:large
```

### Custom Configuration

Edit `CONFIG` object in `api-data-generator.js`:

```javascript
const CONFIG = {
  volumes: {
    categories: 15,
    products: 50,
    customersPerBusiness: 50,
    salesPerBusiness: 1000,
    daysOfSales: 60,
    // ... etc
  },
};
```

## Data Created

The script creates data in the following order:

1. **Categories** (hierarchical structure)
   - Electronics → Mobile Phones, Laptops, Accessories
   - Groceries → Dairy, Bakery, Beverages, Snacks
   - Household → Cleaning, Kitchen, Bathroom
   - Personal Care → Skincare, Haircare, Hygiene
   - Office Supplies → Stationery, Paper Products

2. **Products** (50+ with realistic details)
   - SKU, barcode, pricing
   - Cost and selling prices
   - Tax rates
   - Stock tracking settings

3. **Payment Methods**
   - Cash
   - Credit/Debit Card
   - Mobile Money

4. **Customers** (50+ per business)
   - Contact information
   - Customer types (walk-in, regular, VIP)
   - Credit limits
   - Loyalty points

5. **Sales Shifts** (1-3 per branch per day)
   - Opening balances
   - Time ranges
   - Status (open/closed)

6. **Sales** (1,000+ transactions)
   - Multiple items per sale
   - Realistic quantities and prices
   - Customer assignments (60%)
   - Single or split payments
   - Distributed over 60 days

7. **Workflows**
   - Refund requests (20)
   - Quick sale requests (15)
   - Stock transfer requests (30)

## API Endpoints Used

The script calls the following endpoints:

```
POST   /api/login
GET    /api/categories
POST   /api/categories
GET    /api/products
POST   /api/products
GET    /api/customers
POST   /api/customers
GET    /api/payment-methods
POST   /api/payment-methods
POST   /api/shifts
POST   /api/sales
POST   /api/refunds
POST   /api/quick-sales
POST   /api/stock-transfers
```

## Script Structure

```
api-data-generator.js
├── Configuration
├── HTTP Client Setup
├── Utility Functions
│   ├── Delay & Retry Logic
│   ├── Random Data Generators
│   └── Progress Logging
├── Authentication
├── Data Fetching (existing data)
├── Data Creation Functions
│   ├── createCategories()
│   ├── createProducts()
│   ├── createCustomers()
│   ├── createSales()
│   └── createWorkflows()
└── Main Execution Flow
```

## Error Handling

The script includes:

- **Automatic Retries**: Failed requests retry up to 3 times with exponential backoff
- **Authentication Refresh**: Handles token expiration
- **Duplicate Detection**: Skips existing data to avoid conflicts
- **Clear Error Messages**: Shows exactly what failed and why

## Performance

Default timing (configurable):
- **Delay between requests**: 100ms
- **Delay between batches**: 500ms
- **Request timeout**: 30 seconds
- **Retry delay**: 2 seconds (increasing)

Adjust in `CONFIG`:

```javascript
CONFIG.delayBetweenRequests = 50;  // Faster
CONFIG.delayBetweenBatches = 1000; // Slower batches
CONFIG.maxRetries = 5;             // More retries
```

## Restart Safety

The script checks for existing data before creating:

```javascript
CONFIG.skipExisting = true;
```

When enabled:
- Fetches existing categories, products, etc.
- Reuses existing data
- Only creates what's missing

## Scaling Tips

### Increase Data Volume

```javascript
CONFIG.volumes.salesPerBusiness = 10000;
CONFIG.volumes.products = 200;
CONFIG.volumes.daysOfSales = 180;
```

### Speed Up Generation

```javascript
CONFIG.delayBetweenRequests = 50;    // Down from 100ms
CONFIG.delayBetweenBatches = 200;    // Down from 500ms
```

### Reduce API Load

```javascript
CONFIG.delayBetweenRequests = 500;   // Up from 100ms
CONFIG.maxRetries = 2;               // Fewer retries
```

## Monitoring Progress

The script logs:
- ✅ Successful operations
- ⚠️  Warnings (skipped items, existing data)
- ❌ Errors (with details)
- 📊 Progress updates (every 50 sales)
- 📈 Final summary

Example output:

```
[2026-02-08T11:00:00.000Z] 🔐 Authenticating...
[2026-02-08T11:00:01.000Z] ✅ Authentication successful
[2026-02-08T11:00:02.000Z] 📂 Creating categories...
  ✓ Category created: Electronics (ID: 1)
  ✓ Category created: Mobile Phones (ID: 2)
...
[2026-02-08T11:05:00.000Z] 💰 Creating sales...
📅 Creating 17 sales for 2025-12-10...
  Progress: 50/1000 sales created
  Progress: 100/1000 sales created
...
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

## Troubleshooting

### Authentication Fails

Check:
- API base URL is correct
- Credentials are valid
- `/api/login` endpoint exists

### Request Timeouts

Increase timeout:

```javascript
CONFIG.timeout = 60000; // 60 seconds
```

### Too Many Requests (429)

Increase delays:

```javascript
CONFIG.delayBetweenRequests = 1000; // 1 second
```

### Validation Errors

Check:
- API endpoint URLs match your routes
- Request payloads match your API validation rules
- Required fields are included

## Advanced Usage

### Custom Product Templates

Add your own products:

```javascript
const PRODUCT_TEMPLATES = [
  {
    name: 'Custom Product',
    category: 'Electronics',
    cost: 100,
    price: 150,
    taxable: true,
    perishable: false
  },
  // ... more products
];
```

### Custom Category Structure

Modify `CATEGORY_DATA`:

```javascript
const CATEGORY_DATA = [
  {
    name: 'My Category',
    parent: null,
    children: ['Subcategory 1', 'Subcategory 2']
  },
];
```

### Programmatic Usage

Import and use functions:

```javascript
const generator = require('./api-data-generator');

async function customScript() {
  await generator.authenticate();
  await generator.createCategories();
  await generator.createProducts();
  // ... custom logic
}
```

## License

MIT

## Support

For issues or questions, please check:
1. API logs for error details
2. Network requests in browser dev tools
3. Database constraints that may be violated
