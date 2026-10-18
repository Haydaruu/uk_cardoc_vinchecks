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
        Schema::create('guest_report_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_report_id')->constrained('reports')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['guest_report_id', 'revoked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_report_access_tokens');
    }
};
