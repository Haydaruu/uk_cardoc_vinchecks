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
            $table->string('fulfillment_status', 20)
            ->default('locked')
            ->index();

            $table->unsignedSmallInteger('fulfillment_attempts')
                ->default(0);

            $table->timestamp('fulfillment_started_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_report_purchases', function (Blueprint $table) {
            $table->dropIndex(['fulfillment_status']);

            $table->dropColumn([
                'fulfillment_status',
                'fulfillment_attempts',
                'fulfillment_started_at',
                'fulfilled_at',
            ]);
        });
    }
};
