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
        Schema::table('sales_shifts', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_shifts', 'device_id')) {
                $table->string('device_id', 50)->nullable()->after('user_id');
                $table->index('device_id');
            }
            if (! Schema::hasColumn('sales_shifts', 'opening_balance_discrepancy')) {
                $table->decimal('opening_balance_discrepancy', 15, 2)->nullable()->after('resolution_notes');
            }
            if (! Schema::hasColumn('sales_shifts', 'previous_shift_id')) {
                $table->foreignId('previous_shift_id')->nullable()->after('opening_balance_discrepancy')
                    ->constrained('sales_shifts')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_shifts', function (Blueprint $table) {
            if (Schema::hasColumn('sales_shifts', 'previous_shift_id')) {
                $table->dropForeign(['previous_shift_id']);
                $table->dropColumn('previous_shift_id');
            }
            if (Schema::hasColumn('sales_shifts', 'device_id')) {
                $table->dropIndex(['device_id']);
                $table->dropColumn('device_id');
            }
            if (Schema::hasColumn('sales_shifts', 'opening_balance_discrepancy')) {
                $table->dropColumn('opening_balance_discrepancy');
            }
        });
    }
};
