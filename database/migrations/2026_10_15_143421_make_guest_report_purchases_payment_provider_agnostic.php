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
        Schema::table('guest_report_purchases', function (Blueprint $table) {
            $table->dropUnique(
                'guest_report_purchases_stripe_checkout_session_id_unique'
            );
            $table->dropUnique(
                'guest_report_purchases_stripe_payment_intent_id_unique'
            );
        });

        Schema::table('guest_report_purchases', function (Blueprint $table) {
            $table->renameColumn(
                'stripe_checkout_session_id',
                'provider_order_id'
            );

            $table->renameColumn(
                'stripe_payment_intent_id',
                'provider_payment_id'
            );
        });

        Schema::table('guest_report_purchases', function (Blueprint $table) {
            $table->string('payment_provider', 20)->default('stripe');

            $table->unique(
                ['payment_provider', 'provider_order_id'],
                'guest_report_purchases_provider_order_unique'
            );

            $table->unique(
                ['payment_provider', 'provider_payment_id'],
                'guest_report_purchases_provider_payment_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_report_purchases', function (Blueprint $table) {
            $table->dropUnique(
                'guest_report_purchases_provider_order_unique'
            );

            $table->dropUnique(
                'guest_report_purchases_provider_payment_unique'
            );

            $table->dropColumn('payment_provider');
        });

        Schema::table('guest_report_purchases', function (Blueprint $table) {
            $table->renameColumn(
                'provider_order_id',
                'stripe_checkout_session_id'
            );

            $table->renameColumn(
                'provider_payment_id',
                'stripe_payment_intent_id'
            );
        });

        Schema::table('guest_report_purchases', function (Blueprint $table) {
            $table->unique(
                'stripe_checkout_session_id',
                'guest_report_purchases_stripe_checkout_session_id_unique'
            );

            $table->unique(
                'stripe_payment_intent_id',
                'guest_report_purchases_stripe_payment_intent_id_unique'
            );
        });
    }
};
