# Server-to-Server Sync Setup Guide

## Overview

This guide explains how to set up and use the **Server-to-Server Sync** architecture where multiple local servers sync with a central cloud server. This approach is ideal for POS systems with multiple devices per branch.

## Architecture

```
┌─────────────────────────────────────────────────┐
│              Cloud Server (Master)              │
│         https://pos-cloud.example.com           │
│                                                 │
│  • Master database                              │
│  • Receives data from all branches              │
│  • Provides updates to all branches             │
└────────┬──────────────┬──────────────┬──────────┘
         │              │              │
    Sync │ (30s)   Sync │ (30s)   Sync │ (30s)
         │              │              │
┌────────▼────────┐ ┌───▼──────────┐ ┌─▼───────────┐
│  Branch Server  │ │ Branch Server│ │Branch Server│
│   (Lagos LAN)   │ │ (Abuja LAN)  │ │(Ibadan LAN) │
│ 192.168.1.100   │ │192.168.1.100 │ │192.168.1.100│
└────────┬────────┘ └───┬──────────┘ └─┬───────────┘
         │              │              │
    ┌────┴────┐    ┌────┴────┐    ┌────┴────┐
    │ POS #1  │    │ POS #1  │    │ POS #1  │
    │ POS #2  │    │ POS #2  │    │ POS #2  │
    │ POS #3  │    │ POS #3  │    │ POS #3  │
    └─────────┘    └─────────┘    └─────────┘
```

## Key Concepts

**Cloud Server (Master)**
- Central server hosted online (e.g., AWS, DigitalOcean)
- Receives sales, customers, and other data from branches
- Provides product updates, price changes to branches
- Accessible via internet

**Edge Server (Branch)**
- Local server in each branch location
- Runs on local network (LAN)
- Full Laravel application with PostgreSQL
- Auto-syncs with cloud every 30 seconds
- Works offline if internet down

**POS Clients**
- Thin clients (web browsers)
- Connect to local branch server only
- Fast response (local network)
- No offline database needed

## Benefits

✅ **Simple Clients** - POS devices are just web browsers  
✅ **Full Offline** - Branch continues working if internet fails  
✅ **Fast Performance** - Local LAN speed for all operations  
✅ **Centralized Data** - Cloud server has all branch data  
✅ **Easy Updates** - Deploy code changes once per location  
✅ **Scalable** - Add branches without affecting others  

---

## Setup Instructions

### Part 1: Cloud Server Setup

#### 1.1 Deploy Laravel to Cloud

```bash
# On your cloud server (Ubuntu/DigitalOcean/AWS)
git clone your-repo
cd PosBackend
composer install --no-dev
cp .env.example .env
php artisan key:generate
```

#### 1.2 Configure Cloud Environment

Edit `.env`:
```bash
APP_NAME="POS Cloud Server"
APP_ENV=production
APP_URL=https://pos-cloud.example.com

DB_CONNECTION=pgsql
DB_HOST=your-cloud-db-host
DB_DATABASE=pos_cloud
DB_USERNAME=postgres
DB_PASSWORD=your-secure-password

# Sync Configuration
SYNC_MODE=cloud
LOCAL_SERVER_ID=cloud-master-001
```

#### 1.3 Run Migrations

```bash
php artisan migrate --force
php artisan db:seed --force
```

#### 1.4 Generate Server Token

```bash
# Create a long-lived token for edge servers to authenticate
php artisan tinker

>>> $user = User::first(); // Or create a dedicated sync user
>>> $token = $user->createToken('edge-server-sync', ['*'])->plainTextToken;
>>> echo $token;
"1|AbCdEfGhIjKlMnOpQrStUvWxYz..."

# Save this token - edge servers will use it
```

---

### Part 2: Edge Server Setup (Branch)

#### 2.1 Prepare Branch Hardware

**Recommended Specs:**
- Intel i3 or better
- 8GB RAM minimum
- 256GB SSD
- Ubuntu Server 22.04 LTS
- UPS (for power backup)
- Gigabit Ethernet

#### 2.2 Install Laravel on Branch Server

```bash
# On branch server (192.168.1.100)
git clone your-repo
cd PosBackend
composer install --no-dev
cp .env.example .env
php artisan key:generate
```

#### 2.3 Configure Edge Environment

Edit `.env`:
```bash
APP_NAME="POS Branch Server - Lagos"
APP_ENV=production
APP_URL=http://192.168.1.100:8000

DB_CONNECTION=pgsql
DB_HOST=localhost
DB_DATABASE=pos_branch_lagos
DB_USERNAME=postgres
DB_PASSWORD=branch-secure-password

# Sync Configuration
SYNC_MODE=edge
LOCAL_SERVER_ID=edge-lagos-001
LOCAL_SERVER_URL=http://192.168.1.100:8000/api

# Cloud Server Connection
CLOUD_SERVER_URL=https://pos-cloud.example.com/api
CLOUD_SERVER_TOKEN=1|AbCdEfGhIjKlMnOpQrStUvWxYz...

# Sync Settings
AUTO_SYNC=true
SYNC_INTERVAL=30
```

#### 2.4 Run Migrations

```bash
php artisan migrate --force
php artisan db:seed --force
```

#### 2.5 Start Services

```bash
# Start Laravel
php artisan serve --host=0.0.0.0 --port=8000 &

# Start scheduler (for auto-sync)
php artisan schedule:work &

# Or use supervisor for production
sudo supervisorctl start laravel-worker
sudo supervisorctl start laravel-scheduler
```

---

### Part 3: Configure POS Clients

#### 3.1 Client Configuration

Each POS device just needs:
```javascript
// config.js
const API_URL = 'http://192.168.1.100:8000/api';

// All requests go to local server
fetch(`${API_URL}/products`, {
  headers: {
    'Authorization': `Bearer ${userToken}`,
    'X-Business-Id': businessId
  }
});
```

#### 3.2 Network Setup

Ensure POS devices can reach branch server:
```bash
# On POS device, test connectivity
ping 192.168.1.100
curl http://192.168.1.100:8000/api/server-sync/health
```

---

## Using Server Sync

### Automatic Sync (Recommended)

The system syncs automatically every 30 seconds:

**What Happens:**
1. Edge server creates a sale → Stored in local DB
2. After 30s → Scheduler runs `server:sync`
3. Push phase → Sale sent to cloud server
4. Cloud server → Validates and stores sale
5. Pull phase → Edge gets product updates from cloud
6. Repeat every 30 seconds

**No action needed!** Just ensure:
- `AUTO_SYNC=true` in `.env`
- Scheduler is running: `php artisan schedule:work`

### Manual Sync

You can manually trigger sync:

```bash
# Sync everything (push + pull)
php artisan server:sync

# Only push local changes to cloud
php artisan server:sync --push

# Only pull cloud changes to local
php artisan server:sync --pull

# Force sync even if recently synced
php artisan server:sync --force
```

**Example Output:**
```
Starting server synchronization...

⏳ Checking cloud server status...
✅ Cloud server is online

⏳ Pushing local changes to cloud...
✅ Push completed: Changes pushed successfully

⏳ Pulling changes from cloud...
✅ Pull completed: Applied 15 changes from cloud

🎉 Synchronization complete!
```

### API Endpoints

#### Check Sync Status

```bash
curl http://192.168.1.100:8000/api/server-sync/status \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**
```json
{
  "server_id": "edge-lagos-001",
  "mode": "edge",
  "cloud_status": "online",
  "last_push": "2026-02-10T15:30:00Z",
  "last_pull": "2026-02-10T15:30:00Z",
  "pending_changes": 5,
  "recent_sessions": [...]
}
```

#### Health Check

```bash
curl http://192.168.1.100:8000/api/server-sync/health
```

**Response:**
```json
{
  "status": "healthy",
  "server_id": "edge-lagos-001",
  "mode": "edge",
  "timestamp": "2026-02-10T15:30:00Z"
}
```

---

## Data Flow Examples

### Example 1: Creating a Sale

**Scenario:** Cashier creates sale at Branch Lagos while internet is down

```
1. POS Client → POST /sales (to local server)
   ├─ Local server: Validates sale
   ├─ Local server: Saves to local DB
   │  └─ origin_server_id = "edge-lagos-001"
   └─ Response: Sale created (ID: 123)

2. 30 seconds later → Scheduler runs server:sync
   ├─ Check internet → Still down
   └─ Skip sync (will retry in 30s)

3. Internet restored
   ├─ Next sync cycle runs
   ├─ Push phase:
   │  ├─ Collect new sales (ID: 123)
   │  └─ POST to cloud: /server-sync/receive
   ├─ Cloud server:
   │  ├─ Validates sale
   │  ├─ Saves to cloud DB
   │  └─ Returns: Accepted
   └─ Edge server: Marks sale as synced

4. Result:
   ├─ Local DB: Sale #123 (synced ✅)
   └─ Cloud DB: Sale #123 (origin: edge-lagos-001)
```

### Example 2: Product Price Update

**Scenario:** Admin updates product price in cloud, needs to sync to all branches

```
1. Admin → Updates product price in cloud
   └─ Cloud DB: Product #456 price = 100.00 (version 2)

2. Branch server sync cycle (every 30s)
   ├─ Pull phase:
   │  ├─ POST /server-sync/provide-changes
   │  ├─ last_sync_at: 2026-02-10T15:00:00Z
   │  └─ Cloud returns: Product #456 updated
   ├─ Edge server:
   │  ├─ Checks version (local: 1, cloud: 2)
   │  ├─ Cloud wins (higher version)
   │  └─ Updates local DB: price = 100.00
   └─ Result: Product synced

3. All POS devices now see new price
   └─ GET /products/456 → price: 100.00
```

### Example 3: Conflict Resolution

**Scenario:** Same customer edited on cloud and branch server

```
1. Both locations edit customer (email):
   ├─ Cloud: email = "new@example.com" (version 3)
   └─ Edge: email = "updated@example.com" (version 3)

2. Edge pushes first:
   ├─ Cloud receives: version 3
   ├─ Cloud has: version 3
   └─ Conflict detected!

3. Resolution (config: latest_wins):
   ├─ Compare timestamps
   ├─ Cloud updated: 15:30:00
   ├─ Edge updated: 15:30:05
   ├─ Edge is newer → Edge wins
   └─ Cloud accepts edge version

4. Next pull cycle:
   └─ All servers have: "updated@example.com"
```

---

## Monitoring & Troubleshooting

### Check Sync Sessions

```bash
php artisan tinker

>>> ServerSyncSession::recent(10)->get()
```

**Example:**
```
id  | server_id       | direction | status  | records_sent | created_at
----|-----------------|-----------|---------|--------------|-------------------
150 | edge-lagos-001  | push      | success | 12           | 2026-02-10 15:30
149 | edge-lagos-001  | pull      | success | 0            | 2026-02-10 15:30
148 | edge-lagos-001  | push      | failed  | 5            | 2026-02-10 15:00
```

### View Failed Syncs

```bash
>>> ServerSyncSession::failed()->recent(5)->get()
```

### Check Pending Changes

```bash
# On edge server
curl http://localhost:8000/api/server-sync/status | jq '.pending_changes'
# Output: 5
```

### Common Issues

**Issue 1: Cloud Server Unreachable**

```bash
# Check connectivity
ping pos-cloud.example.com

# Check firewall
curl -v https://pos-cloud.example.com/api/server-sync/health

# Check token
curl https://pos-cloud.example.com/api/server-sync/health \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Issue 2: Sync Not Running**

```bash
# Check scheduler
ps aux | grep schedule:work

# Check if enabled
php artisan tinker
>>> config('sync.auto_sync')
>>> config('sync.mode')

# Manually run
php artisan server:sync
```

**Issue 3: Records Not Syncing**

```bash
# Check if records have origin_server_id
php artisan tinker
>>> Sale::whereNull('origin_server_id')->count()

# Update existing records
>>> Sale::whereNull('origin_server_id')
     ->update(['origin_server_id' => config('sync.local_server_id')])
```

---

## Production Deployment

### Using Supervisor (Recommended)

Create `/etc/supervisor/conf.d/pos-edge-server.conf`:

```ini
[program:pos-laravel]
process_name=%(program_name)s
command=php /var/www/PosBackend/artisan serve --host=0.0.0.0 --port=8000
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/PosBackend/storage/logs/supervisor.log

[program:pos-scheduler]
process_name=%(program_name)s
command=php /var/www/PosBackend/artisan schedule:work
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/PosBackend/storage/logs/scheduler.log
```

Start services:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start pos-laravel
sudo supervisorctl start pos-scheduler
```

### Using Nginx (Better Performance)

```nginx
server {
    listen 80;
    server_name 192.168.1.100;
    root /var/www/PosBackend/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

---

## Configuration Options

### Sync Modes

| Mode | Description | Use Case |
|------|-------------|----------|
| `cloud` | Master server | Central cloud deployment |
| `edge` | Branch server | Each physical branch |
| `standalone` | No sync | Single-server deployment |

### Conflict Resolution

| Strategy | Behavior | Best For |
|----------|----------|----------|
| `cloud_wins` | Cloud always wins | Centralized control |
| `edge_wins` | Branch always wins | Branch autonomy |
| `latest_wins` | Newest update wins | Balanced approach |
| `manual` | Requires resolution | Critical data |

### Environment Variables

```bash
# Server Role
SYNC_MODE=edge                    # cloud, edge, or standalone

# Server Identity
LOCAL_SERVER_ID=edge-lagos-001    # Unique per server

# Cloud Connection
CLOUD_SERVER_URL=https://cloud.example.com/api
CLOUD_SERVER_TOKEN=your-token-here

# Sync Behavior
AUTO_SYNC=true                    # Auto-sync on schedule
SYNC_INTERVAL=30                  # Seconds between syncs
SYNC_CONFLICT_RESOLUTION=latest_wins
SYNC_TIMEOUT=30                   # Request timeout
SYNC_MAX_RETRIES=3                # Retry failed syncs
```

---

## Testing

### Test Cloud Server

```bash
# On cloud server
php artisan migrate:fresh --seed
php artisan serve

# In another terminal
curl http://localhost:8000/api/server-sync/health
```

### Test Edge Server

```bash
# On edge server
# 1. Set environment
SYNC_MODE=edge
CLOUD_SERVER_URL=http://cloud-server:8000/api
CLOUD_SERVER_TOKEN=your-token

# 2. Run sync
php artisan server:sync

# Expected output:
# ✅ Cloud server is online
# ✅ Push completed
# ✅ Pull completed
```

### Test Full Flow

```bash
# 1. Create sale on edge
curl http://edge-server:8000/api/sales \
  -H "Authorization: Bearer $TOKEN" \
  -X POST \
  -d '{"branch_id":1,"total":100}'

# 2. Trigger sync
php artisan server:sync --push

# 3. Check cloud has sale
curl http://cloud-server:8000/api/sales \
  -H "Authorization: Bearer $TOKEN"
```

---

## Comparison: Client Sync vs Server Sync

| Feature | Client-Side Sync | Server-to-Server Sync |
|---------|------------------|------------------------|
| **Client Complexity** | High (IndexedDB, sync logic) | Low (just web browser) |
| **Offline Capability** | Per device | Entire branch |
| **Infrastructure** | No local server | Local server required |
| **Multiple Devices** | Each syncs separately | Share local server |
| **Performance** | Depends on device | Fast (LAN speed) |
| **Deployment** | Deploy to all devices | Deploy to branch server |
| **Cost** | Low (no server) | Medium (server hardware) |
| **Best For** | Single POS devices | Multi-device branches |

---

## Next Steps

1. ✅ Deploy cloud server
2. ✅ Configure edge servers for each branch
3. ✅ Run migrations on all servers
4. ✅ Configure environment variables
5. ✅ Start scheduler on edge servers
6. ✅ Test sync between servers
7. ✅ Connect POS clients to branch servers
8. ✅ Monitor sync sessions
9. ✅ Set up alerts for failed syncs

---

**For support, check the ServerSyncController.php implementation or review server_sync_sessions table.**
