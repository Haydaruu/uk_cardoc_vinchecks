<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('guest_report_purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('fulfillment_generation')->default(1);

            $table->uuid('fulfillment_dispatch_uuid')
                ->nullable()
                ->unique('guest_report_purchases_dispatch_uuid_unique');

            $table->timestamp('fulfillment_dispatch_expires_at')->nullable();

            $table->uuid('fulfillment_claim_uuid')
                ->nullable()
                ->unique('guest_report_purchases_claim_uuid_unique');

            $table->timestamp('fulfillment_claim_expires_at')->nullable();

            $table->unsignedSmallInteger('fulfillment_generation_attempts')->default(0);
            $table->unsignedSmallInteger('fulfillment_recovery_attempts')->default(0);

            $table->uuid('fulfillment_recovery_claim_entitlement_uuid')->nullable();

            $table->timestamp('fulfillment_recovery_exhausted_at')->nullable();
            $table->timestamp('fulfillment_manual_review_at')->nullable();
            $table->text('fulfillment_failure_reason')->nullable();

            $table->unsignedSmallInteger('fulfillment_dispatch_push_slots_allocated')->default(0);

            $table->index(
                ['fulfillment_status', 'fulfillment_dispatch_expires_at'],
                'idx_grp_fulfillment_dispatch_expiry'
            );

            $table->index(
                ['fulfillment_status', 'fulfillment_claim_expires_at'],
                'idx_grp_fulfillment_claim_expiry'
            );
        });

        // Conservative backfill: historical A is treated as consumed budget.
        // A_g starts equal to A. Historical A is never reconstructed or clamped.
        DB::table('guest_report_purchases')->update([
            'fulfillment_generation' => 1,
            'fulfillment_generation_attempts' => DB::raw('fulfillment_attempts'),
            'fulfillment_recovery_attempts' => 0,
            'fulfillment_dispatch_push_slots_allocated' => 0,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_report_purchases', function (Blueprint $table) {
            $table->dropIndex('idx_grp_fulfillment_dispatch_expiry');
            $table->dropIndex('idx_grp_fulfillment_claim_expiry');
            $table->dropUnique('guest_report_purchases_dispatch_uuid_unique');
            $table->dropUnique('guest_report_purchases_claim_uuid_unique');

            $table->dropColumn([
                'fulfillment_generation',
                'fulfillment_dispatch_uuid',
                'fulfillment_dispatch_expires_at',
                'fulfillment_claim_uuid',
                'fulfillment_claim_expires_at',
                'fulfillment_generation_attempts',
                'fulfillment_recovery_attempts',
                'fulfillment_recovery_claim_entitlement_uuid',
                'fulfillment_recovery_exhausted_at',
                'fulfillment_manual_review_at',
                'fulfillment_failure_reason',
                'fulfillment_dispatch_push_slots_allocated',
            ]);
        });
    }
};
