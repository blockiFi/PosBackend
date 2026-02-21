<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds pausable support: 'paused' status and paused_at timestamp.
     */
    public function up(): void
    {
        Schema::table('sales_shifts', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('status');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sales_shifts MODIFY COLUMN status ENUM('open', 'paused', 'closed') DEFAULT 'open'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sales_shifts MODIFY COLUMN status ENUM('open', 'closed') DEFAULT 'open'");
        }

        Schema::table('sales_shifts', function (Blueprint $table) {
            $table->dropColumn('paused_at');
        });
    }
};
