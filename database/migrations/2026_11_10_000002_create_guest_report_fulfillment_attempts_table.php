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
        Schema::create('guest_report_fulfillment_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('guest_report_purchase_id')
                ->constrained('guest_report_purchases')
                ->restrictOnDelete();

            $table->char('attempt_key', 64);
            $table->string('kind', 32);
            $table->string('origin', 10);
            $table->string('status', 20);
            $table->unsignedBigInteger('generation');

            $table->uuid('dispatch_uuid')->nullable();
            $table->uuid('claim_uuid')
                ->nullable()
                ->unique('guest_report_fulfillment_attempts_claim_uuid_unique');

            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();

            $table->boolean('entitlement_used')->default(false);
            $table->text('failure_reason')->nullable();
            $table->string('queue_job_id', 255)->nullable();

            $table->timestamps();

            $table->unique(
                ['guest_report_purchase_id', 'attempt_key'],
                'guest_report_fulfillment_attempts_purchase_attempt_key_unique'
            );

            $table->index(
                ['guest_report_purchase_id', 'generation', 'status'],
                'idx_grfa_purchase_generation_status'
            );

            $table->index(
                ['guest_report_purchase_id', 'dispatch_uuid'],
                'idx_grfa_purchase_dispatch'
            );

            $table->index('status', 'idx_grfa_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_report_fulfillment_attempts');
    }
};
