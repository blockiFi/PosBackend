<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->string('currency', 3)->nullable();
            $table->decimal('last_unit_cost', 15, 2)->nullable();
            $table->timestamp('last_received_at')->nullable();

            $table->decimal('avg_unit_cost', 15, 4)->nullable();
            $table->unsignedInteger('receipt_count')->default(0);

            $table->timestamps();

            $table->unique(['business_id', 'supplier_id', 'product_id'], 'supp_prod_price_unique');
            $table->index(['business_id', 'supplier_id']);
            $table->index(['business_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_prices');
    }
};
