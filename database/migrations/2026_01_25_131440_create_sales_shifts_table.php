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
        Schema::create('sales_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('shift_number')->unique();
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('restrict'); // cashier
            $table->dateTime('start_time');
            $table->dateTime('end_time')->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('expected_cash', 15, 2)->default(0);
            $table->decimal('actual_cash', 15, 2)->nullable();
            $table->decimal('cash_sales', 15, 2)->default(0);
            $table->decimal('card_sales', 15, 2)->default(0);
            $table->decimal('other_sales', 15, 2)->default(0);
            $table->decimal('total_sales', 15, 2)->default(0);
            $table->integer('transactions_count')->default(0);
            $table->decimal('variance', 15, 2)->default(0);
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['business_id', 'branch_id', 'status']);
            $table->index(['user_id', 'start_time']);
            $table->index(['shift_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_shifts');
    }
};
