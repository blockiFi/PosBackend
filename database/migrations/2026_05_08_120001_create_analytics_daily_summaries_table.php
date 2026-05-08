<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('sale_date');
            $table->unsignedInteger('txn_count')->default(0);
            $table->unsignedInteger('items_sold')->default(0);
            $table->decimal('revenue', 18, 2)->default(0);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('cost', 18, 2)->default(0);
            $table->decimal('profit', 18, 2)->default(0);
            $table->timestamp('computed_at')->useCurrent();
            $table->unique(['business_id', 'branch_id', 'sale_date']);
            $table->index(['business_id', 'sale_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_daily_summaries');
    }
};
