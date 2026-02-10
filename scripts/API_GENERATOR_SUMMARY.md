# API Data Generator - Quick Summary

## ✅ What Was Created

A comprehensive Node.js script system for generating test data via your POS API endpoints.

## 📁 Files Created

```
scripts/
├── api-data-generator.js      # Main generator script (~600 lines)
├── api-test.js                # Connection test utility
├── presets.js                 # Data volume presets
├── package.json               # Dependencies
├── .env.example               # Environment template
├── README.md                  # Overview and quick start
└── USAGE_GUIDE.md            # Complete documentation
```

## 🚀 Quick Start (3 Steps)

```bash
# 1. Install
cd scripts && npm install

# 2. Configure
cp .env.example .env
# Edit .env with your API URL and credentials

# 3. Run
npm test          # Test connection first
npm run generate  # Generate data
```

## 📊 What Data Gets Created

| Resource | Volume (Medium) | Details |
|----------|----------------|---------|
| Categories | 15 | Hierarchical structure |
| Products | 50+ | With SKU, pricing, tax rates |
| Customers | 50 | Contact info, loyalty points |
| Payment Methods | 3 | Cash, Card, Mobile Money |
| Sales Shifts | 180 | 3 per day × 60 days |
| Sales | 1,000 | With items, payments |
| Refund Requests | 20 | Mixed statuses |
| Quick Sales | 15 | Discount approvals |
| Stock Transfers | 30 | Inter-branch |

**Execution Order:** Categories → Products → Payment Methods → Customers → Shifts → Sales → Workflows

## 🎮 Volume Presets

```bash
npm run generate:small   # 100 sales, quick test
npm run generate         # 1,000 sales, default
npm run generate:large   # 10,000 sales, load test
```

## ⚙️ Key Features

✅ **Realistic Data** - Uses Faker.js for authentic names, emails, addresses  
✅ **API Testing** - Tests actual endpoints, not database  
✅ **Restart-Safe** - Skips existing data, no duplicates  
✅ **Auto-Retry** - Handles network errors automatically  
✅ **Progress Logs** - See exactly what's happening  
✅ **Configurable** - Easy to scale up/down  
✅ **Error Handling** - Clear error messages with solutions  

## 📝 Configuration Options

Edit `api-data-generator.js`:

```javascript
const CONFIG = {
  // Connection
  baseURL: 'http://localhost:8000/api',
  timeout: 30000,
  
  // Auth
  authEmail: 'admin@acmeretail.com',
  authPassword: 'password',
  
  // Volumes
  volumes: {
    products: 50,
    salesPerBusiness: 1000,
    daysOfSales: 60,
    // ... more
  },
  
  // Performance
  delayBetweenRequests: 100,  // ms
  maxRetries: 3,
  skipExisting: true,
};
```

## 🔌 Required API Endpoints

Your Laravel API needs these routes:

```
POST /api/login
GET  /api/categories
POST /api/categories
GET  /api/products
POST /api/products
GET  /api/customers
POST /api/customers
GET  /api/payment-methods
POST /api/payment-methods
POST /api/shifts
POST /api/sales
POST /api/refunds
POST /api/quick-sales
POST /api/stock-transfers
```

## 🛠️ Customization Examples

### Change Sales Volume

```javascript
CONFIG.volumes.salesPerBusiness = 5000;
```

### Speed Up Generation

```javascript
CONFIG.delayBetweenRequests = 50;  // Faster
```

### Add Custom Products

```javascript
const PRODUCT_TEMPLATES = [
  {
    name: 'My Product',
    category: 'Electronics',
    cost: 100,
    price: 150,
    taxable: true
  },
];
```

## 📈 Performance

| Preset | Sales | Time | Notes |
|--------|-------|------|-------|
| Small | 100 | ~2-3 min | Quick test |
| Medium | 1,000 | ~10-15 min | Default |
| Large | 10,000 | ~1-2 hours | Load test |

Times vary based on API speed and network latency.

## 🔍 Troubleshooting

### Can't connect to API
```bash
# Make sure Laravel is running
php artisan serve
```

### Authentication fails
- Check credentials in `.env`
- Verify `/api/login` endpoint works
- Check Laravel logs

### Validation errors
- Ensure request payloads match API validation
- Check for required fields
- Verify unique constraints (SKU, email)

### Too slow
```javascript
CONFIG.delayBetweenRequests = 50;  // Reduce delay
```

## 📊 Sample Output

```
═══════════════════════════════════════════════════════
   POS API DATA GENERATOR
═══════════════════════════════════════════════════════

[2026-02-08T11:00:00.000Z] 🔐 Authenticating...
[2026-02-08T11:00:01.000Z] ✅ Authentication successful

[2026-02-08T11:00:02.000Z] 📂 Creating categories...
  ✓ Category created: Electronics (ID: 1)
  ✓ Category created: Groceries (ID: 2)
[2026-02-08T11:00:10.000Z] ✅ Created 15 categories

[2026-02-08T11:00:15.000Z] 📦 Creating products...
  ✓ Product created: iPhone 15 Pro (ID: 1)
  ✓ Product created: MacBook Pro 14" (ID: 2)
[2026-02-08T11:02:00.000Z] ✅ Created 50 products

[2026-02-08T11:05:00.000Z] 💰 Creating sales...
  Progress: 50/1000 sales created
  Progress: 100/1000 sales created
  ...
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

## 🎯 Use Cases

1. **Development** - Populate dev database quickly
2. **Testing** - Generate consistent test data
3. **Demos** - Create realistic demo environment
4. **QA** - Test with large datasets
5. **Load Testing** - Stress test with 10k+ records
6. **API Testing** - Verify all endpoints work
7. **Integration Testing** - Test end-to-end flows

## 📚 Documentation

- `README.md` - Overview and quick start
- `USAGE_GUIDE.md` - Complete documentation (detailed)
- `api-data-generator.js` - Inline code comments
- `.env.example` - Configuration template

## 💡 Tips

1. **Always test first:** `npm test`
2. **Start small:** Use `small` preset initially
3. **Monitor progress:** Watch console output
4. **Check results:** Query database after generation
5. **Backup first:** Before large data imports
6. **Clean slate:** `php artisan migrate:fresh` for fresh start

## 🔗 Next Steps

1. ✅ Install dependencies: `npm install`
2. ✅ Configure `.env` file
3. ✅ Test connection: `npm test`
4. ✅ Generate data: `npm run generate`
5. ✅ Verify in database
6. ✅ Customize as needed

## ⚖️ Comparison: API Generator vs Database Seeder

| Feature | API Generator | Database Seeder |
|---------|--------------|-----------------|
| **Tests API** | ✅ Yes | ❌ No |
| **Auth Required** | ✅ Yes | ❌ No |
| **Validation** | ✅ Full API validation | ⚠️ Model only |
| **Speed** | ⚠️ Slower (network) | ✅ Faster (direct DB) |
| **Business Logic** | ✅ Full (controllers) | ⚠️ Partial (models) |
| **Real World** | ✅ Simulates real usage | ❌ Direct insert |
| **Setup** | ⚠️ Node.js required | ✅ Built-in |
| **Debugging** | ✅ See API responses | ⚠️ Database only |

**Use API Generator when:**
- Testing API endpoints
- Simulating real application usage
- Need to validate API responses
- Testing authentication/authorization
- Want to test full request/response cycle

**Use Database Seeder when:**
- Need quick data population
- Don't need API validation
- Want faster execution
- Testing database relationships only
- CI/CD environment without API

## 📞 Support

For help:
1. Check `USAGE_GUIDE.md` for detailed docs
2. Run `npm test` for diagnostics
3. Check Laravel logs: `storage/logs/laravel.log`
4. Verify API routes: `php artisan route:list`

---

**Ready to generate data!** Run `npm test` to verify connection, then `npm run generate` to start.
