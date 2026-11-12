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
        Schema::table('processed_webhook_events', function (Blueprint $table) {
            $table->unsignedSmallInteger('retry_count')->default(0)->after('ownership_token');
            $table->timestamp('next_retry_at')->nullable()->after('retry_count')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processed_webhook_events', function (Blueprint $table) {
            $table->dropIndex(['next_retry_at']);
            $table->dropColumn(['retry_count', 'next_retry_at']);
        });
    }
};
