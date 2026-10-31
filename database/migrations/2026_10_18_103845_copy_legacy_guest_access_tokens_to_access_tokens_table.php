<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('guest_report_purchases')
            ->whereNotNull('access_token_hash')
            ->orderBy('id')
            ->chunkById(100, function ($purchases): void {
                foreach ($purchases as $purchase) {
                    $tokenHash = $purchase->access_token_hash;

                    if (
                        ! is_string($tokenHash)
                        || preg_match('/\A[a-f0-9]{64}\z/', $tokenHash) !== 1
                    ) {
                        throw new RuntimeException(
                            "Invalid legacy access token hash for purchase ID {$purchase->id}."
                        );
                    }

                    $existingToken = DB::table(
                        'guest_report_access_tokens'
                    )
                        ->where('token_hash', $tokenHash)
                        ->first();

                    if ($existingToken !== null) {
                        if (
                            (int) $existingToken->guest_report_id
                            !== (int) $purchase->report_id
                        ) {
                            throw new RuntimeException(
                                "Access token hash maps to conflicting reports for purchase ID {$purchase->id}."
                            );
                        }

                        continue;
                    }

                    DB::table('guest_report_access_tokens')->insert([
                        'guest_report_id' => $purchase->report_id,
                        'token_hash' => $tokenHash,
                        'revoked_at' => null,
                        'created_at' => $purchase->created_at ?? now(),
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
