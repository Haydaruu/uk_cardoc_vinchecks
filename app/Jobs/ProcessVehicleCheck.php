<?php

namespace App\Jobs;

use App\Contracts\VehicleDataProviderInterface;
use App\Services\VehicleProviders\OneAutoProvider;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Models\GuestReportPurchase;
use App\Services\CreditService;
use App\Services\Reports\NormalizedReportBuilder;
use App\Services\Reports\CompositeReportBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessVehicleCheck implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    use InteractsWithQueue;
    use Dispatchable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        if( $this->guestReportPurchaseId !== null) {
            return 'guest-report-purchase-'.$this->guestReportPurchaseId;
        }

        return 'vehicle-check'.$this->vinCheckId.'_'.(
            $this->existingReportId ?? 'new'
        );
    }

    public function __construct(
        private int $vinCheckId,
        private ?int $existingReportId = null,
        private ?int $guestReportPurchaseId = null,
    ) {
    }
    public function handle(
        VehicleDataProviderInterface $provider,
        NormalizedReportBuilder $reportBuilder,
        CreditService $creditService,
        ?CompositeReportBuilder $compositeReportBuilder = null,
        ?OneAutoProvider $oneAutoProvider = null,
    ): void {
        $vinCheck = VinCheck::findOrFail(
            $this->vinCheckId
        );

        $isGuestFulfillment =
            $this->guestReportPurchaseId !== null;

        $isPremium =
            $isGuestFulfillment
            || $vinCheck->check_type === 'premium';

        $regNumber =
            $vinCheck->registration_number;

        try {
            if ($isGuestFulfillment) {
                if ($this->existingReportId === null) {
                    throw new \RuntimeException('Guest Premium fulfillment requires an existing report.');
                }

                $alreadyCompleted = DB::transaction(
                    function () use ($vinCheck): bool {
                        $purchase = GuestReportPurchase::query()
                            ->lockForUpdate()
                            ->findOrFail($this->guestReportPurchaseId);
                        if ($purchase->payment_status !== GuestReportPurchase::STATUS_PAID) {
                            throw new \RuntimeException('Guest report purchase has not been paid.');
                        }

                        if ((int) $purchase->report_id !== (int) $this->existingReportId) {
                            throw new \RuntimeException('Guest purchase does not match the report.');
                        }

                        if ($purchase->fulfillment_status === GuestReportPurchase::FULFILLMENT_COMPLETED) {
                            return true;
                        }

                        if (! in_array($purchase->fulfillment_status,[
                            GuestReportPurchase::FULFILLMENT_PENDING,
                            GuestReportPurchase::FULFILLMENT_PROCESSING,
                            GuestReportPurchase::FULFILLMENT_FAILED,
                        ], true)) {
                            throw new \RuntimeException('Guest report fulfillment is not eligible to run.');
                        }

                        $purchase->update([
                            'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PROCESSING,
                            'fulfillment_attempts' => ((int) $purchase->fulfillment_attempts) + 1,
                            'fulfillment_started_at' => now(),
                        ]);

                        $vinCheck->update([
                            'check_type' => 'premium',
                            'status' => 'pending',
                            'stage' => 'queued',
                        ]);

                        return false;
                    } 
                );

                if ($alreadyCompleted) {
                    return;
                }

                $vinCheck->refresh();
            }
            $vinCheck->update([
                'stage' => 'connecting',
            ]);

            if (! $isPremium) {
                $selectedProvider = config(
                    'services.vehicle_reports.provider',
                    'checkcardetails'
                );

                if ($selectedProvider === 'oneauto') {
                    $oneAutoProvider ??= app(OneAutoProvider::class);

                    $reportData = $this->buildOneAutoBasicReportData(
                        $regNumber,
                        $oneAutoProvider,
                    );
                } else {
                    $reportData = $provider->getRegistrationDetails(
                        $regNumber
                    );
                }

                $vehicleData = $this->mapBasicApiDataToVehicle(
                    $reportData
                );

                $actualReportType = 'basic';
            } else {
                $vinCheck->update([
                    'stage' => 'verifying_history',
                ]);

                $strategy = config(
                    'services.vehicle_reports.strategy',
                    'single'
                );

                if ($strategy === 'hybrid') {
                    $builder = $compositeReportBuilder
                        ?? app(CompositeReportBuilder::class);

                    $builtReport = $builder->build($regNumber);
                } else {
                    $builtReport = $reportBuilder->build(
                        vrm: $regNumber,
                    );
                }

                $reportData =
                    $builtReport['normalized'];

                $vehicleData =
                    $this->mapNormalizedReportToVehicle(
                        normalized: $reportData,
                        raw: $builtReport['raw'],
                    );

                $actualReportType = 'premium';
            }

            $vinCheck->update([
                'stage' => 'finalizing',
            ]);

            $vehicle = Vehicle::updateOrCreate(
                [
                    'registration_number' =>
                        $regNumber,
                ],
                $vehicleData,
            );

            $guestReportPurchaseId = $this->guestReportPurchaseId;

            DB::transaction(
                function () use (
                    $vinCheck,
                    $vehicle,
                    $isPremium,
                    $actualReportType,
                    $reportData,
                    $creditService,
                    $guestReportPurchaseId,
                ) {
                    if ($this->existingReportId) {

                        $report = Report::findOrFail(
                            $this->existingReportId
                        );

                        $report->update([
                            'vehicle_id' =>
                                $vehicle->id,

                            'vin' =>
                                $vehicle->vin,

                            'report_data' =>
                                $reportData,

                            'report_type' =>
                                $isPremium
                                    ? 'premium'
                                    : 'basic',

                            'vin_check_id' =>
                                $vinCheck->id,

                            'generated_at' =>
                                now(),
                        ]);
                    } else {
                        $report = Report::create([
                            'user_id' =>
                                $vinCheck->user_id,

                            'vehicle_id' =>
                                $vehicle->id,

                            'vin_check_id' =>
                                $vinCheck->id,

                            'vin' =>
                                $vehicle->vin,

                            'report_data' =>
                                $reportData,

                            'report_type' =>
                                $isPremium
                                    ? 'premium'
                                    : 'basic',

                            'generated_at' =>
                                now(),
                        ]);
                    }

                    if (
                        $actualReportType === 'premium'
                        && $isPremium
                        && $vinCheck->user_id
                    ) {
                        $creditService->consume(
                            user:
                                $vinCheck->user,

                            referenceId:
                                (string) $report->id,

                            description:
                                "Vehicle check: {$vinCheck->registration_number}",
                        );
                    }

                    if ($guestReportPurchaseId !== null) {
                        $purchase = GuestReportPurchase::query()
                            ->lockForUpdate()
                            ->findOrFail($guestReportPurchaseId);
                        if ( $purchase->payment_status!== GuestReportPurchase::STATUS_PAID
                            || (int) $purchase->report_id !== (int) $report->id) {
                                throw new \RuntimeException('Guest purchase validation failed during fulfillment.');
                        }

                        $purchase->update([
                            'fulfillment_status' => GuestReportPurchase::FULFILLMENT_COMPLETED,
                            'fulfilled_at' => $purchase->fulfilled_at ?? now(),
                        ]);
                    }

                    $vinCheck->update([
                        'stage' => 'completed',
                        'status' => 'success',
                    ]);
                }
            );
        } catch (\Throwable $e) {
            Log::error(
                'Vehicle check failed',
                [
                    'vin_check_id' =>
                        $vinCheck->id,

                    'registration_number' =>
                        $regNumber,

                    'check_type' =>
                        $vinCheck->check_type,

                    'error' =>
                        $e->getMessage(),
                ],
            );

            $vinCheck->update([
                'stage' => 'failed',
                'status' => 'failed',
            ]);

            if ( $this->guestReportPurchaseId !== null && $this->attempts() >= $this->tries) {
                GuestReportPurchase::query()
                    ->whereKey($this->guestReportPurchaseId)
                    ->where('payment_status', GuestReportPurchase::STATUS_PAID)
                    ->where('fulfillment_status', GuestReportPurchase::FULFILLMENT_PROCESSING
                    )
                    ->update([
                        'fulfillment_status' =>
                            GuestReportPurchase::FULFILLMENT_FAILED,
                    ]);
            }

            throw $e;
        }
    }

    private function buildOneAutoBasicReportData(
        string $requestedVrm,
        OneAutoProvider $oneAuto,
    ): array {
        // 1. Ambil identitas kendaraan terlebih dahulu.
        $details = $oneAuto->getVehicleDetails($requestedVrm);

        if (data_get($details, 'success') !== true) {
            throw new \RuntimeException(
                'OneAuto vehicle details response was unsuccessful.'
            );
        }

        $identity = data_get(
            $details,
            'result.vehicle_identification',
            []
        );

        $normalizeVrm = static fn ($value): string => strtoupper(
            preg_replace(
                '/\s+/',
                '',
                trim((string) $value)
            )
        );

        $expectedVrm = $normalizeVrm($requestedVrm);

        $detailsVrm = $normalizeVrm(
            data_get($identity, 'vehicle_registration_mark')
        );

        // 2. Tolak kendaraan yang tidak cocok sebelum meminta MOT.
        if ($detailsVrm === '' || $detailsVrm !== $expectedVrm) {
            throw new \RuntimeException(
                "OneAuto vehicle mismatch. Requested {$expectedVrm}, "
                . "but Vehicle Details returned {$detailsVrm}."
            );
        }

        // 3. Baru ambil MOT setelah identitas pertama lolos.
        $mot = $oneAuto->getMotHistory($requestedVrm);

        if (data_get($mot, 'success') !== true) {
            throw new \RuntimeException(
                'OneAuto MOT response was unsuccessful.'
            );
        }

        $motVehicle = data_get(
            $mot,
            'result.dvsa_data.dvsa_vehicle_Data',
            []
        );

        $motVrm = $normalizeVrm(
            data_get($motVehicle, 'vehicle_registration_mark')
        );

        // 4. Pastikan respons MOT juga milik kendaraan yang diminta.
        if ($motVrm === '' || $motVrm !== $expectedVrm) {
            throw new \RuntimeException(
                "OneAuto MOT vehicle mismatch. Requested {$expectedVrm}, "
                . "but MOT returned {$motVrm}."
            );
        }

        // 5. Ambil hasil MOT terbaru.
        $latestMot = collect(
            data_get($mot, 'result.dvsa_data.mot_tests', [])
        )
            ->sortByDesc(
                fn (array $test) => $test['mot_test_date'] ?? ''
            )
            ->first();

        $motExpiry = $latestMot['mot_expiry_date'] ?? null;

        $motStatus = $motExpiry
            ? (
                \Carbon\CarbonImmutable::parse($motExpiry)
                    ->greaterThanOrEqualTo(now()->startOfDay())
                    ? 'Valid'
                    : 'Expired'
            )
            : 'Unknown';

        // 6. Kembalikan struktur basic report yang digunakan aplikasi.
        return [
            'registrationNumber' => $detailsVrm,
            'make' => data_get($identity, 'dvla_manufacturer_desc'),
            'model' => data_get($identity, 'dvla_model_desc'),

            'colour' => data_get(
                $details,
                'result.colour_details.colour'
            ) ?? data_get($motVehicle, 'colour'),

            'fuelType' => data_get(
                $identity,
                'dvla_fuel_desc'
            ) ?? data_get($motVehicle, 'fuel_type_desc'),

            'engineCapacity' => null,
            'yearOfManufacture' => data_get(
                $identity,
                'manufactured_year'
            ),

            'co2Emissions' => data_get(
                $details,
                'result.vehicle_excise_duty_details.co2_gkm'
            ),

            'tax' => [
                'taxStatus' => data_get(
                    $mot,
                    'result.dvla_data.tax_status'
                ),
                'taxDueDate' => data_get(
                    $mot,
                    'result.dvla_data.tax_expiry_date'
                ),
            ],

            'mot' => [
                'motStatus' => $motStatus,
                'motDueDate' => $motExpiry,
            ],

            'vin' => data_get(
                $identity,
                'vehicle_identification_number'
            ),
        ];
    }

    private function mapBasicApiDataToVehicle(
        array $apiData
    ): array {
        return [
            'brand' => $apiData['make'] ?? null,
            'model' => $apiData['model'] ?? null,
            'colour' => $apiData['colour'] ?? null,
            'fuel_type' => $apiData['fuelType'] ?? null,
            'engine_capacity' => $apiData['engineCapacity'] ?? null,
            'year' => $apiData['yearOfManufacture'] ?? null,
            'year_of_manufacture' => $apiData['yearOfManufacture'] ?? null,
            'co2_emissions' => $apiData['co2Emissions'] ?? null,
            'tax_status' => data_get($apiData, 'tax.taxStatus'),
            'mot_expiry_date' => data_get($apiData, 'mot.motDueDate'),
            'vin' => $apiData['vin'] ?? 'NOT FOUND',
            'raw_api_response' => $apiData,
            'last_refreshed_at' => now(),
        ];
    }
    private function mapNormalizedReportToVehicle(
        array $normalized,
        array $raw,
    ): array {
        $vehicle =
            $normalized['vehicle']
            ?? [];

        $finance =
            data_get(
                $normalized,
                'history.finance',
                []
            );

        $writeOff =
            data_get(
                $normalized,
                'history.write_off',
                []
            );

        /*
         * Ambil MOT terbaru.
         *
         * Normalized schema dua provider sudah
         * menggunakan:
         *
         * date
         * expiry_date
         * mileage
         * result
         */
        $latestMot = collect(
            data_get(
                $normalized,
                'mot.tests',
                []
            )
        )
            ->sortByDesc(
                fn (array $test) =>
                    $test['date']
                    ?? ''
            )
            ->first();

        /*
         * write_off.records masih membawa beberapa
         * detail provider-specific.
         *
         * CCD biasanya punya "status".
         * OneAuto biasanya punya "vehicle_status".
         */
        $writeOffCategory =
            data_get(
                $writeOff,
                'records.0.status'
            )
            ?? data_get(
                $writeOff,
                'records.0.vehicle_status'
            );

        return [
            'vin' =>
                $vehicle['vin']
                ?? 'NOT FOUND',

            'brand' =>
                $vehicle['make']
                ?? null,

            'model' =>
                $vehicle['model']
                ?? null,

            'year' =>
                $vehicle['year']
                ?? null,

            'year_of_manufacture' =>
                $vehicle['year']
                ?? null,

            'colour' =>
                $vehicle['colour']
                ?? null,

            'fuel_type' =>
                $vehicle['fuel_type']
                ?? null,

            'engine_capacity' =>
                $vehicle['engine_capacity_cc']
                ?? null,

            'co2_emissions' =>
                data_get(
                    $normalized,
                    'specifications.co2_gkm'
                ),

            'tax_status' =>
                data_get(
                    $normalized,
                    'mot.tax_status'
                ),

            'mot_expiry_date' =>
                $latestMot[
                    'expiry_date'
                ]
                ?? null,

            'outstanding_finance' =>
                (
                    (int) (
                        $finance['count']
                        ?? 0
                    )
                ) > 0,

            'write_off_category' =>
                $writeOffCategory,

            'image_url' =>
                $vehicle['image_url']
                ?? null,

            /*
             * Vehicle model tetap punya raw_api_response.
             *
             * Di sini kita simpan raw bundle provider,
             * sedangkan Report.report_data hanya menyimpan
             * normalized customer report.
             */
            'raw_api_response' =>
                $raw,

            'last_refreshed_at' =>
                now(),
        ];
    }
}