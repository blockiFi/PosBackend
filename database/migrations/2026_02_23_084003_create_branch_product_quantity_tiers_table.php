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
        Schema::create('branch_product_quantity_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_product_id')->constrained('branch_products')->onDelete('cascade');
            $table->unsignedInteger('min_quantity');
            $table->unsignedInteger('max_quantity')->nullable(); // null = no upper limit
            $table->decimal('price_per_unit', 15, 2);
            $table->timestamps();

            $table->index(['branch_product_id', 'min_quantity'], 'bp_quantity_tiers_bp_min_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_product_quantity_tiers');
    }
};
