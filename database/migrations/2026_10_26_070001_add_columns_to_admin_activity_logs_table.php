<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_activity_logs', function (Blueprint $table) {
            $table->string('target_type')->nullable()->after('action');
            $table->unsignedBigInteger('target_id')->nullable()->after('target_type');
            $table->text('reason')->nullable()->after('target_id');
            $table->string('outcome', 30)->default('success')->after('reason');
            $table->index(['target_type', 'target_id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::table('admin_activity_logs', function (Blueprint $table) {
            $table->dropIndex(['target_type', 'target_id']);
            $table->dropIndex(['action']);
            $table->dropColumn(['target_type', 'target_id', 'reason', 'outcome']);
        });
    }
};
