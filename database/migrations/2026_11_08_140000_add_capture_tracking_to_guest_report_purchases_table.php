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
            $table->string('capture_status', 32)->nullable();
            $table->integer('capture_attempts')->default(0);
            $table->timestamp('capture_request_started_at')->nullable();
            $table->text('capture_failure_reason')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('last_reconciliation_dispatched_at')->nullable();
            $table->uuid('active_capture_attempt_uuid')->nullable();

            $table->index(
                ['payment_provider', 'payment_status', 'capture_status', 'last_reconciled_at'],
                'idx_guest_purchases_capture_reconciliation'
            );

            $table->index(
                ['payment_provider', 'capture_status', 'last_reconciliation_dispatched_at'],
                'idx_guest_purchases_stale_dispatch'
            );
        });

        // ── Conservative Backfill (Zero Synthetic Timestamps) ──

        // 1. Historical Paid PayPal records WITH proven provider_payment_id
        DB::table('guest_report_purchases')
            ->where('payment_provider', 'paypal')
            ->where('payment_status', 'paid')
            ->whereNotNull('provider_payment_id')
            ->whereNull('capture_status')
            ->update([
                'capture_status' => 'completed',
                'capture_attempts' => 1,
            ]);

        // 2. Historical Paid PayPal records WITHOUT provider_payment_id (requires audit)
        DB::table('guest_report_purchases')
            ->where('payment_provider', 'paypal')
            ->where('payment_status', 'paid')
            ->whereNull('provider_payment_id')
            ->whereNull('capture_status')
            ->update([
                'capture_status' => 'capture_reconciliation_required',
                'capture_attempts' => 1,
                'capture_failure_reason' => 'Legacy paid record missing verified provider payment ID.',
            ]);

        // 3. Historical Failed PayPal records
        DB::table('guest_report_purchases')
            ->where('payment_provider', 'paypal')
            ->where('payment_status', 'failed')
            ->whereNull('capture_status')
            ->update([
                'capture_status' => 'failed',
                'capture_attempts' => 1,
            ]);

        // 4. Historical Expired PayPal records
        DB::table('guest_report_purchases')
            ->where('payment_provider', 'paypal')
            ->where('payment_status', 'expired')
            ->whereNull('capture_status')
            ->update([
                'capture_status' => 'failed',
                'capture_attempts' => 1,
                'capture_failure_reason' => 'Purchase expired before capture.',
            ]);

        // 5. Historical Refunded PayPal records
        DB::table('guest_report_purchases')
            ->where('payment_provider', 'paypal')
            ->where('payment_status', 'refunded')
            ->whereNull('capture_status')
            ->update([
                'capture_status' => 'completed',
                'capture_attempts' => 1,
            ]);

        // 6. Historical Pre-Phase-3 Pending PayPal records: assign 'legacy_untracked'
        // Timestamps remain strictly NULL (no fabrication)
        DB::table('guest_report_purchases')
            ->where('payment_provider', 'paypal')
            ->where('payment_status', 'pending')
            ->whereNull('capture_status')
            ->update([
                'capture_status' => 'legacy_untracked',
                'capture_attempts' => 0,
                'capture_request_started_at' => null,
                'last_reconciled_at' => null,
                'last_reconciliation_dispatched_at' => null,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_report_purchases', function (Blueprint $table) {
            $table->dropIndex('idx_guest_purchases_capture_reconciliation');
            $table->dropIndex('idx_guest_purchases_stale_dispatch');

            $table->dropColumn([
                'capture_status',
                'capture_attempts',
                'capture_request_started_at',
                'capture_failure_reason',
                'last_reconciled_at',
                'last_reconciliation_dispatched_at',
                'active_capture_attempt_uuid',
            ]);
        });
    }
};
