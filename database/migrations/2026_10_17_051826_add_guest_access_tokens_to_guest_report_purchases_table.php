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
            $table->char('access_token_hash', 64)
                ->nullable()
                ->unique();

            $table->text('access_token_encrypted')
                ->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_report_purchases', function (Blueprint $table) {
            $table->dropUnique(['access_token_hash']);

            $table->dropColumn([
                'access_token_hash',
                'access_token_encrypted',
            ]);
        });
    }
};
