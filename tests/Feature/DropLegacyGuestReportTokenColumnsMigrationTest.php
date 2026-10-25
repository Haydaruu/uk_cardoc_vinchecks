<?php

use App\Models\GuestReportAccessToken;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function addLegacyGuestTokenColumnsForMigrationTest(): void
{
    Schema::table('guest_report_purchases', function (Blueprint $table) {
        $table->char('access_token_hash', 64)->nullable()->unique();
        $table->text('access_token_encrypted')->nullable();
    });
}

function legacyGuestTokenDropMigrationForTest(): object
{
    static $migration = null;

    if ($migration === null) {
        $migration = require database_path(
            'migrations/2026_10_19_062740_drop_legacy_access_token_columns_from_guest_report_purchases_table.php'
        );
    }

    return $migration;
}

function createPurchaseForLegacyTokenMigrationTest(): GuestReportPurchase
{
    $vehicle = Vehicle::create([
        'registration_number' => 'LEGACY-MIGRATION-TEST',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'report_type' => 'basic',
    ]);

    return GuestReportPurchase::create([
        'report_id' => $report->id,
        'email' => 'legacy@example.com',
        'payment_provider' => 'stripe',
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'amount_minor' => 2000,
        'currency' => 'gbp',
    ]);
}

test('legacy token columns are dropped when the token has been copied to the access table', function () {
    addLegacyGuestTokenColumnsForMigrationTest();

    $purchase = createPurchaseForLegacyTokenMigrationTest();

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    DB::table('guest_report_purchases')
        ->where('id', $purchase->id)
        ->update([
            'access_token_hash' => $tokenHash,
            'access_token_encrypted' => encrypt($token),
        ]);

    GuestReportAccessToken::create([
        'guest_report_id' => $purchase->report_id,
        'token_hash' => $tokenHash,
        'created_at' => now(),
    ]);

    legacyGuestTokenDropMigrationForTest()->up();

    expect(
        Schema::hasColumn('guest_report_purchases', 'access_token_hash')
    )->toBeFalse();

    expect(
        Schema::hasColumn('guest_report_purchases', 'access_token_encrypted')
    )->toBeFalse();

    $this->assertDatabaseHas('guest_report_access_tokens', [
        'guest_report_id' => $purchase->report_id,
        'token_hash' => $tokenHash,
        'revoked_at' => null,
    ]);
});

test('legacy token columns are preserved when a token has not been copied', function () {
    addLegacyGuestTokenColumnsForMigrationTest();

    $purchase = createPurchaseForLegacyTokenMigrationTest();

    $token = bin2hex(random_bytes(32));

    DB::table('guest_report_purchases')
        ->where('id', $purchase->id)
        ->update([
            'access_token_hash' => hash('sha256', $token),
            'access_token_encrypted' => encrypt($token),
        ]);

    expect(
        fn () => legacyGuestTokenDropMigrationForTest()->up()
    )->toThrow(
        RuntimeException::class,
        'Cannot drop legacy guest access token columns'
    );

    expect(
        Schema::hasColumn('guest_report_purchases', 'access_token_hash')
    )->toBeTrue();

    expect(
        Schema::hasColumn('guest_report_purchases', 'access_token_encrypted')
    )->toBeTrue();
});
