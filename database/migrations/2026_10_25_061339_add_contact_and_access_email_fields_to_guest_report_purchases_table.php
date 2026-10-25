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
            $table->string('name', 150)->nullable();
            $table->string('phone', 32)->nullable();
            $table->timestamp('access_email_sent_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_report_purchases', function (Blueprint $table) {
             $table->dropColumn([
                'name',
                'phone',
                'access_email_sent_at',
            ]);
        });
    }
};
