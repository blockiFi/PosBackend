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
        Schema::create('change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
            $table->string('entity_type', 50); // sales, products, customers, etc.
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_uuid', 36)->nullable();
            $table->enum('action', ['created', 'updated', 'deleted'])->default('updated');
            $table->unsignedBigInteger('version'); // version after this change
            $table->string('device_id', 50)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->json('changes')->nullable(); // old_value => new_value for updates
            $table->timestamp('changed_at');
            $table->boolean('synced')->default(false);
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['entity_uuid']);
            $table->index(['business_id', 'changed_at']);
            $table->index(['synced', 'changed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('change_logs');
    }
};
