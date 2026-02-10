<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('branch_products', function (Blueprint $table) {
            // Add new columns for shelf and store quantities
            $table->integer('shelf_quantity')->default(0)->after('stock_quantity');
            $table->integer('store_quantity')->default(0)->after('shelf_quantity');
        });
        
        // Migrate existing stock_quantity to shelf_quantity (assume all existing stock is on shelf)
        DB::statement('UPDATE branch_products SET shelf_quantity = stock_quantity, stock_quantity = stock_quantity WHERE deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_products', function (Blueprint $table) {
            $table->dropColumn(['shelf_quantity', 'store_quantity']);
        });
    }
};
