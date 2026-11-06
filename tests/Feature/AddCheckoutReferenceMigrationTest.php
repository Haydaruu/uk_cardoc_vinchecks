<?php

use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createLegacyCheckoutReferenceMigrationPurchase(
    string $registration,
    string $email,
    string $status,
    int $amount,
    string $providerOrderId,
): GuestReportPurchase {
    $vehicle = Vehicle::create([
        'registration_number' => $registration,
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'report_type' => 'basic',
    ]);

    return GuestReportPurchase::create([
        'report_id' => $report->id,
        'email' => $email,
        'payment_provider' => 'stripe',
        'payment_status' => $status,
        'amount_minor' => $amount,
        'currency' => 'gbp',
        'provider_order_id' => $providerOrderId,
    ]);
}

test('checkout reference migration backfills unique UUIDs and preserves existing purchase data', function () {
    // Create records using the current schema first.
    $firstPurchase = createLegacyCheckoutReferenceMigrationPurchase(
        'MIGRATION-OLD-001',
        'first-migration@example.com',
        GuestReportPurchase::STATUS_PAID,
        2800,
        'cs_test_migration001',
    );

    $secondPurchase = createLegacyCheckoutReferenceMigrationPurchase(
        'MIGRATION-OLD-002',
        'second-migration@example.com',
        GuestReportPurchase::STATUS_PENDING,
        3500,
        'cs_test_migration002',
    );

    $purchaseIds = [
        $firstPurchase->id,
        $secondPurchase->id,
    ];

    // Simulate the old schema before checkout_reference was introduced.
    Schema::table('guest_report_purchases', function (Blueprint $table) {
        $table->dropUnique(
            'guest_report_purchases_checkout_reference_unique'
        );
    });

    Schema::table('guest_report_purchases', function (Blueprint $table) {
        $table->dropColumn('checkout_reference');
    });

    expect(
        Schema::hasColumn('guest_report_purchases', 'checkout_reference')
    )->toBeFalse();

    // Execute the actual migration against the legacy-style table.
    $migration = require database_path(
        'migrations/2026_11_02_120929_add_checkout_reference_to_guest_report_purchases_table.php'
    );

    $migration->up();

    expect(
        Schema::hasColumn('guest_report_purchases', 'checkout_reference')
    )->toBeTrue();

    $purchases = DB::table('guest_report_purchases')
        ->whereIn('id', $purchaseIds)
        ->orderBy('id')
        ->get();

    // Every existing purchase must receive a valid UUID.
    expect($purchases)->toHaveCount(2);

    $references = $purchases
        ->pluck('checkout_reference')
        ->all();

    foreach ($references as $reference) {
        expect(
            preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',
                $reference,
            )
        )->toBe(1);
    }

    // UUIDs must be unique.
    expect($references[0])->not->toBe($references[1]);

    // Existing payment data must remain unchanged.
    $firstAfterMigration = $purchases->first();

    expect($firstAfterMigration->payment_status)
        ->toBe(GuestReportPurchase::STATUS_PAID);
    expect((int) $firstAfterMigration->amount_minor)->toBe(2800);
    expect($firstAfterMigration->currency)->toBe('gbp');
    expect($firstAfterMigration->provider_order_id)
        ->toBe('cs_test_migration001');

    $secondAfterMigration = $purchases->last();

    expect($secondAfterMigration->payment_status)
        ->toBe(GuestReportPurchase::STATUS_PENDING);
    expect((int) $secondAfterMigration->amount_minor)->toBe(3500);
    expect($secondAfterMigration->currency)->toBe('gbp');
    expect($secondAfterMigration->provider_order_id)
        ->toBe('cs_test_migration002');

    // The database must reject NULL checkout references.
    expect(
        fn () => DB::table('guest_report_purchases')
            ->where('id', $firstPurchase->id)
            ->update(['checkout_reference' => null])
    )->toThrow(QueryException::class);

    // The unique constraint must reject duplicate references.
    expect(
        fn () => DB::table('guest_report_purchases')
            ->where('id', $secondPurchase->id)
            ->update(['checkout_reference' => $references[0]])
    )->toThrow(QueryException::class);
});
