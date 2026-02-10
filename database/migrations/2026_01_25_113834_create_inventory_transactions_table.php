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
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // who made the transaction
            
            // Transaction details
            $table->enum('type', [
                'purchase',      // Stock received from supplier
                'sale',          // Stock sold to customer
                'adjustment',    // Manual stock adjustment (count, correction)
                'transfer_out',  // Stock transferred to another branch
                'transfer_in',   // Stock received from another branch
                'return',        // Customer return (increases stock)
                'damage',        // Damaged/expired stock (decreases stock)
                'initial'        // Initial stock setup
            ]);
            
            $table->integer('quantity'); // Positive for stock in, negative for stock out
            $table->integer('quantity_before')->default(0); // Stock level before transaction
            $table->integer('quantity_after')->default(0); // Stock level after transaction
            
            $table->decimal('unit_cost', 10, 2)->nullable(); // Cost per unit
            $table->decimal('total_cost', 10, 2)->nullable(); // Total transaction cost
            
            // Related records
            $table->foreignId('related_branch_id')->nullable()->constrained('branches')->nullOnDelete(); // For transfers
            $table->foreignId('related_transaction_id')->nullable()->constrained('inventory_transactions')->nullOnDelete(); // Link transfer pairs
            $table->string('reference_number')->nullable(); // PO number, invoice number, etc.
            
            $table->text('notes')->nullable();
            $table->json('meta_data')->nullable(); // supplier info, reason, etc.
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index(['business_id', 'branch_id', 'product_id']);
            $table->index(['type', 'created_at']);
            $table->index('reference_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};
