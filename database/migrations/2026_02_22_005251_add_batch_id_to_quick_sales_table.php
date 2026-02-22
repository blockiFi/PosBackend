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
        Schema::table('quick_sales', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('branch_id')->constrained('product_batches')->onDelete('cascade');
            $table->index(['product_id', 'branch_id', 'batch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quick_sales', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'branch_id', 'batch_id', 'status']);
            $table->dropForeign(['batch_id']);
        });
    }
};
