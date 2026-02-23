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
        Schema::create('branch_product_unit_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_product_id')->constrained('branch_products')->onDelete('cascade');
            $table->foreignId('product_unit_id')->constrained('product_units')->onDelete('cascade');
            $table->decimal('selling_price', 15, 2);
            $table->timestamps();

            // Use a shorter explicit index name to avoid MySQL's 64-character limit
            $table->unique(['branch_product_id', 'product_unit_id'], 'bp_unit_price_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_product_unit_prices');
    }
};
