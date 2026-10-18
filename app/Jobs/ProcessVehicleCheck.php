<?php

namespace App\Jobs;

use App\Contracts\VehicleDataProviderInterface;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Models\GuestReportPurchase;
use App\Services\CreditService;
use App\Services\Reports\NormalizedReportBuilder;
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

            if (!$isPremium) {
                $reportData =
                    $provider->getRegistrationDetails(
                        $regNumber
                    );

                $vehicleData =
                    $this->mapBasicApiDataToVehicle(
                        $reportData
                    );

                $actualReportType = 'basic';
            } else {


                $vinCheck->update([
                    'stage' => 'verifying_history',
                ]);

                $builtReport =
                    $reportBuilder->build(
                        vrm: $regNumber,
                    );

                $reportData =
                    $builtReport['normalized'];

                $vehicleData =
                    $this->mapNormalizedReportToVehicle(
                        normalized:
                            $reportData,

                        raw:
                            $builtReport['raw'],
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

            if (
                $this->attempts()
                >= $this->tries
            ) {
                return;
            }

            throw $e;
        }
    }

    private function mapBasicApiDataToVehicle(
        array $apiData
    ): array {
        return [
            'brand' =>
                $apiData['make']
                ?? null,

            'model' =>
                $apiData['model']
                ?? null,

            'colour' =>
                $apiData['colour']
                ?? null,

            'fuel_type' =>
                $apiData['fuelType']
                ?? null,

            'engine_capacity' =>
                $apiData['engineCapacity']
                ?? null,

            'year' =>
                $apiData['yearOfManufacture']
                ?? null,

            'year_of_manufacture' =>
                $apiData['yearOfManufacture']
                ?? null,

            'co2_emissions' =>
                $apiData['co2Emissions']
                ?? null,

            'tax_status' =>
                data_get(
                    $apiData,
                    'tax.taxStatus'
                ),

            'mot_expiry_date' =>
                data_get(
                    $apiData,
                    'mot.motDueDate'
                ),

            'vin' =>
                $apiData['vin']
                ?? 'NOT FOUND',

            'raw_api_response' =>
                $apiData,

            'last_refreshed_at' =>
                now(),
        ];
    }

    /**
     * Map UKCarDoc normalized report
     * ke Vehicle model.
     */
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