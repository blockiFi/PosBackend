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
        Schema::table('refund_requests', function (Blueprint $table) {
            $table->string('refund_scope', 20)->default('whole_sale')->after('branch_id');
        });

        Schema::create('refund_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_request_id')->constrained('refund_requests')->onDelete('cascade');
            $table->foreignId('sale_item_id')->constrained('sale_items')->onDelete('cascade');
            $table->decimal('quantity', 10, 2);
            $table->timestamps();
            $table->index('refund_request_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('refunded_amount', 10, 2)->default(0)->after('refunded_at');
        });

        // Backfill: sales already marked refunded get refunded_amount = total_amount
        DB::table('sales')->where('is_refunded', true)->update([
            'refunded_amount' => DB::raw('total_amount'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('refunded_amount');
        });
        Schema::dropIfExists('refund_request_items');
        Schema::table('refund_requests', function (Blueprint $table) {
            $table->dropColumn('refund_scope');
        });
    }
};
