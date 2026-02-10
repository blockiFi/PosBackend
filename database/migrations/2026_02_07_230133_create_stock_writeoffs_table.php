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
        Schema::create('stock_writeoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_product_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('sku')->index();
            $table->integer('quantity');
            $table->text('reason');
            $table->foreignId('written_off_by')->constrained('users')->onDelete('restrict');
            $table->timestamp('written_off_at');
            $table->timestamps();
            $table->softDeletes();

            // Indexes for common queries
            $table->index(['business_id', 'branch_id', 'written_off_at']);
            $table->index(['business_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_writeoffs');
    }
};
