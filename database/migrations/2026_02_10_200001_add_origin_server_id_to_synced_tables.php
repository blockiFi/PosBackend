<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add origin_server_id to track which server created the record
        Schema::table('sales', function (Blueprint $table) {
            $table->string('origin_server_id')->nullable()->after('branch_id')->index();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('origin_server_id')->nullable()->after('business_id')->index();
        });

        // Add version column for conflict detection if not exists
        if (!Schema::hasColumn('sales', 'version')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->integer('version')->default(1)->after('status');
            });
        }

        if (!Schema::hasColumn('customers', 'version')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->integer('version')->default(1)->after('business_id');
            });
        }

        if (!Schema::hasColumn('products', 'version')) {
            Schema::table('products', function (Blueprint $table) {
                $table->integer('version')->default(1)->after('status');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['origin_server_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['origin_server_id']);
        });
    }
};
