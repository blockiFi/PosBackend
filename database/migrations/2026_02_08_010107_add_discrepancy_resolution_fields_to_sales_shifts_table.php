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
            $table->boolean('discrepancy_resolved')->default(false)->after('variance');
            $table->timestamp('discrepancy_resolved_at')->nullable()->after('discrepancy_resolved');
            $table->foreignId('discrepancy_resolved_by')->nullable()->constrained('users')->onDelete('set null')->after('discrepancy_resolved_at');
            $table->text('resolution_notes')->nullable()->after('discrepancy_resolved_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_shifts', function (Blueprint $table) {
            $table->dropForeign(['discrepancy_resolved_by']);
            $table->dropColumn(['discrepancy_resolved', 'discrepancy_resolved_at', 'discrepancy_resolved_by', 'resolution_notes']);
        });
    }
};
