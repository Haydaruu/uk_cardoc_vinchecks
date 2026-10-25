<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasUnmigratedLegacyTokens = DB::table(
            'guest_report_purchases as purchase'
        )
            ->where(function ($query) {
                $query->whereNotNull('purchase.access_token_hash')
                    ->orWhereNotNull('purchase.access_token_encrypted');
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('guest_report_access_tokens as access_token')
                    ->whereColumn(
                        'access_token.guest_report_id',
                        'purchase.report_id',
                    )
                    ->whereColumn(
                        'access_token.token_hash',
                        'purchase.access_token_hash',
                    );
            })
            ->exists();

        if ($hasUnmigratedLegacyTokens) {
            throw new RuntimeException(
                'Cannot drop legacy guest access token columns: '
                .'some legacy tokens are missing from '
                .'guest_report_access_tokens. Migrate or investigate '
                .'those records first.'
            );
        }

        Schema::table('guest_report_purchases', function (Blueprint $table) {
            $table->dropUnique(['access_token_hash']);

            $table->dropColumn([
                'access_token_hash',
                'access_token_encrypted',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('guest_report_purchases', function (Blueprint $table) {
            $table->char('access_token_hash', 64)->nullable()->unique();
            $table->text('access_token_encrypted')->nullable();
        });
    }
};
