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
        Schema::table('inventory_transactions', function (Blueprint $table) {
            // Add location-specific quantity tracking
            $table->integer('shelf_quantity')->default(0)->after('quantity');
            $table->integer('store_quantity')->default(0)->after('shelf_quantity');
            $table->integer('shelf_quantity_before')->default(0)->after('quantity_before');
            $table->integer('store_quantity_before')->default(0)->after('shelf_quantity_before');
            $table->integer('shelf_quantity_after')->default(0)->after('quantity_after');
            $table->integer('store_quantity_after')->default(0)->after('shelf_quantity_after');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'shelf_quantity', 'store_quantity',
                'shelf_quantity_before', 'store_quantity_before',
                'shelf_quantity_after', 'store_quantity_after'
            ]);
        });
    }
};
