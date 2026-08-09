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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('vin')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->integer('year')->nullable();
            $table->string('registration_number')->index()->nullable();
            $table->string('tax_status')->nullable();
            $table->date('mot_expiry_date')->nullable();
            $table->string('colour')->nullable();
            $table->string('fuel_type')->nullable();
            $table->integer('engine_capacity')->nullable();
            $table->integer('co2_emissions')->nullable();
            $table->integer('year_of_manufacture')->nullable();
            $table->boolean('outstanding_finance')->default(false);
            $table->string('write_off_category')->nullable();
            $table->timestamp('last_refreshed_at')->index()->nullable();
            $table->json('raw_api_response')->nullable();
            $table->timestamps(); // created_at & updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
