<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_products', function (Blueprint $table) {
            $table->decimal('last_received_cost', 15, 2)->nullable()->after('cost_price');
            $table->decimal('avg_cost_price', 15, 4)->nullable()->after('last_received_cost');
        });
    }

    public function down(): void
    {
        Schema::table('branch_products', function (Blueprint $table) {
            $table->dropColumn(['last_received_cost', 'avg_cost_price']);
        });
    }
};
