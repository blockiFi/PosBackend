<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthenticationController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BranchProductController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\BusinessSettingsController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceGroupController;
use App\Http\Controllers\Api\GoodsReceivedNoteController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\QuickSaleController;
use App\Http\Controllers\Api\RefundRequestController;
use App\Http\Controllers\Api\RolePermissionController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SalesShiftController;
use App\Http\Controllers\Api\SeedController;
use App\Http\Controllers\Api\ShelfStoreMoveRequestController;
use App\Http\Controllers\Api\StockTransferRequestController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SyncDashboardController;
use App\Http\Controllers\Api\UserBusinessController;
use App\Http\Controllers\StockWriteoffController;
use App\Http\Controllers\SyncController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::put('/user', [AuthenticationController::class, 'updateProfile'])->middleware('auth:sanctum');

// Public auth
Route::post('register', [AuthenticationController::class, 'register']);
Route::post('login', [AuthenticationController::class, 'login']);
Route::post('pin-login', [AuthenticationController::class, 'pinLogin']);
Route::post('business-details-with-branch-auth', [AuthenticationController::class, 'getBusinessDetailsWithBranchAuthorization']);
Route::post('register-cashier-device', [AuthenticationController::class, 'registerCashierDeviceWithBranchAuthorization']);

// Protected routes
Route::middleware(['auth:sanctum'])->group(function () {
    // PIN management
    Route::post('pin/set', [AuthenticationController::class, 'setPin']);
    Route::post('pin/remove', [AuthenticationController::class, 'removePin']);
    // Get business and branch by auth code (branch authorization)
    // Business listing & creation (scoped to authenticated user's memberships)
    Route::get('businesses', [BusinessController::class, 'index']);
    Route::post('businesses', [BusinessController::class, 'store']);

    // Permissions (no business context needed - global)
    Route::get('permissions', [RolePermissionController::class, 'listPermissions']);

    // Business-specific actions require business context (header/query or pivot membership)
    Route::middleware(['business.context'])->group(function () {
        Route::get('dashboard/summary', [DashboardController::class, 'summary']);

        Route::get('businesses/{id}', [BusinessController::class, 'show']);
        Route::put('businesses/{id}', [BusinessController::class, 'update']);
        Route::delete('businesses/{id}', [BusinessController::class, 'destroy']);

        // Business settings (require business context)
        Route::get('settings/business', [BusinessSettingsController::class, 'show']);
        Route::put('settings/business', [BusinessSettingsController::class, 'update']);

        // Branch routes (require business context)
        Route::get('branches', [BranchController::class, 'index']);
        Route::post('branches', [BranchController::class, 'store']);
        Route::post('branches/generate-auth-codes', [BranchController::class, 'generateAuthCode']);
        Route::get('branches/{id}', [BranchController::class, 'show']);
        Route::put('branches/{id}', [BranchController::class, 'update']);
        Route::delete('branches/{id}', [BranchController::class, 'destroy']);

        // Device Group routes (require business context)
        Route::get('device-groups', [DeviceGroupController::class, 'index']);
        Route::get('device-groups/report', [DeviceGroupController::class, 'report']);
        Route::post('device-groups', [DeviceGroupController::class, 'store']);
        Route::get('device-groups/{id}', [DeviceGroupController::class, 'show']);
        Route::put('device-groups/{id}', [DeviceGroupController::class, 'update']);
        Route::delete('device-groups/{id}', [DeviceGroupController::class, 'destroy']);
        Route::post('device-groups/{id}/assign-device', [DeviceGroupController::class, 'assignDevice']);
        Route::post('device-groups/{id}/remove-device', [DeviceGroupController::class, 'removeDevice']);

        // Devices (require business context)
        Route::get('devices', [DeviceController::class, 'index']);
        Route::get('devices/{device}', [DeviceController::class, 'show']);
        Route::put('devices/{device}', [DeviceController::class, 'update']);
        Route::patch('devices/{device}', [DeviceController::class, 'update']);
        Route::delete('devices/{device}', [DeviceController::class, 'destroy']);

        // Sync Dashboard (require business context)
        Route::prefix('sync/dashboard')->group(function () {
            Route::get('summary', [SyncDashboardController::class, 'summary']);
            Route::get('sessions', [SyncDashboardController::class, 'sessions']);
            Route::get('conflicts', [SyncDashboardController::class, 'conflicts']);
        });

        // Role & Permission routes (require business context)
        Route::get('roles', [RolePermissionController::class, 'index']);
        Route::post('roles', [RolePermissionController::class, 'store']);
        Route::post('roles/addpermission', [RolePermissionController::class, 'addPermissionToRole']);
        Route::post('roles/removepermission', [RolePermissionController::class, 'removePermissionFromRole']);
        Route::get('roles/{id}', [RolePermissionController::class, 'show']);
        Route::put('roles/{id}', [RolePermissionController::class, 'update']);
        Route::delete('roles/{id}', [RolePermissionController::class, 'destroy']);

        // User role assignment routes
        Route::post('roles/assign', [RolePermissionController::class, 'assignRoleToUser']);
        Route::post('roles/remove', [RolePermissionController::class, 'removeRoleFromUser']);
        Route::get('users/{userId}/roles', [RolePermissionController::class, 'getUserRoles']);

        // User Business Management routes
        Route::get('business-users', [UserBusinessController::class, 'index']);
        Route::post('business-users', [UserBusinessController::class, 'store']);
        Route::get('business-users/{userId}', [UserBusinessController::class, 'show']);
        Route::put('business-users/{userId}/set-password', [UserBusinessController::class, 'setPassword']);
        Route::put('business-users/{userId}', [UserBusinessController::class, 'update']);
        Route::delete('business-users/{userId}', [UserBusinessController::class, 'destroy']);

        // Product Category routes (require business context)
        Route::get('categories', [ProductCategoryController::class, 'index']);
        Route::post('categories', [ProductCategoryController::class, 'store']);
        Route::get('categories/{id}', [ProductCategoryController::class, 'show']);
        Route::put('categories/{id}', [ProductCategoryController::class, 'update']);
        Route::delete('categories/{id}', [ProductCategoryController::class, 'destroy']);
        Route::get('categories/{id}/breadcrumb', [ProductCategoryController::class, 'breadcrumb']);

        // Seed from file (CSV/Excel with column mapping)
        Route::post('seed', [SeedController::class, 'store']);
        Route::get('seed/{id}/status', [SeedController::class, 'status']);

        // Product routes (require business context)
        Route::get('products', [ProductController::class, 'index']);
        Route::post('products', [ProductController::class, 'store']);
        Route::get('products/{id}', [ProductController::class, 'show']);
        Route::put('products/{id}', [ProductController::class, 'update']);
        Route::delete('products/{id}', [ProductController::class, 'destroy']);
        Route::post('products/{id}/branches', [ProductController::class, 'addToBranch']);
        Route::delete('products/{id}/branches', [ProductController::class, 'removeFromBranch']);
        Route::patch('products/{id}/price', [ProductController::class, 'updatePrice']);
        Route::patch('products/{id}/base-selling-price', [ProductController::class, 'updateBaseSellingPrice']);
        Route::get('products/{id}/units', [ProductController::class, 'indexUnits']);
        Route::post('products/{id}/units', [ProductController::class, 'storeUnit']);
        Route::put('products/{id}/units/{unitId}', [ProductController::class, 'updateUnit']);
        Route::delete('products/{id}/units/{unitId}', [ProductController::class, 'destroyUnit']);

        // Get products for a specific branch (with permission check)
        Route::get('branches/{branchId}/products', [ProductController::class, 'getProductsByBranch']);

        // Branch Product routes (require business context)
        Route::get('branch-products', [BranchProductController::class, 'index']);
        Route::get('branch-products/by-category', [BranchProductController::class, 'getByCategory']);
        Route::post('branch-products', [BranchProductController::class, 'store']);
        Route::post('branch-products/assign-multiple', [BranchProductController::class, 'assignMultiple']);
        Route::post('branch-products/bulk-move', [BranchProductController::class, 'bulkMove']);
        Route::post('branch-products/bulk-selling-price', [BranchProductController::class, 'bulkSellingPrice']);
        Route::get('branch-products/{id}', [BranchProductController::class, 'show']);
        Route::get('branch-products/{id}/price', [BranchProductController::class, 'getPrice']);
        Route::get('branch-products/{id}/unit-prices', [BranchProductController::class, 'indexUnitPrices']);
        Route::post('branch-products/{id}/unit-prices', [BranchProductController::class, 'storeUnitPrice']);
        Route::put('branch-products/{id}/unit-prices/{unitPriceId}', [BranchProductController::class, 'updateUnitPrice']);
        Route::delete('branch-products/{id}/unit-prices/{unitPriceId}', [BranchProductController::class, 'destroyUnitPrice']);
        Route::get('branch-products/{id}/quantity-tiers', [BranchProductController::class, 'indexQuantityTiers']);
        Route::post('branch-products/{id}/quantity-tiers', [BranchProductController::class, 'storeQuantityTier']);
        Route::put('branch-products/{id}/quantity-tiers/{tierId}', [BranchProductController::class, 'updateQuantityTier']);
        Route::delete('branch-products/{id}/quantity-tiers/{tierId}', [BranchProductController::class, 'destroyQuantityTier']);
        Route::put('branch-products/{id}', [BranchProductController::class, 'update']);
        Route::patch('branch-products/{id}/selling-price', [BranchProductController::class, 'updateSellingPrice']);
        Route::delete('branch-products/{id}', [BranchProductController::class, 'destroy']);
        Route::post('branch-products/{id}/stock', [BranchProductController::class, 'updateStock']);
        Route::post('branch-products/{id}/move-to-shelf', [BranchProductController::class, 'moveToShelf']);
        Route::post('branch-products/{id}/move-to-store', [BranchProductController::class, 'moveToStore']);
        Route::get('branch-products/summary/stock', [BranchProductController::class, 'stockSummary']);
        Route::post('branch-products/bulk-update', [BranchProductController::class, 'bulkUpdate']);

        // Inventory routes (require business context)
        Route::get('inventory/transactions', [InventoryController::class, 'index']);
        Route::post('inventory/transactions', [InventoryController::class, 'store']);
        Route::get('inventory/transactions/{id}', [InventoryController::class, 'show']);
        Route::get('inventory/stock-summary', [InventoryController::class, 'stockSummary']);

        // Suppliers + GRN routes (require business context)
        Route::get('suppliers', [SupplierController::class, 'index']);
        Route::post('suppliers', [SupplierController::class, 'store']);
        Route::get('suppliers/{id}', [SupplierController::class, 'show']);
        Route::put('suppliers/{id}', [SupplierController::class, 'update']);
        Route::delete('suppliers/{id}', [SupplierController::class, 'destroy']);
        Route::get('suppliers/{id}/prices', [SupplierController::class, 'prices']);

        Route::get('grn/analytics/receipts-by-supplier', [GoodsReceivedNoteController::class, 'receiptsBySupplier']);
        Route::get('goods-received-notes', [GoodsReceivedNoteController::class, 'index']);
        Route::post('goods-received-notes', [GoodsReceivedNoteController::class, 'store']);
        Route::get('goods-received-notes/{id}', [GoodsReceivedNoteController::class, 'show']);
        Route::put('goods-received-notes/{id}', [GoodsReceivedNoteController::class, 'update']);
        Route::delete('goods-received-notes/{id}', [GoodsReceivedNoteController::class, 'destroy']);
        Route::post('goods-received-notes/{id}/submit', [GoodsReceivedNoteController::class, 'submit']);
        Route::post('goods-received-notes/{id}/approve', [GoodsReceivedNoteController::class, 'approve']);
        Route::post('goods-received-notes/{id}/reject', [GoodsReceivedNoteController::class, 'reject']);
        Route::post('goods-received-notes/{id}/cancel', [GoodsReceivedNoteController::class, 'cancel']);
        Route::post('goods-received-notes/{id}/lines', [GoodsReceivedNoteController::class, 'addLine']);
        Route::put('goods-received-notes/{id}/lines/{lineId}', [GoodsReceivedNoteController::class, 'updateLine']);
        Route::delete('goods-received-notes/{id}/lines/{lineId}', [GoodsReceivedNoteController::class, 'deleteLine']);

        // Purchase orders (Phase 2)
        Route::get('purchase-orders/analytics/top-variance-items', [PurchaseOrderController::class, 'topVarianceItems']);
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store']);
        Route::get('purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
        Route::put('purchase-orders/{id}', [PurchaseOrderController::class, 'update']);
        Route::post('purchase-orders/{id}/submit', [PurchaseOrderController::class, 'submit']);
        Route::post('purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel']);
        Route::get('purchase-orders/{id}/receivable', [PurchaseOrderController::class, 'receivable']);

        // Customer routes (require business context)
        Route::get('customers', [CustomerController::class, 'index']);
        Route::post('customers', [CustomerController::class, 'store']);
        Route::get('customers/{id}', [CustomerController::class, 'show']);
        Route::put('customers/{id}', [CustomerController::class, 'update']);
        Route::delete('customers/{id}', [CustomerController::class, 'destroy']);

        // Payment Method routes (require business context)
        Route::get('payment-methods', [PaymentMethodController::class, 'index']);
        Route::post('payment-methods', [PaymentMethodController::class, 'store']);
        Route::get('payment-methods/{id}', [PaymentMethodController::class, 'show']);
        Route::put('payment-methods/{id}', [PaymentMethodController::class, 'update']);
        Route::delete('payment-methods/{id}', [PaymentMethodController::class, 'destroy']);

        // Sale routes (require business context)
        Route::get('sales', [SaleController::class, 'index']);
        Route::post('sales', [SaleController::class, 'store']);
        // NOTE: literal-segment routes must come before {id} routes so they win matching.
        Route::get('sales/by-reference/{reference}', [SaleController::class, 'findByReference']);
        Route::get('sales/{id}', [SaleController::class, 'show']);
        Route::post('sales/{id}/payments', [SaleController::class, 'addPayment']);
        Route::post('sales/{id}/complete-deposit', [SaleController::class, 'completeDeposit']);
        Route::post('sales/{id}/cancel', [SaleController::class, 'cancel']);

        // Sales Shift routes (require business context)
        Route::get('shifts', [SalesShiftController::class, 'index']);
        Route::get('shifts/branch-summary', [SalesShiftController::class, 'branchShiftsSummary']);
        Route::post('shifts/backfill-groups', [SalesShiftController::class, 'backfillGroups']);
        Route::post('shifts', [SalesShiftController::class, 'store']);
        Route::get('shifts/current', [SalesShiftController::class, 'current']);
        Route::get('shifts/{id}', [SalesShiftController::class, 'show']);
        Route::get('shifts/{id}/summary', [SalesShiftController::class, 'summary']);
        Route::get('shifts/{id}/sales', [SalesShiftController::class, 'sales']);
        Route::post('shifts/{id}/close', [SalesShiftController::class, 'close']);
        Route::post('shifts/{id}/pause', [SalesShiftController::class, 'pause']);
        Route::post('shifts/{id}/resume', [SalesShiftController::class, 'resume']);
        Route::post('shifts/{id}/resolve-discrepancy', [SalesShiftController::class, 'resolveDiscrepancy']);

        // Batch routes (require business context)
        Route::get('batches', [BatchController::class, 'index']);
        Route::get('batches/near-expiry', [BatchController::class, 'nearExpiry']);
        Route::get('batches/expired', [BatchController::class, 'expired']);
        Route::get('batches/{id}', [BatchController::class, 'show']);
        Route::patch('batches/{id}', [BatchController::class, 'update']);
        Route::get('products/{id}/batches', [BatchController::class, 'forProduct']);

        // Analytics routes (require business context and permissions)
        Route::get('analytics/organization', [AnalyticsController::class, 'organizationAnalytics']);
        Route::get('analytics/branches', [AnalyticsController::class, 'branchAnalytics']);
        Route::get('analytics/products', [AnalyticsController::class, 'productAnalytics']);
        Route::get('analytics/profit-loss', [AnalyticsController::class, 'profitLoss']);
        Route::get('analytics/growth-trends', [AnalyticsController::class, 'growthTrends']);

        // Stock Transfer Request routes (workflow system)
        Route::get('stock-transfer-requests', [StockTransferRequestController::class, 'index']);
        Route::post('stock-transfer-requests', [StockTransferRequestController::class, 'store']);
        Route::get('stock-transfer-requests/{id}', [StockTransferRequestController::class, 'show']);
        Route::post('stock-transfer-requests/{id}/approve', [StockTransferRequestController::class, 'approve']);
        Route::post('stock-transfer-requests/{id}/accept', [StockTransferRequestController::class, 'accept']);
        Route::post('stock-transfer-requests/{id}/reject-in', [StockTransferRequestController::class, 'rejectIn']);
        Route::post('stock-transfer-requests/{id}/reject', [StockTransferRequestController::class, 'reject']);
        Route::post('stock-transfer-requests/{id}/confirm', [StockTransferRequestController::class, 'confirm']);
        Route::post('stock-transfer-requests/{id}/cancel', [StockTransferRequestController::class, 'cancel']);

        // Shelf/Store Move Request routes (request → approve/reject)
        Route::get('shelf-store-move-requests', [ShelfStoreMoveRequestController::class, 'index']);
        Route::post('shelf-store-move-requests', [ShelfStoreMoveRequestController::class, 'store']);
        Route::get('shelf-store-move-requests/{id}', [ShelfStoreMoveRequestController::class, 'show']);
        Route::post('shelf-store-move-requests/{id}/approve', [ShelfStoreMoveRequestController::class, 'approve']);
        Route::post('shelf-store-move-requests/{id}/reject', [ShelfStoreMoveRequestController::class, 'reject']);

        // Stock Write-off routes
        Route::get('stock-writeoffs', [StockWriteoffController::class, 'index']);
        Route::post('stock-writeoffs', [StockWriteoffController::class, 'store']);
        Route::post('stock-writeoffs/writeoff-batch', [StockWriteoffController::class, 'writeOffBatch']);
        Route::get('stock-writeoffs/{id}', [StockWriteoffController::class, 'show']);

        // Refund Request routes (workflow system)
        Route::get('refund-requests', [RefundRequestController::class, 'index']);
        Route::post('refund-requests', [RefundRequestController::class, 'store']);
        Route::get('refund-requests/{id}', [RefundRequestController::class, 'show']);
        Route::post('refund-requests/{id}/approve', [RefundRequestController::class, 'approve']);
        Route::post('refund-requests/{id}/reject', [RefundRequestController::class, 'reject']);

        // Quick Sale routes (near-expiry discount workflow)
        Route::get('quick-sales', [QuickSaleController::class, 'index']);
        Route::post('quick-sales', [QuickSaleController::class, 'store']);
        Route::get('quick-sales/{id}', [QuickSaleController::class, 'show']);
        Route::post('quick-sales/{id}/approve', [QuickSaleController::class, 'approve']);
        Route::post('quick-sales/{id}/reject', [QuickSaleController::class, 'reject']);
        Route::post('quick-sales/{id}/end', [QuickSaleController::class, 'end']);

        // Offline Sync routes (Client-side sync - require business context and device ID header)
        Route::prefix('sync')->group(function () {
            Route::get('online-devices', [SyncController::class, 'onlineDevices']);
            Route::post('register-device', [SyncController::class, 'registerDevice']);
            Route::post('bootstrap', [SyncController::class, 'bootstrap']);
            Route::post('pull', [SyncController::class, 'pull']);
            Route::post('push', [SyncController::class, 'push']);
            Route::post('resolve-conflicts', [SyncController::class, 'resolveConflicts']);
            Route::get('status', [SyncController::class, 'status']);
            Route::post('heartbeat', [SyncController::class, 'heartbeat']);
        });

        // Server-to-Server Sync routes (Edge ↔ Cloud sync)
        Route::prefix('server-sync')->group(function () {
            // Edge server endpoints (called by edge servers)
            Route::post('push', [\App\Http\Controllers\ServerSyncController::class, 'pushToCloud']);
            Route::post('pull', [\App\Http\Controllers\ServerSyncController::class, 'pullFromCloud']);
            Route::get('status', [\App\Http\Controllers\ServerSyncController::class, 'status']);
            Route::get('health', [\App\Http\Controllers\ServerSyncController::class, 'health']);

            // Cloud server endpoints (called by cloud server or for cloud server)
            Route::post('receive', [\App\Http\Controllers\ServerSyncController::class, 'receiveFromEdge']);
            Route::post('provide-changes', [\App\Http\Controllers\ServerSyncController::class, 'provideChangesToEdge']);
        });
    });
});
