<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_sync_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id')->unique();
            $table->string('server_id')->index();
            $table->enum('direction', ['push', 'pull', 'receive', 'provide'])->index();
            $table->enum('status', ['success', 'failed', 'partial'])->default('success');
            $table->integer('records_sent')->default(0);
            $table->integer('records_received')->default(0);
            $table->integer('records_accepted')->default(0);
            $table->integer('records_rejected')->default(0);
            $table->integer('records_applied')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
            $table->index(['direction', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_sync_sessions');
    }
};
