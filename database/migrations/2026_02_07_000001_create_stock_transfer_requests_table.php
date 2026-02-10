<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number')->unique();
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_product_id')->constrained()->onDelete('cascade');
            
            // Request details
            $table->integer('quantity_requested');
            $table->text('reason')->nullable();
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            
            // State management
            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
                'confirmed',
                'cancelled'
            ])->default('pending');
            
            // Audit trail - Who did what
            $table->foreignId('requested_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('requested_at');
            
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('confirmed_at')->nullable();
            $table->text('confirmation_notes')->nullable();
            
            // Actual transferred quantity (may differ from requested)
            $table->integer('quantity_transferred')->nullable();
            
            // Concurrency control
            $table->integer('version')->default(1);
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index(['business_id', 'status']);
            $table->index(['branch_id', 'status']);
            $table->index(['requested_by', 'status']);
            $table->index('request_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_requests');
    }
};
