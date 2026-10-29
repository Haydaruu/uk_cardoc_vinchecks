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
        $driver = DB::getDriverName();

        if ($driver !== 'pgsql' && $driver !== 'sqlite') {
            throw new RuntimeException(
                "Unsupported database driver for processed_webhook_events: {$driver}. Only 'pgsql' and 'sqlite' are supported."
            );
        }

        Schema::create('processed_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_id', 255);
            $table->string('event_type', 100);
            $table->string('status', 20)->default('processing')->index();
            $table->text('error_message')->nullable();
            $table->json('payload_summary')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id'], 'processed_webhook_events_unique');
            $table->index(['provider', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processed_webhook_events');
    }
};
