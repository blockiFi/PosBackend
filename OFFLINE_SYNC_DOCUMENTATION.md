# Offline-First Synchronization System

## Overview

This document describes the complete offline-first synchronization system for the POS Backend. The system allows POS clients (web, desktop, mobile) to operate offline for extended periods and reliably synchronize data when connectivity is restored.

## Architecture

### Core Principles

1. **UUID-based Identification**: All syncable records use UUIDs for global uniqueness
2. **Version Control**: Each record has a version number that increments on updates
3. **Change Tracking**: All modifications are logged with timestamps and device info
4. **Idempotency**: Operations can be safely retried without duplication
5. **Conflict Detection**: Version mismatches trigger conflict resolution workflows
6. **Bi-directional Sync**: Both client→server (push) and server→client (pull)

### Database Schema

#### Sync Metadata Fields (Added to Existing Tables)

All syncable tables now include:
- `client_uuid` (string, 36): Client-generated UUID for offline records
- `version` (integer): Increments on each update
- `device_id` (string, 50): Originating device identifier
- `synced_at` (timestamp): Last successful sync time
- `sync_status` (enum): 'pending', 'synced', 'conflict'
- `origin` (string): 'online' or 'offline'

**Tables Enhanced:**
- sales
- sale_items
- payments
- inventory_transactions
- sales_shifts
- refund_requests
- quick_sales
- customers
- products (version & synced_at only)
- branch_products (version & synced_at only)

#### New Sync Tables

**device_registrations**
```sql
- id: Primary key
- device_id: Unique device identifier
- business_id: Foreign key to businesses
- branch_id: Foreign key to branches (nullable)
- user_id: Foreign key to users (nullable)
- device_name: Human-readable device name
- device_type: 'web', 'desktop', 'mobile', 'tablet'
- os: Operating system
- app_version: Client application version
- ip_address: Last known IP
- status: 'active', 'inactive', 'blocked'
- last_seen_at: Last heartbeat
- last_sync_at: Last successful sync
- total_syncs: Counter
- capabilities: JSON (offline_mode, auto_sync, etc.)
- metadata: JSON (additional device info)
```

**sync_sessions**
```sql
- id: Primary key
- session_id: UUID for this sync session
- device_id: Foreign key to device_registrations
- business_id: Foreign key to businesses
- user_id: Foreign key to users
- direction: 'pull', 'push', 'bidirectional'
- status: 'initiated', 'in_progress', 'completed', 'failed', 'partial'
- started_at: Session start time
- completed_at: Session end time
- records_pushed: Count of records sent to server
- records_pulled: Count of records sent to client
- conflicts_detected: Number of conflicts found
- conflicts_resolved: Number of conflicts resolved
- errors_count: Number of errors
- last_activity_at: Last operation timestamp
- summary: JSON (detailed stats per entity)
- error_message: Text description of failures
- metadata: JSON (additional session info)
```

**change_logs**
```sql
- id: Primary key
- business_id: Foreign key to businesses
- entity_type: 'sales', 'products', 'customers', etc.
- entity_id: ID of the changed record
- entity_uuid: UUID of the changed record
- action: 'created', 'updated', 'deleted'
- version: Version number after change
- device_id: Originating device
- user_id: User who made the change
- changes: JSON (old_value => new_value)
- changed_at: When the change occurred
- synced: Boolean (pushed to all clients)
```

---

## API Endpoints

### Base URL
All sync endpoints are under `/api/sync`

### Authentication
All endpoints require Bearer token authentication and business context.

---

### 1. Device Registration

**POST** `/sync/register-device`

Register a new POS device/client for sync operations.

**Request:**
```json
{
  "device_id": "POS-001-ABC123",
  "device_name": "Front Counter Terminal",
  "device_type": "desktop",
  "os": "Windows 11",
  "app_version": "2.1.0",
  "branch_id": 1,
  "capabilities": {
    "offline_mode": true,
    "auto_sync": true,
    "max_offline_days": 30
  }
}
```

**Response:** `201 Created`
```json
{
  "device": {
    "id": 5,
    "device_id": "POS-001-ABC123",
    "business_id": 1,
    "branch_id": 1,
    "status": "active",
    "created_at": "2026-02-08T10:00:00Z"
  },
  "sync_token": "eyJ0eXAiOiJKV1QiLCJhbGc..."
}
```

---

### 2. Bootstrap (Initial Data Download)

**POST** `/sync/bootstrap`

Download initial dataset for a new/reset device. Returns all data the device needs to operate offline.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
X-Device-Id: {device_id}
```

**Request:**
```json
{
  "branch_id": 1,
  "entities": [
    "products",
    "categories",
    "payment_methods",
    "customers",
    "branch_products"
  ],
  "include_history": false
}
```

**Response:** `200 OK`
```json
{
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "server_timestamp": "2026-02-08T10:00:00Z",
  "business_settings": {
    "allow_decimal_quantities": false,
    "deposit_stock_mode": "reserve_on_create"
  },
  "data": {
    "products": [
      {
        "id": 1,
        "uuid": "650e8400-e29b-41d4-a716-446655440001",
        "name": "Product A",
        "sku": "SKU001",
        "base_selling_price": 99.99,
        "version": 5,
        "synced_at": "2026-02-08T10:00:00Z"
      }
    ],
    "categories": [...],
    "payment_methods": [...],
    "customers": [...],
    "branch_products": [...]
  },
  "metadata": {
    "total_records": 1250,
    "checksum": "a1b2c3d4e5f6",
    "estimated_size_kb": 850
  }
}
```

Offline clients should cache `business_settings.allow_decimal_quantities` from bootstrap (or `GET settings/business`) and match quantity input UX to that flag. When the setting is **off**, sync push rejects fractional sale/GRN line quantities with HTTP 422.

---

### 3. Pull Changes (Server → Client)

**POST** `/sync/pull`

Retrieve changes made on server or by other devices since last sync.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
X-Device-Id: {device_id}
```

**Request:**
```json
{
  "last_sync_at": "2026-02-07T15:30:00Z",
  "entities": [
    "products",
    "customers",
    "branch_products"
  ],
  "limit": 500
}
```

**Response:** `200 OK`
```json
{
  "session_id": "660e8400-e29b-41d4-a716-446655440002",
  "server_timestamp": "2026-02-08T10:00:00Z",
  "changes": {
    "products": {
      "created": [],
      "updated": [
        {
          "id": 5,
          "uuid": "750e8400-e29b-41d4-a716-446655440003",
          "base_selling_price": 89.99,
          "version": 6,
          "changed_at": "2026-02-08T09:30:00Z",
          "changed_by": 2
        }
      ],
      "deleted": []
    },
    "customers": {
      "created": [
        {
          "id": 101,
          "customer_code": "CUST-101",
          "name": "John Doe",
          "client_uuid": "850e8400-e29b-41d4-a716-446655440004",
          "version": 1,
          "device_id": "POS-002-XYZ789"
        }
      ],
      "updated": [],
      "deleted": []
    }
  },
  "has_more": false,
  "next_cursor": null
}
```

---

### 4. Push Changes (Client → Server)

**POST** `/sync/push`

Upload offline-generated data to server with conflict detection.

**Fractional quantities:** Sale line `items[].quantity` accepts decimal values (minimum `0.01`, e.g. `10.5` for weight/volume products). Stock and batch deductions use the same quantity — no rounding to integers.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
X-Device-Id: {device_id}
```

**Request:**
```json
{
  "session_id": "770e8400-e29b-41d4-a716-446655440005",
  "batch_id": "batch-001",
  "changes": {
    "sales": [
      {
        "client_uuid": "880e8400-e29b-41d4-a716-446655440006",
        "sale_number": "SALE-20260207-001",
        "branch_id": 1,
        "shift_id": null,
        "customer_id": 5,
        "sale_type": "pos",
        "sale_date": "2026-02-07T14:30:00Z",
        "subtotal": 150.00,
        "tax": 22.50,
        "total": 172.50,
        "payment_status": "paid",
        "status": "completed",
        "version": 1,
        "origin": "offline",
        "items": [
          {
            "client_uuid": "990e8400-e29b-41d4-a716-446655440007",
            "product_id": 5,
            "quantity": 2,
            "unit_price": 75.00,
            "subtotal": 150.00
          }
        ],
        "payments": [
          {
            "client_uuid": "aa0e8400-e29b-41d4-a716-446655440008",
            "payment_method_id": 1,
            "amount": 172.50,
            "payment_date": "2026-02-07T14:30:00Z"
          }
        ]
      }
    ],
    "customers": [
      {
        "client_uuid": "bb0e8400-e29b-41d4-a716-446655440009",
        "customer_code": "CUST-102",
        "name": "Jane Smith",
        "email": "jane@example.com",
        "type": "walk-in",
        "version": 1,
        "origin": "offline"
      }
    ]
  }
}
```

**Response:** `200 OK` (Success)
```json
{
  "session_id": "770e8400-e29b-41d4-a716-446655440005",
  "status": "completed",
  "results": {
    "sales": {
      "accepted": 1,
      "rejected": 0,
      "conflicts": 0,
      "mappings": {
        "880e8400-e29b-41d4-a716-446655440006": {
          "server_id": 245,
          "sale_number": "SALE-20260207-001",
          "status": "synced"
        }
      }
    },
    "customers": {
      "accepted": 1,
      "rejected": 0,
      "conflicts": 0,
      "mappings": {
        "bb0e8400-e29b-41d4-a716-446655440009": {
          "server_id": 102,
          "customer_code": "CUST-102",
          "status": "synced"
        }
      }
    }
  },
  "server_timestamp": "2026-02-08T10:05:00Z"
}
```

**Response:** `207 Multi-Status` (Partial Success with Conflicts)
```json
{
  "session_id": "770e8400-e29b-41d4-a716-446655440005",
  "status": "partial",
  "results": {
    "sales": {
      "accepted": 0,
      "rejected": 0,
      "conflicts": 1,
      "conflicts_details": [
        {
          "client_uuid": "880e8400-e29b-41d4-a716-446655440006",
          "conflict_type": "inventory_insufficient",
          "reason": "Product 5: Requested 2 units, available 0 units",
          "client_version": 1,
          "server_state": {
            "product_id": 5,
            "available_quantity": 0,
            "last_updated": "2026-02-08T09:00:00Z"
          },
          "resolution_options": [
            "reject_sale",
            "partial_fulfillment",
            "manual_review"
          ]
        }
      ]
    }
  }
}
```

---

### 5. Resolve Conflicts

**POST** `/sync/resolve-conflicts`

Submit conflict resolution decisions for server processing.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
X-Device-Id: {device_id}
```

**Request:**
```json
{
  "session_id": "770e8400-e29b-41d4-a716-446655440005",
  "resolutions": [
    {
      "client_uuid": "880e8400-e29b-41d4-a716-446655440006",
      "entity_type": "sales",
      "resolution_strategy": "server_wins",
      "action": "reject_sale",
      "notes": "Insufficient inventory, sale cancelled"
    }
  ]
}
```

**Response:** `200 OK`
```json
{
  "resolved": 1,
  "failed": 0,
  "results": [
    {
      "client_uuid": "880e8400-e29b-41d4-a716-446655440006",
      "status": "resolved",
      "final_action": "rejected",
      "server_timestamp": "2026-02-08T10:10:00Z"
    }
  ]
}
```

---

### 6. Sync Status

**GET** `/sync/status`

Check current sync status and pending changes.

**Headers:**
```
Authorization: Bearer {token}
X-Business-Id: {business_id}
X-Device-Id: {device_id}
```

**Query Parameters:**
- `include_pending`: boolean (default: true)

**Response:** `200 OK`
```json
{
  "device": {
    "device_id": "POS-001-ABC123",
    "status": "active",
    "last_sync_at": "2026-02-08T09:00:00Z",
    "total_syncs": 45
  },
  "pending_changes": {
    "server_to_client": 5,
    "conflicts": 0
  },
  "last_session": {
    "session_id": "770e8400-e29b-41d4-a716-446655440005",
    "status": "completed",
    "completed_at": "2026-02-08T10:05:00Z"
  },
  "server_timestamp": "2026-02-08T10:15:00Z"
}
```

---

### 7. Heartbeat

**POST** `/sync/heartbeat`

Keep device registration active and check for urgent updates.

**Headers:**
```
Authorization: Bearer {token}
X-Device-Id: {device_id}
```

**Response:** `200 OK`
```json
{
  "status": "ok",
  "server_timestamp": "2026-02-08T10:20:00Z",
  "has_pending_changes": false,
  "should_sync": false,
  "messages": []
}
```

---

## Conflict Resolution

### Conflict Types

1. **Version Mismatch**: Record updated on both server and client
2. **Inventory Conflict**: Insufficient stock for offline sale
3. **Price Conflict**: Product price changed while offline
4. **Shift Conflict**: Shift closed while offline sales recorded
5. **Deleted Entity**: Record deleted on server, updated on client
6. **Duplicate Detection**: Same transaction submitted twice

### Resolution Strategies

**Client Wins**: Accept client data, overwrite server
**Server Wins**: Reject client data, use server data
**Manual Review**: Flag for administrator review
**Merge**: Combine non-conflicting fields
**Split Transaction**: Separate into multiple records

### Conflict Resolution Priority

1. **Financial Integrity**: Never duplicate payments
2. **Inventory Accuracy**: Prevent negative stock
3. **Shift Integrity**: Maintain shift accountability
4. **Audit Trail**: Preserve complete history

---

## Client Implementation Guide

### Offline Operation Flow

```javascript
// 1. Register device
const device = await registerDevice({
  device_id: generateDeviceId(),
  device_name: "POS Terminal 1",
  device_type: "desktop",
  branch_id: 1
});

// 2. Bootstrap initial data
const initialData = await bootstrap({
  branch_id: 1,
  entities: ["products", "customers", "payment_methods"]
});

// 3. Store data locally (IndexedDB, SQLite, etc.)
await localDB.bulkInsert('products', initialData.products);

// 4. Operate offline
const sale = await createOfflineSale({
  client_uuid: generateUUID(),
  items: [...],
  payments: [...],
  origin: "offline"
});

// 5. Sync when online
const syncResult = await pushChanges({
  changes: {
    sales: [sale]
  }
});

// 6. Handle conflicts
if (syncResult.status === 'partial') {
  for (const conflict of syncResult.conflicts) {
    const resolution = await resolveConflict(conflict);
    await submitResolution(resolution);
  }
}

// 7. Pull server changes
const serverChanges = await pullChanges({
  last_sync_at: localStorage.get('last_sync')
});

// 8. Update local database
await localDB.applyChanges(serverChanges);
```

### Best Practices

1. **Generate UUIDs Client-Side**: Use UUID v4 for all offline records
2. **Timestamp Everything**: Record local timestamps for all operations
3. **Queue Operations**: Maintain offline queue for sync retry
4. **Validate Before Sync**: Check data integrity before pushing
5. **Handle Partial Success**: Process accepted records even if some conflict
6. **Retry Failed Syncs**: Implement exponential backoff
7. **Preserve Order**: Sync in chronological order (shifts before sales)
8. **Version Tracking**: Store version numbers locally
9. **Conflict UI**: Provide clear conflict resolution interface
10. **Data Compression**: Compress large sync payloads

---

## Security Considerations

1. **Device Authentication**: Each device has unique credentials
2. **Token Rotation**: Refresh tokens periodically
3. **Data Encryption**: Encrypt sensitive data in transit and at rest
4. **Tamper Detection**: Verify data integrity with checksums
5. **Access Control**: Enforce branch/user permissions during sync
6. **Audit Logging**: Track all sync operations
7. **Rate Limiting**: Prevent sync abuse
8. **Device Blocking**: Block compromised devices

---

## Performance Optimization

1. **Batch Processing**: Process records in configurable batch sizes
2. **Pagination**: Use cursors for large datasets
3. **Selective Sync**: Only sync required entities
4. **Delta Sync**: Transfer only changes, not full records
5. **Compression**: gzip response payloads
6. **Caching**: Cache frequently accessed reference data
7. **Background Processing**: Queue heavy sync operations
8. **Database Indexing**: Index sync_status, synced_at, device_id

---

## Error Handling

### Common Errors

**409 Conflict**: Version mismatch or data conflict
```json
{
  "error": "conflict",
  "conflicts": [...]
}
```

**422 Validation Error**: Invalid data format
```json
{
  "error": "validation_failed",
  "errors": {...}
}
```

**429 Too Many Requests**: Rate limit exceeded
```json
{
  "error": "rate_limit_exceeded",
  "retry_after": 60
}
```

**503 Service Unavailable**: Server temporarily unavailable
```json
{
  "error": "service_unavailable",
  "retry_after": 300
}
```

### Retry Strategy

1. First retry: Immediate
2. Second retry: 5 seconds
3. Third retry: 30 seconds
4. Fourth retry: 2 minutes
5. Fifth+ retry: 15 minutes

Maximum retries: 10 before requiring manual intervention

---

## Testing Recommendations

1. **Network Interruption**: Test mid-sync disconnection
2. **Concurrent Edits**: Multiple devices editing same record
3. **Large Batches**: 1000+ records sync
4. **Extended Offline**: 7+ days offline operation
5. **Clock Skew**: Client/server time differences
6. **Data Corruption**: Invalid data handling
7. **Version Conflicts**: Rapid successive updates
8. **Resource Limits**: Mobile device memory constraints

---

## Monitoring & Analytics

Track these metrics:

1. **Sync Success Rate**: Percentage of successful syncs
2. **Conflict Rate**: Conflicts per 1000 records
3. **Sync Duration**: Average time to complete sync
4. **Data Volume**: Records synced per session
5. **Device Health**: Active/inactive device ratio
6. **Offline Duration**: Average offline periods
7. **Error Frequency**: Errors by type
8. **Resolution Time**: Time to resolve conflicts

---

## Migration Path

### Existing Installations

1. **Run Migrations**: Apply sync table schema changes
2. **Backfill UUIDs**: Generate client_uuid for existing records
3. **Initialize Versions**: Set version=1 for all records
4. **Mark as Synced**: Set sync_status='synced', synced_at=now()
5. **Register Devices**: Create device registrations for active terminals
6. **Enable Sync**: Activate sync endpoints
7. **Monitor**: Watch for sync errors during transition

---

## Future Enhancements

1. **Partial Record Sync**: Sync specific fields only
2. **Peer-to-Peer Sync**: Direct device-to-device sync
3. **Automatic Conflict Resolution**: AI-powered conflict resolution
4. **Real-time Sync**: WebSocket-based instant sync
5. **Sync Scheduling**: Configurable sync intervals
6. **Data Pruning**: Auto-delete old sync logs
7. **Multi-region Sync**: Cross-datacenter synchronization
8. **Offline Analytics**: Local analytics without server

---

**Document Version**: 1.0  
**Last Updated**: February 8, 2026  
**Author**: POS Backend Team
