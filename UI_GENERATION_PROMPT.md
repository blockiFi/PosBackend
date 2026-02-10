# Comprehensive UI Generation Prompt for POS Management System

## Project Overview

Create a **complete, production-ready, offline-first Point of Sale (POS) Management System** frontend application with comprehensive business management capabilities, multi-tenancy support, role-based access control, and advanced inventory management features.

---

## Application Architecture

### Technology Stack Requirements

**Frontend Framework:** React 18+ with TypeScript OR Vue 3 with TypeScript  
**State Management:** Redux Toolkit/Zustand (React) OR Pinia (Vue)  
**Routing:** React Router v6+ OR Vue Router 4+  
**UI Component Library:** Material-UI (MUI) OR Ant Design OR Tailwind CSS + Headless UI  
**Data Fetching:** Axios with interceptors for auth + React Query/TanStack Query OR Vue Query  
**Offline Support:** IndexedDB (via Dexie.js or idb) + Service Workers  
**Charts/Analytics:** Recharts OR Chart.js OR Apache ECharts  
**Forms:** React Hook Form + Zod OR Vue Formulate + Yup  
**Date Handling:** date-fns OR day.js  
**Icons:** Material Icons OR Lucide React OR Heroicons  
**Notifications:** React Hot Toast OR Vue Toastification  
**PDF Generation:** jsPDF + html2canvas (for receipts and reports)  
**Barcode Scanning:** html5-qrcode OR quagga2 (for product scanning)  
**Printing:** Browser print API with custom receipt templates

---

## Core Features & Modules

### 1. Authentication & Authorization Module

#### Features:
- **Login Screen**
  - Email/password authentication
  - PIN-based quick login (4-6 digit PIN for cashiers)
  - Remember me functionality
  - Forgot password flow
  - Error handling with clear messages
  
- **Registration Screen**
  - Multi-step registration wizard
  - Email verification
  - Password strength indicator
  - Terms & conditions acceptance
  
- **PIN Management**
  - Set PIN (after login with password)
  - Remove PIN
  - PIN-only login for cashiers
  - PIN validation (numeric only, minimum 4 digits)

- **Session Management**
  - Auto-refresh tokens before expiry
  - Logout functionality
  - Session timeout warnings
  - Multiple device session handling

#### UI Components:
```
/auth
  /login - Email/password login form
  /pin-login - Numeric PIN pad interface
  /register - Multi-step registration wizard
  /forgot-password - Password reset request
  /reset-password - Password reset form
  /set-pin - PIN creation interface
```

---

### 2. Business & Branch Management Module

#### Features:
- **Business Dashboard**
  - Business overview cards (total revenue, branches, products, customers)
  - Quick stats (today's sales, active shifts, low stock alerts)
  - Recent activities timeline
  - Business switching (if user belongs to multiple businesses)

- **Business CRUD**
  - Create new business (name, registration number, address, contact)
  - View business details
  - Update business information
  - Delete/deactivate business
  - Upload business logo

- **Branch Management**
  - List all branches (with search, filter, pagination)
  - Create new branch (name, address, contact, manager)
  - Branch details view (stats, assigned users, products, inventory)
  - Update branch information
  - Delete/deactivate branch
  - Branch-specific inventory view
  - Branch transfer history

#### UI Components:
```
/dashboard
  /overview - Main business dashboard
  /business
    /list - All businesses for user
    /[id] - Business details & settings
    /create - New business form
    /edit/[id] - Edit business form
  /branches
    /list - All branches table/grid
    /[id] - Branch details & stats
    /create - New branch form
    /edit/[id] - Edit branch form
```

---

### 3. User Management & Roles Module

#### Features:
- **User Management**
  - List all business users (table with search, filter, role badges)
  - Add new user to business (invite via email)
  - View user details (profile, assigned roles, permissions, activity log)
  - Update user information (name, email, branch assignment)
  - Deactivate/remove user from business
  - User status indicators (active, inactive, blocked)

- **Role & Permission Management**
  - Predefined roles (Admin, Manager, Cashier, Inventory Manager, Accountant)
  - Custom role creation
  - Permission matrix view (checkboxes for all permissions)
  - Assign/remove roles to users
  - Permission categories:
    - Sales (create, view, void, refund)
    - Inventory (manage stock, transfers, write-offs)
    - Products (create, edit, delete, pricing)
    - Users (manage, assign roles)
    - Reports (view analytics, export data)
    - Settings (business config, branches, payment methods)

#### UI Components:
```
/users
  /list - User management table
  /[id] - User profile & details
  /invite - Invite new user form
  /edit/[id] - Edit user form
/roles
  /list - All roles table
  /[id] - Role details & permissions
  /create - New role form
  /edit/[id] - Edit role permissions
```

---

### 4. Product & Category Management Module

#### Features:
- **Product Categories**
  - Hierarchical tree view (parent-child categories)
  - Category breadcrumb navigation
  - Create category (name, parent category, description)
  - Edit/delete category
  - Category-based product filtering
  - Drag-and-drop category reordering

- **Product Management**
  - Product list (grid/table view toggle)
  - Advanced filters (category, price range, stock status, barcode search)
  - Product quick search (by name, SKU, barcode)
  - Product card/row (image, name, SKU, price, stock levels)
  - Create product form:
    - Basic info (name, SKU, barcode, category)
    - Pricing (cost price, selling price, tax rate)
    - Inventory (track inventory, unit of measure)
    - Images (multiple upload with preview)
    - Description & metadata
  - Bulk product import (CSV/Excel)
  - Bulk price updates
  - Product activation/deactivation
  - Product variants (size, color, etc.)
  - Barcode generation & printing

- **Branch Product Assignment**
  - Assign products to specific branches
  - Branch-specific pricing
  - Transfer products between branches
  - View product availability by branch

#### UI Components:
```
/products
  /categories
    /tree - Hierarchical category tree
    /create - New category form
    /edit/[id] - Edit category form
  /list - Product grid/table view
  /[id] - Product details page
  /create - New product form
  /edit/[id] - Edit product form
  /import - Bulk import interface
  /bulk-update - Bulk operations
```

---

### 5. Inventory Management Module

#### Features:
- **Stock Overview**
  - Real-time stock levels (shelf + store)
  - Low stock alerts (configurable thresholds)
  - Out of stock items
  - Overstocked items
  - Stock value summary

- **Inventory Transactions**
  - Transaction history (all stock movements)
  - Transaction types (stock in, stock out, adjustment, transfer, write-off)
  - Filter by date range, type, product, branch
  - Transaction details view
  - Add manual adjustment (reason required)

- **Batch Management (FEFO System)**
  - Batch tracking by product
  - Batch details (batch number, expiry date, quantity, cost price)
  - Near-expiry alerts (configurable days threshold)
  - Expired batches list
  - Batch movement history
  - Automatic FEFO allocation in sales

- **Shelf vs Store Inventory**
  - Separate tracking for shelf (display) and store (warehouse)
  - Move stock from store to shelf
  - Move stock from shelf to store
  - Visual indicators for shelf capacity

- **Stock Transfer Requests (Workflow)**
  - Create transfer request (source branch → destination branch)
  - Transfer items list (product, quantity, batch)
  - Approval workflow (pending → approved → in-transit → completed)
  - Manager approval required
  - Receiving confirmation at destination
  - Transfer tracking & history

- **Stock Write-offs**
  - Create write-off (damaged, expired, lost)
  - Reason selection/input required
  - Manager approval workflow
  - Write-off history & reports
  - Impact on inventory value

#### UI Components:
```
/inventory
  /overview - Stock dashboard
  /transactions
    /list - Transaction history table
    /[id] - Transaction details
    /adjust - Manual adjustment form
  /batches
    /list - All batches table
    /[id] - Batch details & history
    /near-expiry - Expiring soon alerts
    /expired - Expired batches list
  /transfers
    /list - Transfer requests table
    /create - New transfer form
    /[id] - Transfer details & tracking
  /writeoffs
    /list - Write-off history
    /create - New write-off form
    /[id] - Write-off details
```

---

### 6. Sales & POS Module

#### Features:
- **POS Interface (Cashier View)**
  - Product search (barcode scan, SKU, name search)
  - Product grid/list (filterable by category)
  - Shopping cart (line items with quantity, price, discounts)
  - Item quantity adjustment (increment/decrement buttons)
  - Remove item from cart
  - Line item discount (percentage or fixed amount)
  - Cart-level discount
  - Tax calculation (automatic based on product tax rate)
  - Customer selection (optional, search existing or create new)
  - Multiple payment methods (cash, card, mobile money)
  - Split payments (partial cash, partial card)
  - Change calculation (for cash payments)
  - Print receipt (thermal printer + A4 format)
  - Email receipt option
  - Park sale (save for later)
  - Retrieve parked sale
  - Void sale (with reason & manager approval)

- **Sales History**
  - Sales list (with filters: date range, cashier, customer, status)
  - Sale details view (items, payments, customer, cashier, timestamps)
  - Sale receipt reprint
  - Sale search (by sale number, customer, amount)
  - Export sales data (CSV, Excel, PDF)

- **Sales Shifts**
  - Start shift (cashier name, opening float amount)
  - Current shift indicator
  - In-shift sales tracking (real-time count & total)
  - Close shift (closing float, expected vs actual cash)
  - Cash discrepancy resolution (over/short reasons)
  - Shift summary report (sales count, total, by payment method)
  - Shift history (all past shifts with performance metrics)

- **Customer Management in Sales**
  - Quick customer lookup during checkout
  - Add new customer on-the-fly
  - Customer purchase history
  - Customer loyalty points (optional)

#### UI Components:
```
/pos
  /cashier - Main POS interface
  /parked-sales - Parked sales list
/sales
  /list - Sales history table
  /[id] - Sale details & receipt
/shifts
  /start - Start shift form
  /current - Active shift dashboard
  /close - Close shift form
  /history - Past shifts table
  /[id] - Shift details & report
```

---

### 7. Customer Management Module

#### Features:
- Customer list (search, filter, pagination)
- Customer card (name, contact, email, total purchases, last purchase)
- Add new customer (name, phone, email, address)
- Edit customer details
- Delete/deactivate customer
- Customer purchase history (all sales)
- Customer analytics (total spent, average order value, visit frequency)
- Customer groups/tiers (VIP, regular, new)
- Export customer list

#### UI Components:
```
/customers
  /list - Customer table/grid
  /[id] - Customer profile & history
  /create - New customer form
  /edit/[id] - Edit customer form
```

---

### 8. Payment Methods Module

#### Features:
- Payment method list (cash, card, mobile money, bank transfer)
- Add custom payment method
- Enable/disable payment methods
- Payment method configuration (account details, API keys for integrations)
- Payment method transaction history
- Default payment method setting

#### UI Components:
```
/payment-methods
  /list - Payment methods table
  /create - New payment method form
  /edit/[id] - Edit payment method
```

---

### 9. Refund & Quick Sale Workflows

#### Features:
- **Refund Requests**
  - Create refund request (select sale, items to refund, reason)
  - Refund workflow (pending → approved → processed OR rejected)
  - Manager approval required
  - Refund amount calculation (original price or custom)
  - Refund method (original payment method or cash)
  - Refund history & tracking
  - Inventory restoration after refund

- **Quick Sales (Near-Expiry Discounts)**
  - Create quick sale for near-expiry batches
  - Set discount percentage
  - Approval workflow (pending → approved → active → ended OR rejected)
  - Manager approval required
  - Active quick sales displayed in POS
  - Automatic discount application during checkout
  - Quick sale performance tracking
  - End quick sale (manual or auto-expire)

#### UI Components:
```
/refunds
  /list - Refund requests table
  /create - New refund form
  /[id] - Refund details & approval
/quick-sales
  /list - Quick sales table
  /create - New quick sale form
  /[id] - Quick sale details & approval
  /active - Currently active quick sales
```

---

### 10. Analytics & Reporting Module

#### Features:
- **Organization-Level Analytics**
  - Total revenue (lifetime, yearly, monthly, daily)
  - Revenue trends (line/bar charts with date range selector)
  - Top-selling products (by quantity & revenue)
  - Sales by category
  - Sales by branch comparison
  - Customer acquisition trends
  - Average order value trends

- **Branch Analytics**
  - Branch performance comparison (revenue, sales count)
  - Branch-specific sales trends
  - Branch inventory turnover
  - Branch profitability

- **Product Analytics**
  - Best-selling products (by revenue & quantity)
  - Worst-selling products (slow movers)
  - Product profitability (margin analysis)
  - Product stock turnover rate
  - Category performance

- **Profit & Loss Report**
  - Revenue breakdown (by product, category, branch)
  - Cost of goods sold (COGS)
  - Gross profit & margin
  - Operating expenses (optional)
  - Net profit calculation
  - Date range filtering (daily, weekly, monthly, yearly, custom)

- **Growth Trends**
  - Revenue growth rate (month-over-month, year-over-year)
  - Customer growth
  - Sales volume growth
  - Product catalog growth

- **Export Capabilities**
  - Export all reports to PDF
  - Export data to Excel/CSV
  - Schedule automated reports (email delivery)
  - Print reports

#### UI Components:
```
/analytics
  /overview - Analytics dashboard
  /organization - Business-wide analytics
  /branches - Branch comparison & analytics
  /products - Product performance
  /profit-loss - P&L report
  /growth - Growth trends & forecasting
  /reports - Report generation & exports
```

---

### 11. Offline Synchronization Module

#### Features:
- **Device Registration**
  - Register POS device (device name, type, OS, branch assignment)
  - Device status indicator (online, offline, syncing)
  - View registered devices
  - Deactivate device

- **Sync Dashboard**
  - Sync status indicator (last sync time, next scheduled sync)
  - Pending changes count (sales, products, customers)
  - Sync history (successful, failed syncs)
  - Manual sync trigger button
  - Auto-sync configuration (interval, conditions)

- **Offline Mode**
  - Visual offline indicator (banner, badge)
  - Queue offline actions (sales, customer creation, inventory updates)
  - Local data storage (IndexedDB)
  - Offline data persistence
  - Data validation before sync

- **Sync Operations**
  - Bootstrap (initial full data download)
  - Pull (download server changes since last sync)
  - Push (upload local changes to server)
  - Conflict resolution UI (when server data conflicts with local)
  - Sync session details (records pushed, pulled, conflicts)

- **Conflict Resolution**
  - Conflict notification center
  - Side-by-side comparison (server version vs local version)
  - Resolve options (keep server, keep local, merge manually)
  - Bulk conflict resolution

#### UI Components:
```
/sync
  /dashboard - Sync status & controls
  /devices
    /list - Registered devices
    /register - Device registration form
  /history - Sync session history
  /conflicts - Conflict resolution interface
  /settings - Sync configuration
```

---

### 12. Settings & Configuration Module

#### Features:
- **Business Settings**
  - Business profile (name, logo, address, contact)
  - Tax configuration (default tax rate, tax exemptions)
  - Currency settings
  - Receipt templates (header, footer, logo)
  - Business hours

- **Branch Settings**
  - Branch profile per branch
  - Branch managers assignment
  - Branch-specific configurations

- **Inventory Settings**
  - Low stock threshold (global & per product)
  - Near-expiry alert days (default 30 days)
  - FEFO enforcement settings
  - Stock movement approval requirements

- **POS Settings**
  - Receipt printer configuration
  - Barcode scanner settings
  - Default payment method
  - Auto-print receipts toggle
  - Cash drawer integration

- **Sync Settings**
  - Auto-sync interval (minutes)
  - Sync only on WiFi toggle
  - Sync conflict resolution strategy (auto or manual)
  - Offline data retention period

- **User Preferences**
  - Language selection
  - Timezone
  - Date format (DD/MM/YYYY or MM/DD/YYYY)
  - Currency format
  - Theme (light/dark mode)
  - Notifications preferences

#### UI Components:
```
/settings
  /business - Business configuration
  /branches - Branch settings
  /inventory - Inventory defaults
  /pos - POS configuration
  /sync - Sync settings
  /preferences - User preferences
```

---

## API Integration Requirements

### Base Configuration
```typescript
// API Base URL
const API_BASE_URL = process.env.REACT_APP_API_BASE_URL || 'http://localhost:8000/api';

// Authentication headers
headers: {
  'Authorization': `Bearer ${token}`,
  'X-Business-Id': businessId,
  'X-Device-Id': deviceId, // For sync operations
  'Content-Type': 'application/json'
}
```

### Authentication Endpoints
- `POST /register` - User registration
- `POST /login` - Email/password login
- `POST /pin-login` - PIN-based login
- `POST /pin/set` - Set user PIN
- `POST /pin/remove` - Remove user PIN

### Business & Branch Endpoints
- `GET /businesses` - List user businesses
- `POST /businesses` - Create business
- `GET /businesses/{id}` - Get business details
- `PUT /businesses/{id}` - Update business
- `DELETE /businesses/{id}` - Delete business
- `GET /branches` - List branches
- `POST /branches` - Create branch
- `GET /branches/{id}` - Get branch details
- `PUT /branches/{id}` - Update branch
- `DELETE /branches/{id}` - Delete branch

### User Management Endpoints
- `GET /business-users` - List business users
- `POST /business-users` - Add user to business
- `GET /business-users/{userId}` - Get user details
- `PUT /business-users/{userId}` - Update user
- `DELETE /business-users/{userId}` - Remove user from business

### Role & Permission Endpoints
- `GET /permissions` - List all permissions
- `GET /roles` - List all roles
- `POST /roles` - Create role
- `GET /roles/{id}` - Get role details
- `PUT /roles/{id}` - Update role
- `DELETE /roles/{id}` - Delete role
- `POST /roles/addpermission` - Add permission to role
- `POST /roles/removepermission` - Remove permission from role
- `POST /roles/assign` - Assign role to user
- `POST /roles/remove` - Remove role from user
- `GET /users/{userId}/roles` - Get user roles

### Product & Category Endpoints
- `GET /categories` - List categories
- `POST /categories` - Create category
- `GET /categories/{id}` - Get category details
- `PUT /categories/{id}` - Update category
- `DELETE /categories/{id}` - Delete category
- `GET /categories/{id}/breadcrumb` - Get category hierarchy
- `GET /products` - List products (with filters)
- `POST /products` - Create product
- `GET /products/{id}` - Get product details
- `PUT /products/{id}` - Update product
- `DELETE /products/{id}` - Delete product
- `POST /products/{id}/branches` - Add product to branch
- `DELETE /products/{id}/branches` - Remove product from branch
- `PATCH /products/{id}/price` - Update product price
- `GET /branches/{branchId}/products` - Get branch products

### Branch Product Endpoints
- `GET /branch-products` - List branch products
- `GET /branch-products/by-category` - Get by category
- `POST /branch-products` - Create branch product
- `GET /branch-products/{id}` - Get details
- `PUT /branch-products/{id}` - Update
- `DELETE /branch-products/{id}` - Delete
- `POST /branch-products/{id}/stock` - Update stock
- `POST /branch-products/{id}/move-to-shelf` - Move to shelf
- `POST /branch-products/{id}/move-to-store` - Move to store
- `GET /branch-products/summary/stock` - Stock summary
- `POST /branch-products/bulk-update` - Bulk updates

### Inventory Endpoints
- `GET /inventory/transactions` - List transactions
- `POST /inventory/transactions` - Create transaction
- `GET /inventory/transactions/{id}` - Get transaction details
- `GET /inventory/stock-summary` - Get stock summary

### Batch Management Endpoints
- `GET /batches` - List batches
- `GET /batches/near-expiry` - Near-expiry batches
- `GET /batches/expired` - Expired batches
- `GET /batches/{id}` - Get batch details
- `PATCH /batches/{id}` - Update batch
- `GET /products/{id}/batches` - Get product batches

### Customer Endpoints
- `GET /customers` - List customers
- `POST /customers` - Create customer
- `GET /customers/{id}` - Get customer details
- `PUT /customers/{id}` - Update customer
- `DELETE /customers/{id}` - Delete customer

### Payment Method Endpoints
- `GET /payment-methods` - List payment methods
- `POST /payment-methods` - Create payment method
- `GET /payment-methods/{id}` - Get details
- `PUT /payment-methods/{id}` - Update
- `DELETE /payment-methods/{id}` - Delete

### Sales Endpoints
- `GET /sales` - List sales
- `POST /sales` - Create sale
- `GET /sales/{id}` - Get sale details
- `POST /sales/{id}/payments` - Add payment to sale
- `POST /sales/{id}/cancel` - Cancel sale

### Sales Shift Endpoints
- `GET /shifts` - List shifts
- `POST /shifts` - Start shift
- `GET /shifts/current` - Get current shift
- `GET /shifts/{id}` - Get shift details
- `GET /shifts/{id}/sales` - Get shift sales
- `POST /shifts/{id}/close` - Close shift
- `POST /shifts/{id}/resolve-discrepancy` - Resolve cash discrepancy

### Refund Request Endpoints
- `GET /refund-requests` - List refund requests
- `POST /refund-requests` - Create refund request
- `GET /refund-requests/{id}` - Get details
- `POST /refund-requests/{id}/approve` - Approve refund
- `POST /refund-requests/{id}/reject` - Reject refund

### Quick Sale Endpoints
- `GET /quick-sales` - List quick sales
- `POST /quick-sales` - Create quick sale
- `GET /quick-sales/{id}` - Get details
- `POST /quick-sales/{id}/approve` - Approve
- `POST /quick-sales/{id}/reject` - Reject
- `POST /quick-sales/{id}/end` - End quick sale

### Stock Transfer Endpoints
- `GET /stock-transfer-requests` - List transfers
- `POST /stock-transfer-requests` - Create transfer
- `GET /stock-transfer-requests/{id}` - Get details
- `POST /stock-transfer-requests/{id}/approve` - Approve
- `POST /stock-transfer-requests/{id}/reject` - Reject
- `POST /stock-transfer-requests/{id}/confirm` - Confirm receipt
- `POST /stock-transfer-requests/{id}/cancel` - Cancel

### Stock Write-off Endpoints
- `GET /stock-writeoffs` - List write-offs
- `POST /stock-writeoffs` - Create write-off
- `GET /stock-writeoffs/{id}` - Get details

### Analytics Endpoints
- `GET /analytics/organization` - Organization analytics
- `GET /analytics/branches` - Branch analytics
- `GET /analytics/products` - Product analytics
- `GET /analytics/profit-loss` - Profit & loss report
- `GET /analytics/growth-trends` - Growth trends

### Sync Endpoints
- `POST /sync/register-device` - Register device
- `POST /sync/bootstrap` - Initial data download
- `POST /sync/pull` - Pull server changes
- `POST /sync/push` - Push local changes
- `POST /sync/resolve-conflicts` - Resolve conflicts
- `GET /sync/status` - Get sync status
- `POST /sync/heartbeat` - Device heartbeat

---

## Offline-First Implementation Guide

### IndexedDB Schema
```typescript
// Database structure
const dbSchema = {
  stores: {
    sales: 'client_uuid, business_id, branch_id, sale_date',
    saleItems: 'client_uuid, sale_id, product_id',
    payments: 'client_uuid, sale_id',
    customers: 'client_uuid, business_id',
    products: 'id, business_id, category_id',
    branchProducts: 'id, branch_id, product_id',
    batches: 'id, product_id, expiry_date',
    categories: 'id, business_id',
    inventoryTransactions: 'client_uuid, branch_id, product_id',
    syncQueue: '++id, entity_type, action, timestamp',
    conflicts: '++id, entity_type, client_uuid'
  }
};
```

### Sync Queue Pattern
```typescript
// Queue actions for sync
interface SyncQueueItem {
  id?: number;
  entity_type: 'sales' | 'customers' | 'products';
  action: 'create' | 'update' | 'delete';
  entity_data: any;
  client_uuid: string;
  timestamp: string;
  retry_count: number;
  status: 'pending' | 'syncing' | 'synced' | 'failed';
}

// Add to queue when offline
async function queueForSync(item: SyncQueueItem) {
  await db.syncQueue.add(item);
}

// Process queue when online
async function processSyncQueue() {
  const pending = await db.syncQueue
    .where('status').equals('pending')
    .toArray();
    
  for (const item of pending) {
    await syncToServer(item);
  }
}
```

### Network Detection
```typescript
// Online/offline detection
window.addEventListener('online', handleOnline);
window.addEventListener('offline', handleOffline);

function handleOnline() {
  // Update UI indicator
  // Trigger sync
  processSyncQueue();
}

function handleOffline() {
  // Update UI indicator
  // Switch to offline mode
}
```

---

## UI/UX Design Guidelines

### Design Principles
1. **Clean & Minimal** - Focus on functionality, reduce clutter
2. **Fast & Responsive** - Optimize for quick operations (cashier speed is critical)
3. **Mobile-First** - Responsive design for tablets and mobile devices
4. **Accessibility** - WCAG 2.1 AA compliance, keyboard navigation
5. **Dark Mode** - Support light and dark themes
6. **Offline-First** - Clear offline indicators, graceful degradation

### Color Scheme
```css
/* Primary Colors */
--primary-500: #3B82F6; /* Blue */
--primary-600: #2563EB;
--primary-700: #1D4ED8;

/* Secondary Colors */
--secondary-500: #10B981; /* Green for success */
--warning-500: #F59E0B; /* Amber for warnings */
--danger-500: #EF4444; /* Red for errors */

/* Neutral Colors */
--gray-50: #F9FAFB;
--gray-100: #F3F4F6;
--gray-200: #E5E7EB;
--gray-700: #374151;
--gray-900: #111827;
```

### Typography
```css
/* Font Family */
--font-primary: 'Inter', 'Segoe UI', system-ui, sans-serif;
--font-mono: 'Fira Code', 'Courier New', monospace;

/* Font Sizes */
--text-xs: 0.75rem;    /* 12px */
--text-sm: 0.875rem;   /* 14px */
--text-base: 1rem;     /* 16px */
--text-lg: 1.125rem;   /* 18px */
--text-xl: 1.25rem;    /* 20px */
--text-2xl: 1.5rem;    /* 24px */
--text-3xl: 1.875rem;  /* 30px */
--text-4xl: 2.25rem;   /* 36px */
```

### Component Patterns

#### Cards
```jsx
<Card>
  <CardHeader>
    <CardTitle>Title</CardTitle>
    <CardActions>
      <Button>Action</Button>
    </CardActions>
  </CardHeader>
  <CardContent>
    {/* Content */}
  </CardContent>
</Card>
```

#### Tables
```jsx
<DataTable
  columns={columns}
  data={data}
  filters={filters}
  sorting={sorting}
  pagination={pagination}
  onRowClick={handleRowClick}
  actions={rowActions}
/>
```

#### Forms
```jsx
<Form onSubmit={handleSubmit}>
  <FormField
    name="email"
    label="Email"
    type="email"
    required
    validation={emailValidation}
  />
  <FormActions>
    <Button variant="secondary" onClick={onCancel}>Cancel</Button>
    <Button type="submit">Submit</Button>
  </FormActions>
</Form>
```

#### Modals/Dialogs
```jsx
<Modal open={isOpen} onClose={onClose} size="md">
  <ModalHeader>
    <ModalTitle>Confirm Action</ModalTitle>
  </ModalHeader>
  <ModalContent>
    {/* Content */}
  </ModalContent>
  <ModalFooter>
    <Button variant="secondary" onClick={onClose}>Cancel</Button>
    <Button variant="primary" onClick={onConfirm}>Confirm</Button>
  </ModalFooter>
</Modal>
```

---

## Responsive Design Breakpoints

```css
/* Mobile */
@media (max-width: 640px) { /* sm */ }

/* Tablet */
@media (min-width: 641px) and (max-width: 1024px) { /* md, lg */ }

/* Desktop */
@media (min-width: 1025px) { /* xl, 2xl */ }
```

### Layout Adaptations
- **Mobile**: Single column, hamburger menu, bottom navigation
- **Tablet**: Sidebar toggleable, 2-column grids
- **Desktop**: Persistent sidebar, 3+ column grids, expanded tables

---

## Performance Requirements

### Loading States
- Skeleton loaders for lists and cards
- Spinner for form submissions
- Progress bars for uploads and sync operations
- Optimistic UI updates (show change immediately, revert on error)

### Caching Strategy
- Cache product catalog (1 hour)
- Cache category tree (1 hour)
- Cache user permissions (session)
- Invalidate cache on updates
- Background refresh for stale data

### Code Splitting
```typescript
// Lazy load routes
const Analytics = lazy(() => import('./pages/Analytics'));
const Inventory = lazy(() => import('./pages/Inventory'));
const Reports = lazy(() => import('./pages/Reports'));
```

### Bundle Size Optimization
- Tree shaking for unused code
- Dynamic imports for heavy libraries
- Image optimization (WebP format, lazy loading)
- Gzip compression
- Service worker for asset caching

---

## Security Requirements

### Authentication
- Store JWT token in httpOnly cookie OR secure localStorage
- Auto-refresh token before expiry
- Clear token on logout
- Redirect to login on 401 Unauthorized

### Input Validation
- Client-side validation (before API call)
- Sanitize HTML inputs
- Escape special characters
- Validate file uploads (type, size)

### XSS Protection
- Use framework's built-in XSS protection
- Never use `dangerouslySetInnerHTML` without sanitization
- Content Security Policy headers

### Data Privacy
- Mask sensitive data (credit card numbers)
- Encrypt offline data (IndexedDB encryption)
- Clear offline data on logout
- Session timeout (configurable, default 30 minutes)

---

## Testing Requirements

### Unit Tests
- Test all utility functions
- Test form validation logic
- Test state management actions/reducers
- Test API service functions
- Coverage target: 80%+

### Integration Tests
- Test API integration (mock responses)
- Test auth flow (login, logout, token refresh)
- Test offline sync queue
- Test conflict resolution logic

### E2E Tests (Cypress/Playwright)
- Complete sale flow (add items, payment, receipt)
- User login and PIN login
- Product creation and assignment
- Shift start and close
- Refund request workflow
- Offline mode and sync

---

## Accessibility Requirements

### WCAG 2.1 AA Compliance
- Semantic HTML elements
- ARIA labels for interactive elements
- Keyboard navigation (Tab, Enter, Esc, Arrow keys)
- Focus indicators (visible focus states)
- Color contrast ratio 4.5:1 (normal text), 3:1 (large text)
- Screen reader support (test with NVDA/JAWS)
- Skip navigation links
- Form error announcements

### Keyboard Shortcuts
- `Ctrl/Cmd + K` - Quick search
- `Ctrl/Cmd + N` - New sale (on POS screen)
- `F2` - Customer search
- `F9` - Park sale
- `F10` - Payment
- `Esc` - Cancel/close modal

---

## Error Handling

### Error Display
```typescript
// Global error boundary
<ErrorBoundary
  fallback={<ErrorFallback />}
  onError={logError}
>
  <App />
</ErrorBoundary>

// Toast notifications
toast.error('Failed to create product');
toast.success('Product created successfully');
toast.warning('Low stock alert');
toast.info('Sync completed');
```

### Error Types
- **Network Errors**: Retry with exponential backoff
- **Validation Errors**: Display inline form errors
- **Server Errors (5xx)**: Show generic error, log details
- **Client Errors (4xx)**: Show specific error message
- **Sync Conflicts**: Show conflict resolution UI

---

## Localization & Internationalization (i18n)

### Supported Languages (Optional)
- English (default)
- French
- Spanish
- Swahili

### Implementation
```typescript
import { useTranslation } from 'react-i18next';

const { t } = useTranslation();

<Button>{t('common.save')}</Button>
<h1>{t('dashboard.welcome', { name: user.name })}</h1>
```

### Locale Data
- Number formatting (1,234.56 vs 1.234,56)
- Currency symbols ($, €, £, KSh, etc.)
- Date formatting (MM/DD/YYYY vs DD/MM/YYYY)
- Timezone handling

---

## Deployment & Environment

### Environment Variables
```env
REACT_APP_API_BASE_URL=https://api.example.com
REACT_APP_ENVIRONMENT=production
REACT_APP_SENTRY_DSN=https://...
REACT_APP_GOOGLE_ANALYTICS_ID=UA-...
REACT_APP_VERSION=2.1.0
REACT_APP_SYNC_INTERVAL=300000  # 5 minutes in ms
```

### Build Output
- Static files (HTML, CSS, JS)
- Service worker for offline support
- Asset optimization (minified, compressed)
- Source maps (for production debugging)

### Hosting Recommendations
- **Frontend**: Vercel, Netlify, AWS S3 + CloudFront
- **API**: Backend hosted separately (Laravel on AWS/DigitalOcean)
- **CDN**: Cloudflare for static assets

---

## Documentation Requirements

### User Documentation
- **User Manual** (PDF/Web) - Complete guide for all features
- **Quick Start Guide** - Get started in 5 minutes
- **Video Tutorials** - Screen recordings for key workflows
- **FAQ** - Common questions and troubleshooting

### Developer Documentation
- **Setup Guide** - Local development environment setup
- **API Reference** - Complete API endpoint documentation
- **Component Library** - Storybook with all UI components
- **Architecture Diagram** - System design and data flow
- **Deployment Guide** - Production deployment steps

---

## Future Enhancements (Nice-to-Have)

### Advanced Features
1. **Loyalty Program** - Customer points and rewards
2. **Multi-Currency** - Support for multiple currencies
3. **Multi-Language Receipts** - Print receipts in customer's language
4. **Barcode Scanner Integration** - Hardware barcode scanner support
5. **Cash Drawer Integration** - Automatic cash drawer opening
6. **Kitchen Display System (KDS)** - For restaurant POS
7. **Table Management** - For dine-in restaurants
8. **Delivery Integration** - Uber Eats, DoorDash API integration
9. **Accounting Integration** - QuickBooks, Xero export
10. **Email Marketing** - Customer email campaigns
11. **SMS Notifications** - Order confirmations, receipts via SMS
12. **Biometric Authentication** - Fingerprint login
13. **AI-Powered Insights** - Sales forecasting, demand prediction
14. **Voice Commands** - Voice-activated POS operations
15. **Mobile App** - React Native/Flutter companion app

---

## Success Metrics

### Performance Metrics
- **Page Load Time**: < 2 seconds
- **Time to Interactive**: < 3 seconds
- **Lighthouse Score**: 90+ (Performance, Accessibility, Best Practices, SEO)
- **API Response Time**: < 500ms (average)

### Business Metrics
- **Sale Completion Time**: < 30 seconds (from item scan to receipt print)
- **User Onboarding Time**: < 10 minutes (new user to first sale)
- **Offline Mode Uptime**: 99.9% reliability
- **Sync Success Rate**: > 99% (successful syncs without conflicts)

---

## Deliverables Checklist

### Phase 1: Core POS (MVP)
- ✅ Authentication (login, PIN login)
- ✅ Business & branch setup
- ✅ Product management
- ✅ Category management
- ✅ POS interface (cashier view)
- ✅ Sales creation and history
- ✅ Basic inventory tracking
- ✅ Customer management
- ✅ Payment methods
- ✅ Sales shift management

### Phase 2: Advanced Inventory
- ✅ Batch tracking (FEFO)
- ✅ Near-expiry alerts
- ✅ Stock transfers (workflow)
- ✅ Stock write-offs
- ✅ Shelf vs Store inventory
- ✅ Inventory transactions history

### Phase 3: Workflows & Approvals
- ✅ Refund request workflow
- ✅ Quick sale workflow (near-expiry discounts)
- ✅ Manager approval system
- ✅ Role-based access control

### Phase 4: Analytics & Reporting
- ✅ Organization analytics dashboard
- ✅ Branch analytics
- ✅ Product analytics
- ✅ Profit & loss report
- ✅ Growth trends
- ✅ Export capabilities (PDF, Excel)

### Phase 5: Offline & Sync
- ✅ Device registration
- ✅ IndexedDB implementation
- ✅ Offline queue system
- ✅ Bootstrap sync
- ✅ Pull/Push sync
- ✅ Conflict resolution UI
- ✅ Sync status dashboard

### Phase 6: Polish & Production
- ✅ Responsive design (all devices)
- ✅ Dark mode
- ✅ Performance optimization
- ✅ Security hardening
- ✅ Accessibility compliance
- ✅ Error handling
- ✅ User documentation
- ✅ Deployment setup

---

## Example User Flows

### Flow 1: Cashier Completes a Sale
1. Cashier logs in with PIN
2. Starts a shift (enters opening float)
3. Scans product barcode or searches by name
4. Product added to cart with price
5. Adjusts quantity if needed
6. Applies line item discount (optional)
7. Adds more products
8. Applies cart-level discount (optional)
9. Selects customer (optional)
10. Clicks "Checkout"
11. Selects payment method (Cash)
12. Enters amount received ($50)
13. System calculates change ($5)
14. Confirms payment
15. Receipt prints automatically
16. Sale saved to local database
17. Sale synced to server (if online) or queued (if offline)

### Flow 2: Manager Approves Refund Request
1. Manager logs in
2. Navigates to Refund Requests
3. Sees pending refund request notification badge
4. Clicks on pending refund request
5. Reviews sale details, items, original payment
6. Reviews refund reason provided by cashier
7. Approves refund
8. System creates refund transaction
9. Inventory updated (items returned to stock)
10. Customer receives refund via original payment method
11. Refund receipt printed

### Flow 3: Inventory Manager Creates Stock Transfer
1. Inventory manager logs in
2. Navigates to Inventory → Transfers
3. Clicks "New Transfer"
4. Selects source branch (Branch A)
5. Selects destination branch (Branch B)
6. Adds products to transfer (Product X, 50 units, Batch #123)
7. Adds transfer reason/notes
8. Submits transfer request
9. System creates transfer with "Pending" status
10. Manager at Branch A receives approval notification
11. Manager approves transfer (status → "Approved")
12. Inventory deducted from Branch A (status → "In Transit")
13. Branch B receives notification
14. Branch B confirms receipt (status → "Completed")
15. Inventory added to Branch B

### Flow 4: Device Syncs After Being Offline
1. POS device loses internet connection
2. Offline indicator appears (red banner)
3. Cashier continues making sales (saved to IndexedDB)
4. 10 sales completed offline
5. Internet connection restored
6. Online indicator appears (green banner)
7. "Sync Now" button appears
8. Cashier clicks "Sync Now"
9. System uploads 10 sales to server (push)
10. System downloads server changes (pull)
11. Conflict detected (Product price changed on server)
12. Conflict resolution modal appears
13. User selects "Keep Server Price"
14. Conflict resolved
15. Sync completes successfully
16. Sync summary shows: 10 sales uploaded, 5 products updated, 1 conflict resolved

---

## Final Notes

This comprehensive prompt provides a complete blueprint for building a production-ready, enterprise-grade POS management system with offline-first capabilities, multi-tenancy, advanced inventory management, and workflow approvals.

**Key Differentiators:**
- **Offline-First**: Reliable operation without internet
- **FEFO Inventory**: Automatic expiry management
- **Workflow Engine**: Approval-based refunds, transfers, quick sales
- **Multi-Tenant**: Isolated business data with branch-level scoping
- **Role-Based Access**: Granular permissions for security
- **Real-Time Analytics**: Comprehensive business intelligence

**Estimated Timeline:**
- **Phase 1 (MVP)**: 4-6 weeks
- **Phase 2 (Inventory)**: 2-3 weeks
- **Phase 3 (Workflows)**: 2-3 weeks
- **Phase 4 (Analytics)**: 2-3 weeks
- **Phase 5 (Offline/Sync)**: 3-4 weeks
- **Phase 6 (Polish)**: 2-3 weeks
- **Total**: 15-22 weeks (3.5-5 months)

**Team Recommendation:**
- 2-3 Frontend Developers (React/Vue experts)
- 1 UI/UX Designer
- 1 Backend Integration Specialist
- 1 QA Engineer
- 1 DevOps Engineer (for deployment)

This system is designed to scale from small retail shops to large multi-branch enterprises with hundreds of concurrent users.
