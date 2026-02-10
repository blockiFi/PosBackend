# Offline Sync System - Complete Setup & Usage Guide

## Table of Contents
1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Prerequisites](#prerequisites)
4. [Initial Setup](#initial-setup)
5. [Device Registration](#device-registration)
6. [Bootstrap Process](#bootstrap-process)
7. [Sync Operations](#sync-operations)
8. [Conflict Resolution](#conflict-resolution)
9. [Best Practices](#best-practices)
10. [Troubleshooting](#troubleshooting)

---

## Overview

The Offline Sync System enables POS devices to:
- Work completely offline when internet is unavailable
- Store transactions locally (sales, customers, inventory)
- Automatically sync data when connection is restored
- Handle conflicts intelligently
- Track device status and sync history

### Key Features
✅ **Bidirectional Sync** - Pull server changes & Push offline changes  
✅ **Conflict Detection** - Version-based conflict resolution  
✅ **Batch Processing** - Efficient data transfer  
✅ **Device Management** - Track multiple devices per branch  
✅ **Change Tracking** - Complete audit trail  
✅ **Heartbeat Monitoring** - Real-time device status  

---

## Architecture

### Components

```
┌─────────────────┐         ┌──────────────────┐         ┌─────────────────┐
│   POS Device    │◄───────►│  Laravel Server  │◄───────►│   Database      │
│  (Offline DB)   │  Sync   │  (API Backend)   │         │  (PostgreSQL)   │
└─────────────────┘         └──────────────────┘         └─────────────────┘
        │                            │                            │
        │                            │                            │
   IndexedDB/                   SyncController              device_registrations
   SQLite/Local                 ChangeLog Model             sync_sessions
   Storage                      DeviceRegistration          change_logs
                                                           sales (synced)
```

### Data Flow

**Push (Device → Server):**
```
Offline Device → Create Local Records → Queue for Sync → Push Batch → 
Server Validates → Resolve Conflicts → Update DB → Return Mappings → 
Update Local IDs
```

**Pull (Server → Device):**
```
Device Requests → Provide Last Sync Time → Server Finds Changes → 
Filter by Device → Return Delta → Device Applies Changes → 
Update Local DB
```

---

## Prerequisites

### Server Requirements
- ✅ Laravel 11 installed and configured
- ✅ Database with sync tables migrated
- ✅ API authentication (Sanctum) working
- ✅ Business context middleware enabled

### Client Requirements
- ✅ Modern browser/app with local storage
- ✅ Ability to store data offline (IndexedDB/SQLite)
- ✅ Network detection capability
- ✅ UUID generation for client records

### Database Tables Required
```sql
-- Core sync tables (already in migrations)
- device_registrations  (track devices)
- sync_sessions        (sync history)
- change_logs         (track changes)

-- Synced entity tables need these columns:
- client_uuid         (unique identifier from device)
- device_id          (which device created it)
- version            (for conflict resolution)
- sync_status        (pending, synced, conflict)
- synced_at         (last sync timestamp)
- origin            (online, offline)
```

---

## Initial Setup

### Step 1: Verify API Routes

Check that sync routes are available:
```bash
php artisan route:list --path=sync
```

Expected routes:
```
POST   /api/sync/register-device     - Register new device
POST   /api/sync/bootstrap          - Initial data download
POST   /api/sync/pull              - Get server changes
POST   /api/sync/push              - Send offline changes
GET    /api/sync/status            - Check sync status
POST   /api/sync/heartbeat         - Device keepalive
POST   /api/sync/resolve-conflicts - Handle conflicts
```

### Step 2: Configure Permissions

Ensure users have required permissions:
```php
// In your permission seeder
'sync data',           // Basic sync permission
'manage offline mode', // Configure offline settings
'resolve conflicts',   // Handle sync conflicts
```

### Step 3: Client-Side Setup

Install required dependencies (example for web app):
```javascript
// For IndexedDB
npm install dexie  // Modern IndexedDB wrapper

// For offline detection
npm install @vueuse/core  // Includes useNetwork()
```

Create offline database schema:
```javascript
import Dexie from 'dexie';

const db = new Dexie('POSOfflineDB');
db.version(1).stores({
  sales: '++id, client_uuid, sync_status, created_at',
  sale_items: '++id, sale_uuid, product_id',
  payments: '++id, sale_uuid, payment_method_id',
  customers: '++id, client_uuid, sync_status',
  products: 'id, uuid, updated_at',
  categories: 'id, uuid',
  payment_methods: 'id',
  sync_queue: '++id, entity_type, action, created_at',
  device_info: 'key'
});
```

---

## Device Registration

### Step 1: Register Device

**When to register:**
- First time app opens on a device
- After factory reset
- When switching branches

**API Call:**
```http
POST /api/sync/register-device
Authorization: Bearer {user_token}
X-Business-Id: {business_id}
Content-Type: application/json

{
  "device_id": "POS-001-ABC123",        // Unique device identifier
  "device_name": "Cashier Station 1",  // Human-readable name
  "device_type": "desktop",            // web, desktop, mobile, tablet
  "os": "Windows 11",                  // Optional
  "app_version": "1.0.0",             // Optional
  "branch_id": 1,                      // Branch this device belongs to
  "capabilities": {                    // Optional features
    "offline_mode": true,
    "barcode_scanner": true,
    "receipt_printer": true
  }
}
```

**Success Response:**
```json
{
  "device": {
    "id": 45,
    "device_id": "POS-001-ABC123",
    "device_name": "Cashier Station 1",
    "device_type": "desktop",
    "branch_id": 1,
    "status": "active",
    "last_seen_at": "2026-02-10T10:30:00Z",
    "created_at": "2026-02-10T10:30:00Z"
  },
  "sync_token": "your-auth-token-here"
}
```

**Client Code Example:**
```javascript
async function registerDevice() {
  const deviceId = localStorage.getItem('device_id') || generateDeviceId();
  
  const response = await fetch('/api/sync/register-device', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${authToken}`,
      'X-Business-Id': businessId,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      device_id: deviceId,
      device_name: 'Cashier Station 1',
      device_type: 'desktop',
      branch_id: currentBranchId,
      capabilities: {
        offline_mode: true,
        barcode_scanner: true
      }
    })
  });
  
  const data = await response.json();
  
  // Store device info locally
  await db.device_info.put({
    key: 'registration',
    device_id: data.device.device_id,
    registered_at: new Date()
  });
  
  localStorage.setItem('device_id', deviceId);
  return data;
}

function generateDeviceId() {
  const prefix = 'POS';
  const branchCode = '001';
  const unique = Math.random().toString(36).substr(2, 9).toUpperCase();
  return `${prefix}-${branchCode}-${unique}`;
}
```

---

## Bootstrap Process

### What is Bootstrap?

Bootstrap downloads the initial dataset when:
- Device registers for first time
- App is reinstalled
- Data needs to be reset
- Switching to a different branch

### Step 2: Bootstrap Initial Data

**API Call:**
```http
POST /api/sync/bootstrap
Authorization: Bearer {token}
X-Business-Id: {business_id}
X-Device-Id: POS-001-ABC123
Content-Type: application/json

{
  "branch_id": 1,
  "entities": [
    "products",
    "categories",
    "payment_methods",
    "customers",
    "branch_products"
  ],
  "include_history": false  // Set true to include deleted records
}
```

**Response:**
```json
{
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "server_timestamp": "2026-02-10T10:35:00Z",
  "data": {
    "products": [
      {
        "id": 1,
        "uuid": "prod-uuid-1",
        "name": "iPhone 14 Pro",
        "sku": "IPH14PRO",
        "barcode": "1234567890",
        "base_selling_price": "999.99",
        "base_cost_price": "750.00",
        "stock_tracking": "simple",
        "version": 1
      }
      // ... more products
    ],
    "categories": [...],
    "payment_methods": [...],
    "customers": [...],
    "branch_products": [...]
  },
  "metadata": {
    "total_records": 1247,
    "checksum": "a1b2c3d4e5f6",
    "estimated_size_kb": 342.5
  }
}
```

**Client Implementation:**
```javascript
async function bootstrapDevice() {
  console.log('Starting bootstrap...');
  
  const deviceId = localStorage.getItem('device_id');
  const response = await fetch('/api/sync/bootstrap', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${authToken}`,
      'X-Business-Id': businessId,
      'X-Device-Id': deviceId,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      branch_id: currentBranchId,
      entities: [
        'products',
        'categories',
        'payment_methods',
        'customers',
        'branch_products'
      ]
    })
  });
  
  const data = await response.json();
  
  // Clear existing data
  await db.transaction('rw', [
    db.products,
    db.categories,
    db.payment_methods,
    db.customers
  ], async () => {
    await db.products.clear();
    await db.categories.clear();
    await db.payment_methods.clear();
    await db.customers.clear();
    
    // Import new data
    if (data.data.products) {
      await db.products.bulkPut(data.data.products);
    }
    if (data.data.categories) {
      await db.categories.bulkPut(data.data.categories);
    }
    if (data.data.payment_methods) {
      await db.payment_methods.bulkPut(data.data.payment_methods);
    }
    if (data.data.customers) {
      await db.customers.bulkPut(data.data.customers);
    }
  });
  
  // Store bootstrap info
  await db.device_info.put({
    key: 'last_bootstrap',
    timestamp: new Date(data.server_timestamp),
    total_records: data.metadata.total_records,
    checksum: data.metadata.checksum
  });
  
  console.log(`Bootstrap complete: ${data.metadata.total_records} records`);
  return data;
}
```

---

## Sync Operations

### Continuous Sync Strategy

``` 
// Main sync manager
class SyncManager {
  constructor() {
    this.syncInterval = null;
    this.isOnline = navigator.onLine;
    this.isSyncing = false;
    
    // Listen for online/offline events
    window.addEventListener('online', () => this.handleOnline());
    window.addEventListener('offline', () => this.handleOffline());
  }
  
  async start() {
    // Initial sync
    if (this.isOnline) {
      await this.performSync();
    }
    
    // Periodic sync every 30 seconds when online
    this.syncInterval = setInterval(async () => {
      if (this.isOnline && !this.isSyncing) {
        await this.performSync();
      }
    }, 30000);
  }
  
  async handleOnline() {
    console.log('Device is back online');
    this.isOnline = true;
    await this.performSync();
  }
  
  handleOffline() {
    console.log('Device is offline');
    this.isOnline = false;
  }
  
  async performSync() {
    if (this.isSyncing) return;
    
    try {
      this.isSyncing = true;
      
      // 1. Send heartbeat
      await this.heartbeat();
      
      // 2. Push offline changes
      const pushResult = await this.pushChanges();
      
      // 3. Pull server changes
      const pullResult = await this.pullChanges();
      
      console.log('Sync complete', { pushResult, pullResult });
      
    } catch (error) {
      console.error('Sync failed:', error);
    } finally {
      this.isSyncing = false;
    }
  }
  
  async heartbeat() {
    const deviceId = localStorage.getItem('device_id');
    const response = await fetch('/api/sync/heartbeat', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${authToken}`,
        'X-Device-Id': deviceId
      }
    });
    
    const data = await response.json();
    return data;
  }
  
  async pushChanges() {
    // Get unsynced records
    const unsyncedSales = await db.sales
      .where('sync_status').equals('pending')
      .toArray();
    
    const unsyncedCustomers = await db.customers
      .where('sync_status').equals('pending')
      .toArray();
    
    if (unsyncedSales.length === 0 && unsyncedCustomers.length === 0) {
      return { status: 'nothing_to_sync' };
    }
    
    // Prepare batch
    const changes = {};
    
    if (unsyncedSales.length > 0) {
      changes.sales = await Promise.all(
        unsyncedSales.map(async (sale) => {
          const items = await db.sale_items
            .where('sale_uuid').equals(sale.client_uuid)
            .toArray();
          
          const payments = await db.payments
            .where('sale_uuid').equals(sale.client_uuid)
            .toArray();
          
          return {
            ...sale,
            items,
            payments
          };
        })
      );
    }
    
    if (unsyncedCustomers.length > 0) {
      changes.customers = unsyncedCustomers;
    }
    
    // Push to server
    const sessionId = generateUUID();
    const deviceId = localStorage.getItem('device_id');
    
    const response = await fetch('/api/sync/push', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${authToken}`,
        'X-Business-Id': businessId,
        'X-Device-Id': deviceId,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        session_id: sessionId,
        changes
      })
    });
    
    const result = await response.json();
    
    // Update local records with server IDs
    if (result.results.sales) {
      for (const [clientUuid, mapping] of Object.entries(result.results.sales.mappings)) {
        await db.sales.where('client_uuid').equals(clientUuid).modify({
          id: mapping.server_id,
          sync_status: 'synced',
          synced_at: new Date()
        });
      }
    }
    
    if (result.results.customers) {
      for (const [clientUuid, mapping] of Object.entries(result.results.customers.mappings)) {
        await db.customers.where('client_uuid').equals(clientUuid).modify({
          id: mapping.server_id,
          sync_status: 'synced',
          synced_at: new Date()
        });
      }
    }
    
    return result;
  }
  
  async pullChanges() {
    // Get last sync time
    const lastSync = await db.device_info.get('last_pull_sync');
    const lastSyncAt = lastSync?.timestamp || new Date(Date.now() - 24 * 60 * 60 * 1000); // Default: 24h ago
    
    const deviceId = localStorage.getItem('device_id');
    
    const response = await fetch('/api/sync/pull', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${authToken}`,
        'X-Business-Id': businessId,
        'X-Device-Id': deviceId,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        last_sync_at: lastSyncAt.toISOString(),
        entities: ['products', 'customers', 'branch_products'],
        limit: 500
      })
    });
    
    const data = await response.json();
    
    // Apply changes
    if (data.changes.products) {
      const { created, updated, deleted } = data.changes.products;
      
      if (created.length > 0) {
        await db.products.bulkPut(created);
      }
      
      if (updated.length > 0) {
        await db.products.bulkPut(updated);
      }
      
      if (deleted.length > 0) {
        await db.products.bulkDelete(deleted.map(d => d.id));
      }
    }
    
    // Similar for customers, branch_products, etc.
    
    // Update last sync time
    await db.device_info.put({
      key: 'last_pull_sync',
      timestamp: new Date(data.server_timestamp)
    });
    
    return data;
  }
}

// Initialize sync manager
const syncManager = new SyncManager();
syncManager.start();
```

---

## Conflict Resolution

### When Conflicts Occur

Conflicts happen when:
- Same record modified on server and offline device
- Version numbers don't match
- Record deleted on server but modified offline

### Conflict Resolution Strategies

**1. Last-Write-Wins (Automatic)**
```javascript
// Server compares versions
if (incomingVersion > currentVersion) {
  // Accept change
  updateRecord(incomingData);
} else {
  // Reject - report conflict
  return { status: 'conflict', current: currentRecord };
}
```

**2. Manual Resolution (User Decides)**
```javascript
// When conflict detected
if (result.results.sales.conflicts > 0) {
  const conflicts = result.results.sales.conflicts_details;
  
  // Show UI to user
  showConflictResolution(conflicts);
}

async function resolveConflict(conflictId, resolution) {
  // resolution: 'use_server', 'use_client', 'merge'
  const response = await fetch('/api/sync/resolve-conflicts', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${authToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      conflict_id: conflictId,
      resolution: resolution,
      merged_data: mergedData // If resolution is 'merge'
    })
  });
  
  return await response.json();
}
```

---

## Best Practices

### 1. Always Use UUIDs
```javascript
function createSale(saleData) {
  return {
    ...saleData,
    client_uuid: generateUUID(),  // Critical for sync
    version: 1,
    sync_status: 'pending',
    created_at: new Date(),
    origin: 'offline'
  };
}
```

### 2. Queue All Changes
```javascript
async function saveSale(sale) {
  // Save to local DB
  const id = await db.sales.add(sale);
  
  // Add to sync queue
  await db.sync_queue.add({
    entity_type: 'sales',
    entity_id: id,
    action: 'create',
    created_at: new Date()
  });
  
  // Trigger sync if online
  if (navigator.onLine) {
    syncManager.performSync();
  }
}
```

### 3. Handle Network Errors
```javascript
async function syncWithRetry(maxRetries = 3) {
  for (let i = 0; i < maxRetries; i++) {
    try {
      return await syncManager.pushChanges();
    } catch (error) {
      if (i === maxRetries - 1) throw error;
      
      // Exponential backoff
      await sleep(Math.pow(2, i) * 1000);
    }
  }
}
```

### 4. Monitor Sync Status
```javascript
// Display sync indicator
function updateSyncUI() {
  const indicator = document.getElementById('sync-status');
  
  if (!navigator.onLine) {
    indicator.textContent = '🔴 Offline';
    indicator.className = 'offline';
  } else if (syncManager.isSyncing) {
    indicator.textContent = '🟡 Syncing...';
    indicator.className = 'syncing';
  } else {
    indicator.textContent = '🟢 Online';
    indicator.className = 'online';
  }
}
```

---

## Troubleshooting

### Common Issues

**1. Device Not Registering**
```bash
# Check if route exists
php artisan route:list | grep sync

# Check authentication
# Ensure user is logged in and has valid token

# Check business context
# X-Business-Id header must be present
```

**2. Bootstrap Fails**
```bash
# Check table columns exist
php artisan migrate:status

# Run migrations if needed
php artisan migrate

# Check data exists
php artisan tinker
>>> App\Models\Product::count()
```

**3. Push Sync Fails**
```javascript
// Check for missing required fields
console.log('Missing fields:', sale);

// Verify UUIDs are unique
const duplicates = await db.sales
  .where('client_uuid').equals(sale.client_uuid)
  .count();

// Check server logs
tail -f storage/logs/laravel.log
```

**4. Pull Sync Not Working**
```javascript
// Verify last_sync_at format
console.log(new Date().toISOString());
// Should be: "2026-02-10T10:30:00.000Z"

// Check device_id header
console.log(localStorage.getItem('device_id'));

// Verify changes exist on server
// Check change_logs table
```

### Debug Mode

Enable detailed logging:
```javascript
class SyncManager {
  constructor() {
    this.debugMode = true;  // Set to true
  }
  
  log(message, data = null) {
    if (this.debugMode) {
      console.log(`[SYNC] ${message}`, data);
    }
  }
}
```

---

## Testing the System

### 1. Test Device Registration
```bash
curl -X POST http://localhost:8000/api/sync/register-device \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Business-Id: 1" \
  -H "Content-Type: application/json" \
  -d '{
    "device_id": "TEST-001",
    "device_name": "Test Device",
    "device_type": "desktop",
    "branch_id": 1
  }'
```

### 2. Test Bootstrap
```bash
curl -X POST http://localhost:8000/api/sync/bootstrap \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Business-Id: 1" \
  -H "X-Device-Id: TEST-001" \
  -H "Content-Type: application/json" \
  -d '{
    "branch_id": 1,
    "entities": ["products", "categories"]
  }'
```

### 3. Test Offline Sale Creation
```javascript
// Create sale while offline
const sale = {
  client_uuid: generateUUID(),
  branch_id: 1,
  sale_number: 'OFF-001',
  total: 100.00,
  sync_status: 'pending',
  version: 1
};

await db.sales.add(sale);

// Go back online and sync
await syncManager.pushChanges();
```

---

## Summary

### Quick Start Checklist

- [ ] Verify sync routes exist (`php artisan route:list`)
- [ ] Set up client offline database (IndexedDB/SQLite)
- [ ] Register device via API
- [ ] Bootstrap initial data
- [ ] Implement sync manager with push/pull
- [ ] Handle online/offline events
- [ ] Test with simulated offline sales
- [ ] Monitor sync status in UI

### Key Endpoints

| Endpoint | Purpose | When to Use |
|----------|---------|-------------|
| `/sync/register-device` | Register POS device | First time setup |
| `/sync/bootstrap` | Download initial data | After registration or reset |
| `/sync/pull` | Get server changes | Periodic sync (every 30s) |
| `/sync/push` | Send offline changes | When online or periodically |
| `/sync/status` | Check sync state | Status dashboard |
| `/sync/heartbeat` | Keep device active | Every 30s when online |

### Next Steps

1. Implement client-side offline database
2. Create UI for sync status
3. Test with real devices
4. Monitor sync_sessions table
5. Set up alerts for failed syncs

---

**For support or questions, refer to the SyncController.php implementation or check the change_logs table for debugging.**
