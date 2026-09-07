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
            $table->string('pending_plan_name')->nullable();
            $table->string('pending_stripe_price_id')->nullable();
            $table->decimal('pending_price', 12, 2)->nullable();
            $table->unsignedInteger('pending_monthly_credits')->nullable();
            $table->timestamp('pending_plan_effective_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn([
                'pending_plan_name',
                'pending_stripe_price_id',
                'pending_price',
                'pending_monthly_credits',
                'pending_plan_effective_at',
            ]);
        });
    }
};
