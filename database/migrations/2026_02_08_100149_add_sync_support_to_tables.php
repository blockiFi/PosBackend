<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds sync support fields to existing tables for offline-first operation
     */
    public function up(): void
    {
        // Add sync fields to sales table
        Schema::table('sales', function (Blueprint $table) {
            $table->string('client_uuid', 36)->nullable()->unique()->after('id');
            $table->unsignedBigInteger('version')->default(1)->after('client_uuid');
            $table->string('device_id', 50)->nullable()->after('version');
            $table->timestamp('synced_at')->nullable()->after('device_id');
            $table->enum('sync_status', ['pending', 'synced', 'conflict'])->default('synced')->after('synced_at');
            $table->string('origin', 20)->default('online')->after('sync_status'); // online, offline
            $table->index(['sync_status', 'synced_at']);
            $table->index('device_id');
        });

        // Add sync fields to sale_items table
        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('client_uuid', 36)->nullable()->unique()->after('id');
            $table->unsignedBigInteger('version')->default(1)->after('client_uuid');
            $table->string('device_id', 50)->nullable()->after('version');
            $table->timestamp('synced_at')->nullable()->after('device_id');
            $table->enum('sync_status', ['pending', 'synced', 'conflict'])->default('synced')->after('synced_at');
            $table->string('origin', 20)->default('online')->after('sync_status');
        });

        // Add sync fields to payments table
        Schema::table('payments', function (Blueprint $table) {
            $table->string('client_uuid', 36)->nullable()->unique()->after('id');
            $table->unsignedBigInteger('version')->default(1)->after('client_uuid');
            $table->string('device_id', 50)->nullable()->after('version');
            $table->timestamp('synced_at')->nullable()->after('device_id');
            $table->enum('sync_status', ['pending', 'synced', 'conflict'])->default('synced')->after('synced_at');
            $table->string('origin', 20)->default('online')->after('sync_status');
        });

        // Add sync fields to inventory_transactions table
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->string('client_uuid', 36)->nullable()->unique()->after('id');
            $table->unsignedBigInteger('version')->default(1)->after('client_uuid');
            $table->string('device_id', 50)->nullable()->after('version');
            $table->timestamp('synced_at')->nullable()->after('device_id');
            $table->enum('sync_status', ['pending', 'synced', 'conflict'])->default('synced')->after('synced_at');
            $table->string('origin', 20)->default('online')->after('sync_status');
        });

        // Add sync fields to sales_shifts table
        Schema::table('sales_shifts', function (Blueprint $table) {
            $table->string('client_uuid', 36)->nullable()->unique()->after('shift_number');
            $table->unsignedBigInteger('version')->default(1)->after('client_uuid');
            $table->string('device_id', 50)->nullable()->after('version');
            $table->timestamp('synced_at')->nullable()->after('device_id');
            $table->enum('sync_status', ['pending', 'synced', 'conflict'])->default('synced')->after('synced_at');
            $table->string('origin', 20)->default('online')->after('sync_status');
        });

        // Add sync fields to refund_requests table
        Schema::table('refund_requests', function (Blueprint $table) {
            $table->string('client_uuid', 36)->nullable()->unique()->after('id');
            $table->unsignedBigInteger('version')->default(1)->after('client_uuid');
            $table->string('device_id', 50)->nullable()->after('version');
            $table->timestamp('synced_at')->nullable()->after('device_id');
            $table->enum('sync_status', ['pending', 'synced', 'conflict'])->default('synced')->after('synced_at');
        });

        // Add sync fields to quick_sales table
        Schema::table('quick_sales', function (Blueprint $table) {
            $table->string('client_uuid', 36)->nullable()->unique()->after('id');
            $table->unsignedBigInteger('version')->default(1)->after('client_uuid');
            $table->string('device_id', 50)->nullable()->after('version');
            $table->timestamp('synced_at')->nullable()->after('device_id');
            $table->enum('sync_status', ['pending', 'synced', 'conflict'])->default('synced')->after('synced_at');
        });

        // Add sync fields to customers table
        Schema::table('customers', function (Blueprint $table) {
            $table->string('client_uuid', 36)->nullable()->unique()->after('customer_code');
            $table->unsignedBigInteger('version')->default(1)->after('client_uuid');
            $table->string('device_id', 50)->nullable()->after('version');
            $table->timestamp('synced_at')->nullable()->after('device_id');
            $table->enum('sync_status', ['pending', 'synced', 'conflict'])->default('synced')->after('synced_at');
        });

        // Add sync fields to products table (for price/stock updates)
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('version')->default(1)->after('uuid');
            $table->timestamp('synced_at')->nullable()->after('version');
            $table->index('synced_at');
        });

        // Add sync fields to branch_products table
        Schema::table('branch_products', function (Blueprint $table) {
            $table->unsignedBigInteger('version')->default(1)->after('id');
            $table->timestamp('synced_at')->nullable()->after('version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['sync_status', 'synced_at']);
            $table->dropIndex(['device_id']);
            $table->dropColumn(['client_uuid', 'version', 'device_id', 'synced_at', 'sync_status', 'origin']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['client_uuid', 'version', 'device_id', 'synced_at', 'sync_status']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['client_uuid', 'version', 'device_id', 'synced_at', 'sync_status']);
        });

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropColumn(['client_uuid', 'version', 'device_id', 'synced_at', 'sync_status', 'origin']);
        });

        Schema::table('sales_shifts', function (Blueprint $table) {
            $table->dropColumn(['client_uuid', 'version', 'device_id', 'synced_at', 'sync_status', 'origin']);
        });

        Schema::table('refund_requests', function (Blueprint $table) {
            $table->dropColumn(['client_uuid', 'version', 'device_id', 'synced_at', 'sync_status']);
        });

        Schema::table('quick_sales', function (Blueprint $table) {
            $table->dropColumn(['client_uuid', 'version', 'device_id', 'synced_at', 'sync_status']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['client_uuid', 'version', 'device_id', 'synced_at', 'sync_status']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['synced_at']);
            $table->dropColumn(['version', 'synced_at']);
        });

        Schema::table('branch_products', function (Blueprint $table) {
            $table->dropColumn(['version', 'synced_at']);
        });
    }
};
