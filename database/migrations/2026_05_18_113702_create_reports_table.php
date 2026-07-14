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
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vin_check_id')->nullable()->constrained('vin_checks')->cascadeOnDelete();
            $table->string('vin')->nullable();
            $table->json('report_data')->nullable();
            $table->enum('report_type', ['basic', 'premium', 'full'])->nullable();
            $table->string('file_path')->nullable();
            $table->integer('download_count')->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps(); // created_at & updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
