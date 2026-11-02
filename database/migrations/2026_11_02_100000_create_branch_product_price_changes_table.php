<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every price a branch item has had, so a sale uploaded late (till was
     * offline) can be recorded at the price the till charged, not today's.
     */
    public function up(): void
    {
        // Staging and production share one database and can deploy at the
        // same time: whichever runs second finds the table already there
        if (Schema::hasTable('branch_product_price_changes')) {
            return;
        }

        Schema::create('branch_product_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_product_id')->constrained('branch_products')->cascadeOnDelete();
            // Price the till charges (with any promotion discount)
            $table->decimal('price', 15, 2);
            // Price before the discount
            $table->decimal('full_price', 15, 2);
            $table->timestamp('effective_from');
            $table->timestamps();

            $table->index(['branch_product_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_product_price_changes');
    }
};
