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
        Schema::create('goods_received_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            $table->string('grn_number')->unique();

            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->unsignedBigInteger('purchase_order_id')->nullable(); // Phase 2 FK

            $table->enum('status', [
                'draft',
                'pending_approval',
                'posted',
                'rejected',
                'cancelled',
            ])->default('draft');

            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            $table->string('supplier_invoice_number')->nullable();
            $table->date('supplier_invoice_date')->nullable();

            $table->string('currency', 3)->nullable();
            $table->decimal('subtotal', 15, 2)->nullable();
            $table->decimal('tax_amount', 15, 2)->nullable();
            $table->decimal('freight', 15, 2)->nullable();
            $table->decimal('other_charges', 15, 2)->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();

            $table->text('notes')->nullable();

            // Offline/idempotency support (Phase 4)
            $table->string('device_id')->nullable();
            $table->uuid('client_uuid')->nullable();

            $table->json('meta_data')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'client_uuid']);
            $table->index(['business_id', 'branch_id', 'status']);
            $table->index(['business_id', 'supplier_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_received_notes');
    }
};
