<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_leads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vin_check_id')
                ->constrained('vin_checks')
                ->cascadeOnDelete();

            $table->index('vin_check_id');

            $table->foreignId('report_id')
                ->constrained('reports')
                ->cascadeOnDelete();

            $table->string('email', 254);
            $table->string('phone', 32)->nullable();

            $table->timestamp('captured_at')->useCurrent();

            $table->timestamps();

            // One lead per vehicle check and one lead per report.
            $table->unique('report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_leads');
    }
};
