
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_report_purchases', function (Blueprint $table) {
            $table->uuid('checkout_reference')->nullable();
            $table->unique('checkout_reference');
        });

        // Backfill existing purchases before making the column mandatory.
        DB::table('guest_report_purchases')
            ->whereNull('checkout_reference')
            ->orderBy('id')
            ->chunkById(100, function ($purchases): void {
                foreach ($purchases as $purchase) {
                    DB::table('guest_report_purchases')
                        ->where('id', $purchase->id)
                        ->whereNull('checkout_reference')
                        ->update([
                            'checkout_reference' => (string) Str::uuid(),
                        ]);
                }
            });

        DB::statement(
            'ALTER TABLE guest_report_purchases
             ALTER COLUMN checkout_reference SET NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::table('guest_report_purchases', function (Blueprint $table) {
            $table->dropUnique(
                'guest_report_purchases_checkout_reference_unique'
            );

            $table->dropColumn('checkout_reference');
        });
    }
};
