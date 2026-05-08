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
        Schema::table('product_batches', function (Blueprint $table) {
            $table
                ->foreignId('goods_received_note_line_id')
                ->nullable()
                ->after('inventory_transaction_id')
                ->constrained('goods_received_note_lines')
                ->nullOnDelete();

            $table->index('goods_received_note_line_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('goods_received_note_line_id');
        });
    }
};
