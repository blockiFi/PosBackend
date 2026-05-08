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
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('branch_product_id')->constrained('branch_products')->cascadeOnDelete();

            $table->decimal('quantity_ordered', 15, 3)->default(0);
            $table->decimal('quantity_received', 15, 3)->default(0);

            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->decimal('line_total', 15, 2)->nullable();

            $table->text('notes')->nullable();
            $table->json('meta_data')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['purchase_order_id']);
            $table->index(['product_id', 'branch_product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};
