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
        Schema::create('sync_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 36)->unique();
            $table->foreignId('device_id')->constrained('device_registrations')->onDelete('cascade');
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('direction', ['pull', 'push', 'bidirectional'])->default('bidirectional');
            $table->enum('status', ['initiated', 'in_progress', 'completed', 'failed', 'partial'])->default('initiated');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('records_pushed')->default(0);
            $table->unsignedInteger('records_pulled')->default(0);
            $table->unsignedInteger('conflicts_detected')->default(0);
            $table->unsignedInteger('conflicts_resolved')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->json('summary')->nullable(); // detailed stats per entity type
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status']);
            $table->index(['business_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_sessions');
    }
};
