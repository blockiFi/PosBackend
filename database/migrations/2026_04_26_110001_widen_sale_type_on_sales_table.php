<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sales MODIFY COLUMN sale_type VARCHAR(32) NOT NULL DEFAULT 'pos'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sales MODIFY COLUMN sale_type ENUM('pos','online','delivery','wholesale') NOT NULL DEFAULT 'pos'");
        }
    }
};
