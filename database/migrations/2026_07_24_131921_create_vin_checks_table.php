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
        Schema::create('vin_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('registration_number')->nullable()->index();
            $table->string('data_source')->nullable();
            $table->string('vin')->unique()->nullable();
            $table->enum('check_type', ['free', 'premium'])->nullable();
            $table->string('ip_address')->nullable();
            $table->enum('status', ['pending', 'success', 'failed'])->nullable();
            $table->string('stage')->nullable();
            $table->timestamp('cached_until')->nullable();
            $table->timestamps(); //created_at & updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vin_checks');
    }
};
