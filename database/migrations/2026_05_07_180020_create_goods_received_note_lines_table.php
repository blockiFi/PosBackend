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
        Schema::create('goods_received_note_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('goods_received_note_id')
                ->constrained('goods_received_notes')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('purchase_order_line_id')->nullable(); // Phase 2 FK

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('branch_product_id')->constrained('branch_products')->cascadeOnDelete();

            $table->decimal('quantity_ordered', 15, 3)->nullable();
            $table->decimal('quantity_received', 15, 3)->default(0);
            $table->decimal('quantity_accepted', 15, 3)->default(0);
            $table->decimal('quantity_rejected', 15, 3)->default(0);
            $table->text('rejection_reason')->nullable();

            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->decimal('line_total', 15, 2)->nullable();

            // Batch / lot metadata (required for batched products at validation time)
            $table->string('batch_number')->nullable();
            $table->string('lot_number')->nullable();
            $table->date('manufacturing_date')->nullable();
            $table->date('expiry_date')->nullable();

            $table->enum('storage_location', ['shelf', 'store'])->default('store');

            $table->foreignId('inventory_transaction_id')->nullable()->constrained('inventory_transactions')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->json('meta_data')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['goods_received_note_id']);
            $table->index(['product_id', 'branch_product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_received_note_lines');
    }
};
