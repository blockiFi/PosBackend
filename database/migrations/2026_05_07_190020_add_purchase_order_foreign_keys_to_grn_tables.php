<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_received_notes', function (Blueprint $table) {
            $table->foreign('purchase_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->nullOnDelete();
        });

        Schema::table('goods_received_note_lines', function (Blueprint $table) {
            $table->foreign('purchase_order_line_id')
                ->references('id')
                ->on('purchase_order_lines')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('goods_received_note_lines', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_line_id']);
        });

        Schema::table('goods_received_notes', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
        });
    }
};
