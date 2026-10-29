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
        Schema::create('refund_records', function (Blueprint $table) {
            $table->id();
            $table->string('refundable_type');
            $table->unsignedBigInteger('refundable_id');
            $table->foreignId('admin_id')->constrained('users');
            $table->string('gateway', 20);
            $table->string('payment_reference');
            $table->string('refund_gateway_ref')->nullable()->index();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 20)->default('processing')->index();
            $table->string('idempotency_key')->unique();
            $table->text('reason');
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['refundable_type', 'refundable_id']);
        });

        $driver = DB::getDriverName();

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX refund_records_single_active_unique ON refund_records (refundable_type, refundable_id) WHERE status IN (\'processing\', \'success\', \'unknown\')'
            );
        } else {
            throw new RuntimeException(
                "Unsupported database driver for partial unique index: {$driver}. Only 'pgsql' and 'sqlite' are supported."
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS refund_records_single_active_unique');
        }

        Schema::dropIfExists('refund_records');
    }
};
