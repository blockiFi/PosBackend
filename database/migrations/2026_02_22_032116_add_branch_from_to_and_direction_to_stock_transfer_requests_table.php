<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stock_transfer_requests', function (Blueprint $table) {
            $table->foreignId('branch_from_id')->nullable()->after('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('branch_to_id')->nullable()->after('branch_from_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('transfer_out_request_id')->nullable()->after('branch_to_id')->constrained('stock_transfer_requests')->onDelete('cascade');
            $table->string('direction', 10)->default('out')->after('transfer_out_request_id');
        });

        // In-requests may not have branch_product_id (receiving branch product created on accept)
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE stock_transfer_requests MODIFY branch_product_id BIGINT UNSIGNED NULL');
        }

        // Backfill: existing rows are "out" requests from branch_id
        DB::table('stock_transfer_requests')->whereNull('branch_from_id')->update([
            'branch_from_id' => DB::raw('branch_id'),
            'direction' => 'out',
        ]);

        // Add new status values (MySQL)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE stock_transfer_requests MODIFY COLUMN status ENUM(
                'pending',
                'approved',
                'rejected',
                'confirmed',
                'cancelled',
                'pending_acceptance',
                'branch_rejected'
            ) DEFAULT 'pending'");
        }

        Schema::table('stock_transfer_requests', function (Blueprint $table) {
            $table->index(['branch_from_id', 'status']);
            $table->index(['branch_to_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_transfer_requests', function (Blueprint $table) {
            $table->dropIndex(['branch_from_id', 'status']);
            $table->dropIndex(['branch_to_id', 'status']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE stock_transfer_requests MODIFY COLUMN status ENUM(
                'pending',
                'approved',
                'rejected',
                'confirmed',
                'cancelled'
            ) DEFAULT 'pending'");
        }

        Schema::table('stock_transfer_requests', function (Blueprint $table) {
            $table->dropForeign(['transfer_out_request_id']);
            $table->dropForeign(['branch_to_id']);
            $table->dropForeign(['branch_from_id']);
        });
    }
};
