<?php

namespace App\Services\Reports;

use App\Services\EmissionsComplianceService;
use App\Services\VehicleDataService;
use App\Services\VehicleProviders\OneAutoProvider;
use InvalidArgumentException;
class NormalizedReportBuilder
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        private VehicleDataService $checkCarDetails,
        private OneAutoProvider $oneAuto,
        private CheckCarDetailsReportNormalizer $checkCarDetailsNormalizer,
        private OneAutoReportNormalizer $oneAutoNormalizer,
        private EmissionsComplianceService $emissionsCompliance,
    ) {
    }

    public function build(string $vrm, ?string $provider = null, ): array 
    {
        $provider ??= config('services.vehicle_reports.provider', 'checkcardetails');

        $vrm = strtoupper(preg_replace('/\s+/', '', $vrm));

        $result = match ($provider) {
            'checkcardetails' =>
                $this->buildCheckCarDetails($vrm),

            'oneauto' =>
                $this->buildOneAuto($vrm),

            default => throw new InvalidArgumentException(
                "Unsupported vehicle data provider: {$provider}"
            ),
        };

        $normalized = $result['normalized'];

        $normalized['meta'] = [
            ...($normalized['meta'] ?? []),

            'schema_version' => 2,
            'format' => 'normalized',
            'provider' => $provider,
        ];

        return [
            'provider' => $provider,
            'vrm' => $vrm,

            'meta' => $result['meta'] ?? [],

            'normalized' => $normalized,

            'raw' => $result['raw'],
        ];
    }

    private function buildCheckCarDetails(
        string $vrm
    ): array {
        $registration = $this->checkCarDetails
            ->getRegistrationDetails($vrm);

        $fullVehicle = $this->checkCarDetails
            ->getFullVehicleData($vrm);

        $history = $this->checkCarDetails
            ->getHistoryCheck($vrm);

        $mot = $this->checkCarDetails
            ->getMotHistory($vrm);

        $mileage = $this->checkCarDetails
            ->getMileageHistory($vrm);

        $image = $this->checkCarDetails
            ->getVehicleImage($vrm);

        $valuation = $this->checkCarDetails
            ->getVehicleValuation($vrm);

        $fuelType =
            data_get(
                $history,
                'VehicleRegistration.FuelType'
            )
            ?? data_get(
                $registration,
                'fuelType'
            );

        $euroStandard = data_get(
            $history,
            'General.EuroStatus'
        );

        $compliance =
            $this->emissionsCompliance->assess(
                $fuelType,
                $euroStandard
            );

        $raw = [
            'registration' => $registration,
            'full_vehicle' => $fullVehicle,
            'history' => $history,
            'mot' => $mot,
            'mileage' => $mileage,
            'image' => $image,
            'valuation' => $valuation,
            'compliance' => $compliance,
        ];

        return [
            'normalized' =>
                $this->checkCarDetailsNormalizer
                    ->normalize($raw),

            'raw' => $raw,

            'meta' => [
                'provider' => 'checkcardetails',
            ],
        ];
    }

    private function buildOneAuto(string $vrm): array 
    {
        $autoCheck = $this->oneAuto
            ->getAutoCheck($vrm);

        $salvage = $this->oneAuto
            ->getSalvageCheck($vrm);

        $recall = $this->oneAuto
            ->getRecallCheck($vrm);

        $mot = $this->oneAuto
            ->getMotHistory($vrm);

        /*
         * Ambil mileage MOT terbaru
         * untuk AutoTrader valuation.
         */
        $latestMot = collect(
            data_get(
                $mot,
                'result.dvsa_data.mot_tests',
                []
            )
        )
            ->sortByDesc(
                fn (array $test) =>
                    $test['mot_test_date'] ?? ''
            )
            ->first();

        $valuationMileage = (int) (
            $latestMot['observation_mileage']
            ?? 0
        );

        $valuation = $this->oneAuto
            ->getValuation(
                $vrm,
                $valuationMileage
            );

        $derivativeId = data_get(
            $valuation,
            'result.autotrader_derivative_id'
        );

        $effectiveDate =
            data_get(
                $autoCheck,
                'result.first_registration_date'
            )
            ?? data_get(
                $autoCheck,
                'result.registration_date'
            )
            ?? now()->toDateString();

        $specs = $derivativeId
            ? $this->oneAuto
                ->getAutoTraderSpecs(
                    $derivativeId,
                    $effectiveDate
                )
            : [];

        $insuranceGroup = data_get(
            $specs,
            'result.insurance_data.insurance_group_1to50'
        );

        $fuelType = data_get(
            $specs,
            'result.basic_vehicle_info.autotrader_fuel_type_desc'
        );

        $insurance = (
            $insuranceGroup !== null
            && $fuelType !== null
        )
            ? $this->oneAuto
                ->getInsuranceCosts(
                    (string) $insuranceGroup,
                    (string) $fuelType
                )
            : [];

        $tax = $this->oneAuto
            ->getVehicleTax($vrm);

        $euroStandard = data_get(
            $specs,
            'result.engine_data.emission_class'
        );

        $compliance =
            $this->emissionsCompliance->assess(
                $fuelType,
                $euroStandard
            );

        $raw = [
            'auto_check' => $autoCheck,
            'salvage' => $salvage,
            'recall' => $recall,
            'valuation' => $valuation,
            'specs' => $specs,
            'insurance' => $insurance,
            'tax' => $tax,
            'mot' => $mot,
            'compliance' => $compliance,
        ];

        return [
            'normalized' =>
                $this->oneAutoNormalizer
                    ->normalize($raw),

            'raw' => $raw,

            'meta' => [
                'provider' => 'oneauto',

                'environment' =>
                    config(
                        'services.oneauto.environment'
                    ),

                'valuation_mileage' =>
                    $valuationMileage,

                'effective_date' =>
                    $effectiveDate,
            ],
        ];
    }
}
