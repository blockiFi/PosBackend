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
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('batch_number')->nullable(); // Internal batch tracking number
            $table->string('lot_number')->nullable(); // Supplier lot number
            $table->date('manufacturing_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->integer('received_quantity')->default(0); // Original quantity received
            $table->integer('current_quantity')->default(0); // Remaining quantity
            $table->decimal('unit_cost', 15, 2)->nullable(); // Cost per unit for this batch
            $table->string('supplier_name')->nullable();
            $table->string('supplier_reference')->nullable(); // Invoice/PO number
            $table->foreignId('inventory_transaction_id')->nullable()->constrained('inventory_transactions')->onDelete('set null'); // Original purchase transaction
            $table->enum('status', ['active', 'depleted', 'expired', 'recalled'])->default('active');
            $table->json('meta_data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['business_id', 'branch_id', 'product_id']);
            $table->index(['expiry_date', 'status']);
            $table->index(['batch_number']);
            $table->index(['lot_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_batches');
    }
};
