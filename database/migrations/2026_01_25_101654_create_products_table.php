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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->onDelete('set null');
            
            // Basic Information (General/Global)
            $table->string('name');
            $table->string('sku')->unique();
            $table->string('barcode')->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            
            // General Pricing (Default/Base prices)
            $table->decimal('base_cost_price', 15, 2)->default(0);
            $table->decimal('base_selling_price', 15, 2);
            
            // Tax Settings (Can be overridden at branch level)
            $table->boolean('is_taxable')->default(true);
            $table->decimal('default_tax_rate', 5, 2)->nullable();
            
            // Units & Measurements (Product characteristics)
            $table->string('unit_of_measure')->nullable(); // e.g., pcs, kg, liters
            $table->decimal('weight', 10, 3)->nullable();
            $table->string('weight_unit')->nullable(); // kg, g, lb, oz
            
            // Product Type & Tracking
            $table->enum('stock_tracking', ['none', 'simple', 'variant'])->default('simple');
            $table->integer('low_stock_threshold')->default(10); // Default threshold
            
            // General Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available_online')->default(false);
            
            // Additional Info
            $table->json('meta_data')->nullable(); // For custom fields
            $table->integer('sort_order')->default(0);
            
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['business_id', 'category_id']);
            $table->index(['business_id', 'is_active']);
            $table->index('sku');
            $table->index('barcode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
