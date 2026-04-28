<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('shift_id')
                ->nullable()
                ->after('sale_id')
                ->constrained('sales_shifts')
                ->onDelete('set null');

            $table->index('shift_id');
        });

        // Backfill shift_id for existing payments using their sale's shift_id (portable across MySQL and SQLite).
        DB::statement('UPDATE payments
            SET shift_id = (SELECT shift_id FROM sales WHERE sales.id = payments.sale_id)
            WHERE shift_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['shift_id']);
            $table->dropConstrainedForeignId('shift_id');
        });
    }
};
