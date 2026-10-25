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
        Schema::create('guest_report_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->restrictOnDelete();
            $table->string('email')->index();
            $table->string('payment_status', 20)->default('pending')->index();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_report_purchases');
    }
};
