<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow fractional stock/sale deductions (e.g. 10.5 kg).
     */
    public function up(): void
    {
        Schema::table('branch_products', function (Blueprint $table) {
            $table->decimal('stock_quantity', 12, 3)->default(0)->change();
            $table->decimal('shelf_quantity', 12, 3)->default(0)->change();
            $table->decimal('store_quantity', 12, 3)->default(0)->change();
            $table->decimal('reorder_quantity', 12, 3)->nullable()->change();
        });

        Schema::table('product_batches', function (Blueprint $table) {
            $table->decimal('received_quantity', 12, 3)->default(0)->change();
            $table->decimal('current_quantity', 12, 3)->default(0)->change();
        });

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->change();
            $table->decimal('quantity_before', 12, 3)->default(0)->change();
            $table->decimal('quantity_after', 12, 3)->default(0)->change();
            $table->decimal('shelf_quantity', 12, 3)->default(0)->change();
            $table->decimal('store_quantity', 12, 3)->default(0)->change();
            $table->decimal('shelf_quantity_before', 12, 3)->default(0)->change();
            $table->decimal('store_quantity_before', 12, 3)->default(0)->change();
            $table->decimal('shelf_quantity_after', 12, 3)->default(0)->change();
            $table->decimal('store_quantity_after', 12, 3)->default(0)->change();
        });

        Schema::table('stock_writeoffs', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->change();
        });

        Schema::table('stock_transfer_requests', function (Blueprint $table) {
            $table->decimal('quantity_requested', 12, 3)->change();
            $table->decimal('quantity_transferred', 12, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('branch_products', function (Blueprint $table) {
            $table->integer('stock_quantity')->default(0)->change();
            $table->integer('shelf_quantity')->default(0)->change();
            $table->integer('store_quantity')->default(0)->change();
            $table->integer('reorder_quantity')->nullable()->change();
        });

        Schema::table('product_batches', function (Blueprint $table) {
            $table->integer('received_quantity')->default(0)->change();
            $table->integer('current_quantity')->default(0)->change();
        });

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->integer('quantity')->change();
            $table->integer('quantity_before')->default(0)->change();
            $table->integer('quantity_after')->default(0)->change();
            $table->integer('shelf_quantity')->default(0)->change();
            $table->integer('store_quantity')->default(0)->change();
            $table->integer('shelf_quantity_before')->default(0)->change();
            $table->integer('store_quantity_before')->default(0)->change();
            $table->integer('shelf_quantity_after')->default(0)->change();
            $table->integer('store_quantity_after')->default(0)->change();
        });

        Schema::table('stock_writeoffs', function (Blueprint $table) {
            $table->integer('quantity')->change();
        });

        Schema::table('stock_transfer_requests', function (Blueprint $table) {
            $table->integer('quantity_requested')->change();
            $table->integer('quantity_transferred')->nullable()->change();
        });
    }
};
