# Postman Collection Update Summary

**Date:** February 8, 2026  
**Version:** 2.0.0  
**File:** `POS_Backend_Complete_API.postman_collection.json`

## Overview

Updated the Postman collection with all newly incorporated features from the POS Backend API.

## ✨ New Features Added

### 1. **PIN Authentication** (3 endpoints)

Added quick login functionality using 4-6 digit PIN:

- **POST** `/api/pin-login` - Login with email and PIN
- **POST** `/api/pin/set` - Set or update user PIN
- **POST** `/api/pin/remove` - Remove PIN (revert to password login)

**Benefits:**
- Faster cashier login at POS terminals
- Secure 4-6 digit PIN system
- Maintains session security with bearer tokens

---

### 2. **Stock Transfer Requests** (7 endpoints)

Complete workflow for inter-branch stock transfers:

- **GET** `/api/stock-transfer-requests` - List transfers with filters
- **POST** `/api/stock-transfer-requests` - Create transfer request
- **GET** `/api/stock-transfer-requests/{id}` - Get transfer details
- **POST** `/api/stock-transfer-requests/{id}/approve` - Approve transfer
- **POST** `/api/stock-transfer-requests/{id}/reject` - Reject transfer
- **POST** `/api/stock-transfer-requests/{id}/confirm` - Confirm receipt
- **POST** `/api/stock-transfer-requests/{id}/cancel` - Cancel pending transfer

**Workflow:**
1. Create → Pending
2. Approve → Ready for shipping
3. Confirm → Inventory transferred
4. Alternative: Reject or Cancel

---

### 3. **Stock Write-offs** (3 endpoints)

Track and record inventory losses:

- **GET** `/api/stock-writeoffs` - List write-offs
- **POST** `/api/stock-writeoffs` - Record write-off
- **GET** `/api/stock-writeoffs/{id}` - Get write-off details

**Supported Reasons:**
- `damaged` - Physical damage
- `expired` - Past expiry date
- `theft` - Stolen inventory
- `lost` - Cannot be located

**Automatically:**
- Decreases inventory
- Creates inventory transaction
- Records for financial reporting

---

### 4. **Branch Product Management** (5 endpoints)

Shelf and store inventory separation:

- **GET** `/api/branch-products` - List branch products
- **GET** `/api/branch-products/summary/stock` - Get stock summary
- **POST** `/api/branch-products/{id}/move-to-shelf` - Move store → shelf
- **POST** `/api/branch-products/{id}/move-to-store` - Move shelf → store
- **POST** `/api/branch-products/bulk-update` - Bulk update prices/settings

**Features:**
- Separate shelf and store stock tracking
- Bulk price updates
- Reorder point management
- Stock movement tracking

---

### 5. **Enhanced Product Management** (3 endpoints)

Additional product-related endpoints:

- **GET** `/api/categories/{id}/breadcrumb` - Get category hierarchy
- **GET** `/api/branches/{branchId}/products` - Get products by branch
- **POST** `/api/products/{id}/branches` - Add product to branch
- **PATCH** `/api/products/{id}/price` - Update branch-specific price

**Features:**
- Hierarchical category breadcrumbs
- Branch-specific product assignment
- Branch-specific pricing

---

### 6. **User-Business Management** (5 endpoints)

Manage user memberships within business context:

- **GET** `/api/business-users` - List business users
- **POST** `/api/business-users` - Add user to business
- **GET** `/api/business-users/{userId}` - Get user details
- **PUT** `/api/business-users/{userId}` - Update user settings
- **DELETE** `/api/business-users/{userId}` - Remove user from business

**Use Cases:**
- Multi-business user management
- Default branch assignment
- User access control per business

---

### 7. **Offline Sync System** (6 endpoints)

Complete offline-first synchronization:

- **POST** `/api/sync/register-device` - Register new device
- **POST** `/api/sync/bootstrap` - Initial data download
- **POST** `/api/sync/pull` - Download server changes
- **POST** `/api/sync/push` - Upload local changes
- **POST** `/api/sync/resolve-conflicts` - Resolve sync conflicts
- **GET** `/api/sync/status` - Get sync status

**Features:**
- Device registration and management
- Bootstrap for initial setup
- Incremental sync (pull/push)
- Conflict detection and resolution
- Idempotent operations with client UUIDs
- Support for offline sales

**Device Types:**
- POS Terminal
- Mobile App
- Tablet App
- Web Browser

**Conflict Resolution Strategies:**
- `server_wins` - Keep server version
- `client_wins` - Keep client version
- `manual_merge` - Custom merged data

---

## 📊 Collection Statistics

### Total Endpoints Added: **37 new endpoints**

| Module | Endpoints Added |
|--------|----------------|
| PIN Authentication | 3 |
| Stock Transfer Requests | 7 |
| Stock Write-offs | 3 |
| Branch Product Management | 5 |
| Enhanced Product Management | 4 |
| User-Business Management | 5 |
| Offline Sync | 6 |
| **Total** | **33** |

### Updated Sections

1. **Authentication** - Added PIN login support
2. **Product Management** - Enhanced with breadcrumbs and branch assignment
3. **New: Branch Product Management** - Complete shelf/store system
4. **New: Stock Transfer Requests** - Inter-branch transfer workflow
5. **New: Stock Write-offs** - Inventory loss tracking
6. **New: User-Business Management** - Multi-business user control
7. **New: Offline Sync** - Device sync system

---

## 🔧 Collection Variables

Added new variable:

```json
{
  "key": "device_id",
  "value": "",
  "type": "string"
}
```

**Existing Variables:**
- `base_url` - API base URL (default: http://localhost:8000)
- `auth_token` - Authentication bearer token
- `business_id` - Current business context
- `branch_id` - Current branch
- `product_id` - Sample product ID
- `sale_id` - Sample sale ID
- `shift_id` - Sample shift ID
- `user_id` - Sample user ID
- `device_id` - Device identifier for sync (NEW)

---

## 📝 Headers

### Standard Headers

All authenticated requests require:
```
Authorization: Bearer {{auth_token}}
X-Business-Id: {{business_id}}
Accept: application/json
```

### Sync-Specific Headers

Sync endpoints additionally require:
```
X-Device-Id: {{device_id}}
```

---

## 🚀 Quick Start with New Features

### 1. PIN Login Flow

```bash
# Set PIN (authenticated)
POST /api/pin/set
{
  "pin": "1234",
  "pin_confirmation": "1234"
}

# Login with PIN (no auth required)
POST /api/pin-login
{
  "email": "cashier@store.com",
  "pin": "1234"
}
# Returns: auth_token
```

### 2. Stock Transfer Flow

```bash
# 1. Create transfer request
POST /api/stock-transfer-requests
{
  "from_branch_id": 1,
  "to_branch_id": 2,
  "product_id": 5,
  "quantity": 50,
  "reason": "Stock rebalancing"
}

# 2. Approve (manager/admin)
POST /api/stock-transfer-requests/1/approve

# 3. Confirm receipt (destination branch)
POST /api/stock-transfer-requests/1/confirm
# Inventory automatically updated
```

### 3. Stock Write-off

```bash
POST /api/stock-writeoffs
{
  "branch_id": 1,
  "product_id": 10,
  "quantity": 5,
  "reason": "expired",
  "notes": "Past expiry date",
  "batch_id": 3
}
# Inventory automatically decreased
```

### 4. Shelf/Store Movement

```bash
# Move from store to shelf (restocking)
POST /api/branch-products/1/move-to-shelf
{
  "quantity": 50,
  "notes": "Weekend restock"
}

# Move from shelf to store (excess)
POST /api/branch-products/1/move-to-store
{
  "quantity": 20,
  "notes": "Excess inventory"
}
```

### 5. Offline Sync Flow

```bash
# 1. Register device (one-time)
POST /api/sync/register-device
{
  "device_name": "POS Terminal 1",
  "device_identifier": "POS-001",
  "branch_id": 1,
  "device_type": "pos_terminal"
}
# Returns: device_id (save this!)

# 2. Bootstrap (initial download)
POST /api/sync/bootstrap
Headers: X-Device-Id: {device_id}
{
  "branch_id": 1,
  "include_historical_data": false
}
# Downloads: products, categories, customers, prices

# 3. Push offline sales
POST /api/sync/push
Headers: X-Device-Id: {device_id}
{
  "changes": [
    {
      "model": "sales",
      "action": "create",
      "client_uuid": "sale-offline-001",
      "data": {...}
    }
  ]
}

# 4. Pull server updates
POST /api/sync/pull
Headers: X-Device-Id: {device_id}
{
  "last_sync_timestamp": "2026-02-08T10:00:00Z",
  "models": ["products", "customers"]
}
```

---

## 🔐 Permissions Required

### New Permissions Added

| Endpoint | Permission Required |
|----------|-------------------|
| Stock Transfer Approve/Reject | `approve stock transfer` |
| Stock Transfer Confirm | `confirm stock transfer` |
| Stock Write-off Create | `manage stock writeoff` |
| Branch Product Move | `manage inventory` |
| Branch Product Bulk Update | `manage products` |
| Business User Add/Remove | `manage users` |

---

## 📚 Documentation Updates

### Collection Description

Updated collection description to include:
- PIN authentication support
- Shelf-store inventory system
- Stock write-offs
- Offline sync capabilities
- Multi-device support

### Request Descriptions

All new endpoints include:
- ✅ Detailed descriptions
- ✅ Parameter explanations
- ✅ Required permissions
- ✅ Workflow context
- ✅ Example payloads
- ✅ Auto-behavior notes (what happens automatically)

---

## 🧪 Testing the Collection

### Import Steps

1. Open Postman
2. Click **Import**
3. Select `POS_Backend_Complete_API.postman_collection.json`
4. Collection appears in sidebar

### Setup Environment

1. Set `base_url` variable to your API URL
2. Login to get `auth_token` (auto-saved)
3. Create business to get `business_id` (auto-saved)
4. Create branch to get `branch_id` (auto-saved)

### Test New Features

Run requests in this order:

**PIN Login:**
1. Login normally → Get token
2. Set PIN
3. Logout
4. PIN Login → Get new token

**Stock Transfers:**
1. Create transfer request
2. Approve transfer
3. Confirm transfer
4. Check inventory (should be updated)

**Sync:**
1. Register device → Save device_id
2. Bootstrap device
3. Create offline sale → Push changes
4. Pull server updates

---

## 🔄 Version History

### Version 2.0.0 (February 8, 2026)

**Added:**
- PIN authentication endpoints (3)
- Stock transfer workflow (7)
- Stock write-off tracking (3)
- Branch product management (5)
- Enhanced product features (4)
- User-business management (5)
- Offline sync system (6)

**Updated:**
- Collection metadata and description
- Version bumped to 2.0.0
- Added new features to module list
- Added device_id variable

**Total Additions:** 33 new endpoints

### Version 1.0.0 (Initial Release)

- Core authentication
- Business/branch management
- Product/inventory management
- Sales and shifts
- Refunds and quick sales
- Analytics
- Batch management

---

## 📞 Support & Resources

### Related Documentation

- `README.md` - Main API documentation
- `BUSINESS_ISOLATION.md` - Multi-tenant architecture
- `SALES_SHIFT_IMPLEMENTATION.md` - Shift management
- `SHELF_STORE_INVENTORY_SYSTEM.md` - Shelf/store details
- `PIN_LOGIN_REFERENCE.md` - PIN authentication

### API Routes

See `routes/api.php` for complete route definitions.

### Test with API Generator

Use the JavaScript API data generator in `/scripts` to populate test data:

```bash
cd scripts
npm install
npm run generate
```

---

## ✅ Validation

- [x] JSON syntax valid
- [x] All endpoints documented
- [x] Request bodies included
- [x] Headers configured
- [x] Variables defined
- [x] Auto-save scripts for IDs
- [x] Descriptions complete
- [x] Permissions noted
- [x] Workflow documented

---

**Collection is ready to use!** Import into Postman and start testing the updated API.
