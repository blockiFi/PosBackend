<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_transactions MODIFY COLUMN type ENUM(
                'purchase',
                'sale',
                'adjustment',
                'transfer_out',
                'transfer_in',
                'return',
                'damage',
                'initial',
                'batch_allocation'
            ) NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_transactions MODIFY COLUMN type ENUM(
                'purchase',
                'sale',
                'adjustment',
                'transfer_out',
                'transfer_in',
                'return',
                'damage',
                'initial'
            ) NOT NULL");
        }
    }
};
