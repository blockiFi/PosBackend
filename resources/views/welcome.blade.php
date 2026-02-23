<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>POS Backend System - API Documentation</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, sans-serif; line-height: 1.6; color: #1a1a1a; background: #f9fafb; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 80px 20px; text-align: center; margin-bottom: 40px; }
        .header h1 { font-size: 3rem; font-weight: 700; margin-bottom: 20px; }
        .header p { font-size: 1.25rem; opacity: 0.95; max-width: 800px; margin: 0 auto; }
        .section { background: white; border-radius: 12px; padding: 40px; margin-bottom: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h2 { font-size: 2rem; font-weight: 700; margin-bottom: 20px; color: #667eea; }
        h3 { font-size: 1.5rem; font-weight: 600; margin-top: 30px; margin-bottom: 15px; color: #4a5568; }
        h4 { font-size: 1.125rem; font-weight: 600; margin-top: 20px; margin-bottom: 10px; color: #2d3748; }
        ul, ol { margin-left: 20px; margin-bottom: 20px; }
        li { margin-bottom: 10px; }
        code { background: #f7fafc; padding: 2px 6px; border-radius: 4px; font-family: 'Courier New', monospace; font-size: 0.9em; color: #e53e3e; }
        pre { background: #2d3748; color: #e2e8f0; padding: 20px; border-radius: 8px; overflow-x: auto; margin: 20px 0; }
        pre code { background: none; color: inherit; padding: 0; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 30px 0; }
        .card { background: #f7fafc; padding: 25px; border-radius: 8px; border-left: 4px solid #667eea; }
        .card h4 { margin-top: 0; color: #667eea; }
        a { color: #667eea; text-decoration: none; font-weight: 500; }
        a:hover { text-decoration: underline; }
        .footer { text-align: center; padding: 40px 20px; color: #718096; font-size: 0.875rem; }
        .emoji { font-size: 1.5rem; margin-right: 10px; }
        .tech-stack { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; }
        .tech-item { background: #edf2f7; padding: 8px 16px; border-radius: 6px; font-weight: 500; color: #2d3748; }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <h1>�� POS Backend System</h1>
            <p>A comprehensive Point of Sale (POS) backend API built with Laravel 12, designed for multi-business, multi-branch retail operations with advanced inventory management, offline synchronization, and real-time analytics.</p>
        </div>
    </div>
    <div class="container">
        <div class="section">
            <h2><span class="emoji">🚀</span>Features</h2>
            <div class="grid">
                <div class="card">
                    <h4>Core Functionality</h4>
                    <ul>
                        <li><strong>Multi-Tenancy</strong>: Business-scoped data isolation</li>
                        <li><strong>Authentication</strong>: Laravel Sanctum with PIN login</li>
                        <li><strong>RBAC</strong>: Granular permissions</li>
                        <li><strong>RESTful API</strong>: Comprehensive endpoints</li>
                    </ul>
                </div>
                <div class="card">
                    <h4>Inventory Management</h4>
                    <ul>
                        <li><strong>Shelf & Store</strong>: Dual-location tracking</li>
                        <li><strong>Shelf/Store Move Requests</strong>: Request → approve/reject workflow</li>
                        <li><strong>Batch Management</strong>: FEFO with expiry tracking</li>
                        <li><strong>Stock Transfers</strong>: Request-based movement</li>
                        <li><strong>Write-offs</strong>: Damage tracking with approvals</li>
                    </ul>
                </div>
                <div class="card">
                    <h4>Sales Management</h4>
                    <ul>
                        <li><strong>Point of Sale</strong>: Complete sales processing</li>
                        <li><strong>Quick Sales</strong>: Fast-track with temp pricing</li>
                        <li><strong>Sales Shifts</strong>: Shift-based tracking</li>
                        <li><strong>Refund Requests</strong>: Approval workflow</li>
                    </ul>
                </div>
                <div class="card">
                    <h4>Analytics & Reporting</h4>
                    <ul>
                        <li><strong>Business Analytics</strong>: Revenue & profit metrics</li>
                        <li><strong>Product Analytics</strong>: Best sellers, alerts</li>
                        <li><strong>Shift Statistics</strong>: Performance tracking</li>
                        <li><strong>Time-based Reports</strong>: Custom date ranges</li>
                    </ul>
                </div>
                <div class="card">
                    <h4>Offline Synchronization</h4>
                    <ul>
                        <li><strong>Device Registration</strong>: Secure device auth</li>
                        <li><strong>Change Log System</strong>: Modification tracking</li>
                        <li><strong>Pull/Push Sync</strong>: Bidirectional sync</li>
                        <li><strong>Conflict Resolution</strong>: Auto & manual</li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="section">
            <h2><span class="emoji">📋</span>Requirements</h2>
            <div class="tech-stack">
                <span class="tech-item">PHP >= 8.2</span>
                <span class="tech-item">Composer</span>
                <span class="tech-item">MySQL/PostgreSQL</span>
                <span class="tech-item">Laravel 12.x</span>
                <span class="tech-item">Node.js & NPM</span>
            </div>
        </div>
        <div class="section">
            <h2><span class="emoji">🛠️</span>Installation</h2>
            <h4>1. Clone the Repository</h4>
            <pre><code>git clone https://github.com/blockiFi/PosBackend.git
cd PosBackend</code></pre>
            <h4>2. Install Dependencies</h4>
            <pre><code>composer install && npm install</code></pre>
            <h4>3. Environment Configuration</h4>
            <pre><code>cp .env.example .env
php artisan key:generate</code></pre>
            <h4>4. Database Setup</h4>
            <pre><code>php artisan migrate && php artisan db:seed</code></pre>
            <h4>5. Start Development Server</h4>
            <pre><code>php artisan serve</code></pre>
            <p>The API will be available at <code>http://localhost:8000/api</code></p>
        </div>
        <div class="section">
            <h2><span class="emoji">📚</span>Documentation</h2>
            <h3>Main Documentation</h3>
            <ul>
                <li><a href="/docs/API_DOCUMENTATION">API Documentation</a> - Full reference with request/response schemas</li>
                <li><a href="/docs/API_DOCUMENTATION#g-complete-api-route-reference">Complete API Route Reference</a> - Every route (method, path, description)</li>
                <li><a href="/docs/ANALYTICS_API">Analytics API</a> - Analytics endpoints</li>
                <li><a href="/docs/OFFLINE_SYNC_DOCUMENTATION">Offline Sync</a> - Client device sync (register, bootstrap, pull, push)</li>
                <li><a href="/docs/SERVER_TO_SERVER_SYNC_GUIDE">Server-to-Server Sync</a> - Edge ↔ Cloud sync (push, pull, receive, provide-changes)</li>
                <li><a href="/docs/FRONTEND_INTEGRATION_GUIDE">Frontend Integration</a> - Implementation guide</li>
                <li><a href="/docs/DATABASE_SEEDER_DOCUMENTATION">Database Seeder</a> - Seeding and test data</li>
                <li><a href="/docs/POSTMAN_COLLECTION_DOCUMENTATION">Postman Collection</a> - API testing</li>
            </ul>
            <h3>API Coverage by Module</h3>
            <p>All routes are documented in the API doc. Controllers and areas covered:</p>
            <ul>
                <li><strong>Auth:</strong> register, login, pin-login, pin/set, pin/remove, business-details-with-branch-auth, GET /user</li>
                <li><strong>Business & Branches:</strong> CRUD businesses, CRUD branches, branches/generate-auth-codes, branches/{id}/products</li>
                <li><strong>Roles & Users:</strong> permissions, roles (CRUD, add/remove permission), assign/remove role, users/{id}/roles, business-users (CRUD, optional branch_id filter)</li>
                <li><strong>Catalog:</strong> categories (CRUD, breadcrumb), products (CRUD, add/remove branches, price), GET branches/{id}/products (products by branch with filters)</li>
                <li><strong>Branch Products:</strong> list, by-category, CRUD, assign-multiple, stock, move-to-shelf/store, summary/stock, bulk-update</li>
                <li><strong>Inventory:</strong> transactions (list, create, show), stock-summary; FEFO batch allocation for stock-in/out</li>
                <li><strong>Batches:</strong> list, near-expiry (days, branch_id), expired, show, update, products/{id}/batches — responses include product, branch, and quick_sale_requested</li>
                <li><strong>Customers & Payments:</strong> customers CRUD, payment-methods CRUD</li>
                <li><strong>Sales:</strong> sales (list, create, show, addPayment, cancel)</li>
                <li><strong>Shifts:</strong> list, open, current, show, sales, close, pause, resume, resolve-discrepancy</li>
                <li><strong>Analytics:</strong> organization, branches, products, profit-loss, growth-trends</li>
                <li><strong>Workflows:</strong> stock-transfer-requests (approve, accept, reject-in, reject, confirm, cancel), shelf-store-move-requests (approve, reject), stock-writeoffs, refund-requests (approve, reject), quick-sales (approve, reject, end)</li>
                <li><strong>Sync:</strong> register-device, bootstrap, pull, push, resolve-conflicts, status, heartbeat</li>
                <li><strong>Server-Sync:</strong> push, pull, status, health, receive, provide-changes</li>
            </ul>
            <h3>Specific Features</h3>
            <ul>
                <li><a href="/docs/BATCH_EXPIRY_MANAGEMENT">Batch Expiry Management</a></li>
                <li><a href="/docs/QUICK_SALE_WORKFLOW">Quick Sale Workflow</a></li>
                <li><a href="/docs/REFUND_REQUEST_WORKFLOW">Refund Request Workflow</a></li>
                <li><a href="/docs/SALES_SHIFT_IMPLEMENTATION">Sales Shift Implementation</a></li>
                <li><a href="/docs/PIN_LOGIN_REFERENCE">PIN Login System</a></li>
                <li><a href="/docs/BRANCH_ACCESS_CONTROL">Branch Access Control</a></li>
                <li><a href="/docs/BUSINESS_ISOLATION">Business Isolation</a></li>
                <li><a href="/docs/SHELF_STORE_INVENTORY_SYSTEM">Shelf & Store Inventory</a></li>
            </ul>
        </div>
        <div class="section">
            <h2><span class="emoji">🔑</span>API Authentication</h2>
            <p>The API uses Laravel Sanctum for authentication. Include the token in your requests:</p>
            <pre><code>Authorization: Bearer {your-token}
X-Business-Id: {business-id}</code></pre>
            <h4>Getting a Token</h4>
            <pre><code>POST /api/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password"
}</code></pre>
        </div>
        <div class="section">
            <h2><span class="emoji">🧪</span>Testing</h2>
            <pre><code>php artisan test</code></pre>
            <h4>Test coverage includes:</h4>
            <ul>
                <li>Authentication & Authorization</li>
                <li>Sales & Inventory Management</li>
                <li>Batch Management & FEFO</li>
                <li>Sync System & Conflict Resolution</li>
                <li>Analytics & Reporting</li>
            </ul>
        </div>
        <div class="section">
            <h2><span class="emoji">🏗️</span>Built With</h2>
            <div class="tech-stack">
                <span class="tech-item">Laravel 12</span>
                <span class="tech-item">Laravel Sanctum</span>
                <span class="tech-item">Spatie Laravel Permission</span>
                <span class="tech-item">MySQL</span>
                <span class="tech-item">PHPUnit</span>
            </div>
        </div>
    </div>
    <div class="footer">
        <div class="container">
            <p><strong>Version:</strong> 1.0.0 | <strong>Last Updated:</strong> February 2026</p>
            <p>Built with ❤️ using Laravel 12</p>
            <p><a href="https://github.com/blockiFi/PosBackend" target="_blank">View on GitHub</a></p>
        </div>
    </div>
</body>
</html>
