<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SKUs only need to be unique inside a business (like barcodes). The old index was
     * globally unique, so no existing rows can violate the narrower composite index.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_sku_unique');
            $table->unique(['business_id', 'sku'], 'products_business_sku_unique');
        });
    }

    /**
     * Reverse the migrations. Fails if two businesses have since reused the same SKU.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_business_sku_unique');
            $table->unique('sku', 'products_sku_unique');
        });
    }
};
