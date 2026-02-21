# POS Backend System

A comprehensive Point of Sale (POS) backend API built with Laravel 12, designed for multi-business, multi-branch retail operations with advanced inventory management, offline synchronization, and real-time analytics.

## 🚀 Features

### Core Functionality
- **Multi-Tenancy**: Business-scoped data isolation with branch-level access control
- **Authentication**: Secure authentication using Laravel Sanctum with PIN-based login support
- **Role-Based Access Control (RBAC)**: Granular permissions using Spatie Laravel Permission
- **RESTful API**: Complete API with comprehensive endpoints for all operations

### Inventory Management
- **Shelf & Store Inventory**: Dual-location inventory tracking system
- **Batch Management**: FEFO (First Expiry First Out) inventory with expiry tracking
- **Stock Transfers**: Request-based stock movement between shelf and store
- **Stock Write-offs**: Damage and expiry tracking with approval workflows
- **Real-time Stock Updates**: Automatic stock adjustments on sales and transfers

### Sales Management
- **Point of Sale**: Complete sales processing with multiple payment methods
- **Quick Sales**: Fast-track sales with temporary pricing and discounts
- **Sales Shifts**: Shift-based sales tracking with opening/closing balances
- **Refund Requests**: Structured refund workflow with approval system
- **Customer Management**: Customer profiles and transaction history

### Analytics & Reporting
- **Business Analytics**: Revenue, profit, and sales metrics
- **Product Analytics**: Best sellers, low stock alerts, and inventory valuations
- **Sales Shift Statistics**: Detailed shift performance and discrepancy tracking
- **Time-based Reports**: Daily, weekly, monthly, and custom date range reporting

### Offline Synchronization
- **Device Registration**: Secure device-to-server authentication
- **Change Log System**: Tracks all data modifications with conflict detection
- **Pull/Push Sync**: Bidirectional synchronization for offline POS terminals
- **Server-to-Server Sync**: Multi-location server synchronization support
- **Conflict Resolution**: Automatic and manual conflict resolution strategies

## 📋 Requirements

- PHP >= 8.2
- Composer
- MySQL/PostgreSQL
- Laravel 12.x
- Node.js & NPM (for frontend assets)

## 🛠️ Installation

### 1. Clone the Repository
```bash
git clone https://github.com/blockiFi/PosBackend.git
cd PosBackend
```

### 2. Install Dependencies
```bash
composer install
npm install
```

### 3. Environment Configuration
```bash
cp .env.example .env
php artisan key:generate
```

Configure your database in the `.env` file:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pos_backend
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### 4. Database Setup
```bash
php artisan migrate
php artisan db:seed
```

### 5. Generate Application Key & Storage Link
```bash
php artisan storage:link
```

### 6. Start Development Server
```bash
php artisan serve
```

The API will be available at `http://localhost:8000/api`

## 📚 Documentation

Comprehensive documentation is available in the repository:

- **[API Documentation](API_DOCUMENTATION.md)** - Complete API reference with all endpoints (includes [Complete Route Reference](API_DOCUMENTATION.md#g-complete-api-route-reference))
- **[Analytics API](ANALYTICS_API.md)** - Analytics and reporting endpoints
- **[Offline Sync Documentation](OFFLINE_SYNC_DOCUMENTATION.md)** - Synchronization system guide
- **[Server-to-Server Sync](SERVER_TO_SERVER_SYNC_GUIDE.md)** - Multi-server sync setup
- **[Frontend Integration Guide](FRONTEND_INTEGRATION_GUIDE.md)** - Frontend implementation guide
- **[Database Seeder Documentation](DATABASE_SEEDER_DOCUMENTATION.md)** - Seeding and testing data
- **[Postman Collection Documentation](POSTMAN_COLLECTION_DOCUMENTATION.md)** - API testing with Postman

### Specific Features
- [Batch Expiry Management](BATCH_EXPIRY_MANAGEMENT.md)
- [Quick Sale Workflow](QUICK_SALE_WORKFLOW.md)
- [Refund Request Workflow](REFUND_REQUEST_WORKFLOW.md)
- [Sales Shift Implementation](SALES_SHIFT_IMPLEMENTATION.md)
- [PIN Login System](PIN_LOGIN_REFERENCE.md)
- [Branch Access Control](BRANCH_ACCESS_CONTROL.md)
- [Business Isolation](BUSINESS_ISOLATION.md)
- [Shelf & Store Inventory System](SHELF_STORE_INVENTORY_SYSTEM.md)

## 🔑 API Authentication

The API uses Laravel Sanctum for authentication. Include the token in your requests:

```bash
Authorization: Bearer {your-token}
X-Business-Id: {business-id}
```

### Getting a Token
```bash
POST /api/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password"
}
```

For a complete list of every API route and controller capability, see **[API Documentation → Appendix G: Complete API Route Reference](API_DOCUMENTATION.md#g-complete-api-route-reference)**.

## 🧪 Testing

Run the test suite:
```bash
php artisan test
```

Run specific test suites:
```bash
# Feature tests
php artisan test --testsuite=Feature

# Unit tests
php artisan test --testsuite=Unit

# Specific test file
php artisan test tests/Feature/AnalyticsTest.php
```

Test coverage includes:
- Authentication & Authorization
- Sales & Inventory Management
- Batch Management & FEFO
- Sync System & Conflict Resolution
- Analytics & Reporting
- Refunds & Stock Transfers

## 📦 Postman Collections

Import the Postman collections for API testing:
- `POS_Backend_Complete_API_v2.postman_collection.json` - Latest complete collection
- `ANALYTICS_API.postman_collection.json` - Analytics-specific endpoints

## 🗂️ Project Structure

```
app/
├── Console/Commands/        # Artisan commands (sync, cleanup)
├── Http/
│   ├── Controllers/Api/    # API controllers
│   └── Middleware/         # Custom middleware
└── Models/                 # Eloquent models

database/
├── migrations/             # Database migrations
├── seeders/               # Database seeders
└── factories/             # Model factories

tests/
├── Feature/               # Feature tests
└── Unit/                  # Unit tests

routes/
└── api.php               # API routes
```

## 🔄 Scheduled Tasks

The application includes automated tasks:
```bash
# Cleanup expired quick sale discounts (runs daily)
php artisan schedule:work
```

## 🤝 Contributing

Contributions are welcome! Please follow these steps:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📝 License

This project is licensed under the MIT License - see the LICENSE file for details.

## 🆘 Support

For issues, questions, or contributions:
- Create an issue in the GitHub repository
- Review existing documentation in the `/docs` folder
- Check the API documentation for endpoint details

## 🏗️ Built With

- [Laravel 12](https://laravel.com) - PHP Framework
- [Laravel Sanctum](https://laravel.com/docs/sanctum) - API Authentication
- [Spatie Laravel Permission](https://spatie.be/docs/laravel-permission) - Role & Permission Management
- [MySQL](https://www.mysql.com/) - Database
- [PHPUnit](https://phpunit.de/) - Testing Framework

---

**Version:** 1.0.0  
**Last Updated:** February 2026
