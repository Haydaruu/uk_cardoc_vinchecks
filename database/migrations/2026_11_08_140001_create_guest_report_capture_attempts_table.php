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
        Schema::create('guest_report_capture_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_report_purchase_id')
                ->constrained('guest_report_purchases')
                ->restrictOnDelete();
            $table->uuid('attempt_uuid')->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->string('provider_order_id', 64);
            $table->string('status', 32)->default('initiated');
            $table->integer('attempt_number')->default(1);
            $table->text('failure_reason')->nullable();
            $table->timestamp('request_started_at')->nullable();
            $table->timestamps();

            $table->index(['provider_order_id', 'status'], 'idx_guest_capture_attempts_order');
            $table->index(['guest_report_purchase_id', 'attempt_number'], 'idx_guest_capture_attempts_purchase');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_report_capture_attempts');
    }
};
