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
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('stripe_price_id')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('current_period_end')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
             $table->dropUnique('subscriptions_stripe_subscription_id_unique');

            $table->dropColumn([
                'stripe_subscription_id',
                'stripe_price_id',
                'cancel_at_period_end',
                'current_period_end',
            ]);
        });
    }
};
