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
        Schema::table('device_registrations', function (Blueprint $table) {
            $table->foreignId('group_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('device_groups')
                ->onDelete('set null');

            $table->index(['business_id', 'group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_registrations', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'group_id']);
            $table->dropConstrainedForeignId('group_id');
        });
    }
};
