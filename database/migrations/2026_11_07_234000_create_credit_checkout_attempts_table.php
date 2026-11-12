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
        Schema::create('credit_checkout_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('intent_reference', 64);
            $table->uuid('attempt_uuid')->unique();
            $table->string('invoice_id', 64)->unique();
            $table->string('plan_slug', 64);
            $table->integer('credits_to_grant');
            $table->integer('amount_minor');
            $table->string('currency', 3)->default('GBP');
            $table->string('provider_order_id', 64)->nullable()->unique();
            $table->string('provider_capture_id', 64)->nullable()->unique();
            $table->string('status', 32)->default('initiated')->index();
            $table->text('failure_reason')->nullable();
            $table->timestamp('provider_request_started_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['user_id', 'intent_reference'], 'uq_credit_checkout_user_intent');
            $table->index(['user_id', 'plan_slug', 'status', 'expires_at'], 'idx_credit_checkout_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_checkout_attempts');
    }
};
