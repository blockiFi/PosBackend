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
        Schema::create('branch_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            
            // Branch-Specific Pricing
            $table->decimal('cost_price', 15, 2)->nullable(); // Override base cost
            $table->decimal('selling_price', 15, 2)->nullable(); // Override base selling price
            $table->decimal('compare_price', 15, 2)->nullable(); // Original price for discount display
            
            // Branch-Specific Discounts
            $table->decimal('discount_amount', 15, 2)->nullable();
            $table->enum('discount_type', ['fixed', 'percentage'])->nullable();
            
            // Branch-Specific Tax (Override product default)
            $table->decimal('tax_rate', 5, 2)->nullable();
            
            // Branch-Specific Inventory
            $table->integer('stock_quantity')->default(0);
            $table->integer('low_stock_threshold')->nullable(); // Override product default
            $table->boolean('allow_backorder')->default(false);
            $table->integer('reorder_point')->nullable();
            $table->integer('reorder_quantity')->nullable();
            
            // Branch-Specific Availability & Display
            $table->boolean('is_available')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->integer('display_order')->default(0);
            
            // Branch-Specific Location
            $table->string('bin_location')->nullable(); // Warehouse location
            $table->string('shelf_location')->nullable();
            
            // Additional Branch-Specific Data
            $table->json('branch_meta_data')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            // Unique constraint - one product per branch
            $table->unique(['branch_id', 'product_id']);
            
            // Indexes
            $table->index(['branch_id', 'is_available']);
            $table->index(['branch_id', 'is_featured']);
            $table->index(['branch_id', 'stock_quantity']);
            $table->index(['product_id', 'branch_id']);
            $table->index('selling_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_products');
    }
};
