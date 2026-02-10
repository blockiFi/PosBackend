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
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('slug')->nullable()->unique();

            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country', 2)->nullable(); // ISO 3166-1 alpha-2

            $table->string('currency', 3)->default('NGN'); // ISO 4217
            $table->string('time_zone')->nullable();

            $table->string('tax_registration_number')->nullable();
            $table->decimal('default_tax_rate', 5, 2)->default(0); // e.g. 7.50 = 7.5%

            $table->json('settings')->nullable(); // receipt footer, printer, tax settings, etc.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
