<?php

namespace App\Services\Reports;

class CheckCarDetailsReportNormalizer
{
    public function normalize(array $data): array
    {
        $registration = $data['registration'] ?? [];
        $fullVehicle = $data['full_vehicle'] ?? [];
        $history = $data['history'] ?? [];
        $mot = $data['mot'] ?? [];
        $mileage = $data['mileage'] ?? [];
        $image = $data['image'] ?? [];
        $valuation = $data['valuation'] ?? [];
        $compliance = $data['compliance'] ?? [];

        $vehicleRegistration = $history['VehicleRegistration'] ?? [];
        $vehicleHistory = $history['VehicleHistory'] ?? [];
        $dimensions = $history['Dimensions'] ?? [];
        $performance = $history['Performance'] ?? [];
        $consumption = $history['Consumption'] ?? [];
        $smmt = $history['SmmtDetails'] ?? [];
        $ved = $history['vedRate'] ?? [];
        $general = $history['General'] ?? [];

        $motHistory = $mot['motHistory'] ?? [];

        $colourChanges =
            data_get($fullVehicle, 'VehicleHistory.ColourChangeList')
            ?? data_get($vehicleHistory, 'ColourChangeList')
            ?? [];

        return [
            'meta' => [
                'provider' => 'checkcardetails',
                'generated_at' => now()->toISOString(),
            ],

            'vehicle' => [
                'vrm' => $registration['registrationNumber']
                    ?? $vehicleRegistration['Vrm']
                    ?? null,

                'vin' => $vehicleRegistration['Vin'] ?? null,

                'make' => $registration['make']
                    ?? $vehicleRegistration['Make']
                    ?? null,

                'model' => $registration['model']
                    ?? $vehicleRegistration['Model']
                    ?? null,

                'year' => $registration['yearOfManufacture']
                    ?? $vehicleRegistration['YearOfManufacture']
                    ?? null,

                'colour' => $registration['colour']
                    ?? $vehicleRegistration['Colour']
                    ?? null,

                'fuel_type' => $registration['fuelType']
                    ?? $vehicleRegistration['FuelType']
                    ?? $smmt['FuelType']
                    ?? null,

                'body_type' => $smmt['BodyStyle']
                    ?? $vehicleRegistration['DoorPlanLiteral']
                    ?? null,

                'transmission' => $vehicleRegistration['Transmission']
                    ?? $smmt['Transmission']
                    ?? null,

                'engine_capacity_cc' => $registration['engineCapacity']
                    ?? $vehicleRegistration['EngineCapacity']
                    ?? null,

                'image_url' => data_get(
                    $image,
                    'VehicleImages.ImageDetailsList.0.ImageUrl'
                ),
            ],

            'status' => [
                'scrapped' => $vehicleRegistration['Scrapped'] ?? null,

                'scrapped_date' => $vehicleRegistration[
                    'DateScrapped'
                ] ?? null,

                'certificate_of_destruction_issued' =>
                    $vehicleRegistration[
                        'CertificateOfDestructionIssued'
                    ] ?? null,

                'imported' => $vehicleRegistration['Imported'] ?? null,

                'exported' => $vehicleRegistration['Exported'] ?? null,
            ],

            'v5c' => [
                'count' => $vehicleHistory[
                    'V5CCertificateCount'
                ] ?? null,

                'history' => $vehicleHistory[
                    'V5CCertificateList'
                ] ?? [],

                'latest_issue_date' =>
                    $registration['dateOfLastV5CIssued']
                    ?? data_get(
                        $vehicleHistory,
                        'V5CCertificateList.0.CertificateDate'
                    ),

                'serial_verified' => null,
                'verification_available' => false,
            ],

            'history' => [
                'finance' => [
                    'available' => true,
                    'count' => count(
                        $vehicleHistory['finance'] ?? []
                    ),
                    'records' => $vehicleHistory['finance'] ?? [],
                ],

                'stolen' => [
                    'available' => true,
                    'count' => count(
                        $vehicleHistory['stolen'] ?? []
                    ),
                    'records' => $vehicleHistory['stolen'] ?? [],
                ],

                'write_off' => [
                    'available' => true,
                    'count' => count(
                        $vehicleHistory['writeoff'] ?? []
                    ),
                    'records' => $vehicleHistory['writeoff'] ?? [],
                ],

                'high_risk' => [
                    'available' => false,
                    'count' => null,
                    'records' => [],
                ],

                'keepers' => [
                    'available' => true,
                    'count' => $vehicleHistory[
                        'KeeperChangesCount'
                    ] ?? 0,

                    'records' => $vehicleHistory[
                        'KeeperChangesList'
                    ] ?? [],
                ],

                'plate_changes' => [
                    'available' => true,
                    'count' => $vehicleHistory[
                        'PlateChangeCount'
                    ] ?? 0,

                    'records' => $vehicleHistory[
                        'PlateChangeList'
                    ] ?? [],
                ],

                'colour_changes' => [
                    'available' => true,
                    'count' => $vehicleHistory[
                        'ColourChangeCount'
                    ] ?? count($colourChanges),

                    'records' => $colourChanges,
                ],
            ],

            'salvage' => [
                'available' => false,
                'record_found' => null,
                'records' => [],
            ],

            'specifications' => [
                'power_bhp' => data_get(
                    $performance,
                    'Power.Bhp'
                ),

                'power_kw' => data_get(
                    $performance,
                    'Power.Kw'
                ),

                'torque_nm' => data_get(
                    $performance,
                    'Torque.Nm'
                ),

                'torque_lbft' => data_get(
                    $performance,
                    'Torque.FtLb'
                ),

                'euro_standard' => $general[
                    'EuroStatus'
                ] ?? null,

                'co2_gkm' => $vehicleRegistration[
                    'Co2Emissions'
                ] ?? data_get(
                    $performance,
                    'Co2'
                ),

                'combined_mpg' => data_get(
                    $consumption,
                    'Combined.Mpg'
                ),

                'top_speed_mph' => data_get(
                    $performance,
                    'MaxSpeed.Mph'
                ),

                'zero_to_sixty_mph' => data_get(
                    $performance,
                    'Acceleration.ZeroTo60Mph'
                ),

                'insurance_group' => null,
            ],

            'dimensions' => [
                'height_mm' => $dimensions['Height'] ?? null,
                'length_mm' => $dimensions['CarLength'] ?? null,
                'width_mm' => $dimensions['Width'] ?? null,
                'wheelbase_mm' => $dimensions['WheelBase'] ?? null,
                'kerb_weight_kg' => $dimensions['KerbWeight'] ?? null,
                'gross_weight_kg' => $dimensions[
                    'GrossVehicleWeight'
                ] ?? null,
                'seats' => $dimensions['NumberOfSeats'] ?? null,
                'doors' => $dimensions['NumberOfDoors'] ?? null,
            ],

            'valuation' => [
                'retail' => data_get(
                    $valuation,
                    'ValuationList.DealerForecourt'
                ),

                'trade_in' => data_get(
                    $valuation,
                    'ValuationList.PartExchange'
                ),

                'private' => data_get(
                    $valuation,
                    'ValuationList.PrivateClean'
                ),

                'trade' => data_get(
                    $valuation,
                    'ValuationList.TradeRetail'
                ),
            ],

            'recalls' => [
                'available' => false,
                'status' => null,
                'source' => null,
                'count' => null,
                'records' => [],
                'manufacturer_url' => null,
            ],

            'emissions_compliance' => [
                'fuel_type' => $registration['fuelType']
                    ?? $vehicleRegistration['FuelType']
                    ?? null,

                'euro_standard' => $general[
                    'EuroStatus'
                ] ?? null,

                'meets_minimum_standard' => $compliance[
                    'meets_minimum_standard'
                ] ?? null,

                'assessment_type' => $compliance[
                    'assessment_type'
                ] ?? 'derived',

                'officially_verified' => false,

                'reason' => $compliance['reason'] ?? null,
            ],

            'running_costs' => [
                'road_tax' => [
                    'available' => true,

                    'annual' => data_get(
                        $ved,
                        'Standard.TwelveMonth'
                    ),

                    'six_month' => data_get(
                        $ved,
                        'Standard.SixMonth'
                    ),
                ],

                'insurance' => [
                    'available' => false,
                    'age_20' => null,
                    'age_30' => null,
                    'age_40' => null,
                    'age_50' => null,
                ],

                'fuel' => [
                    'available' => true,

                    'combined_mpg' => data_get(
                        $consumption,
                        'Combined.Mpg'
                    ),

                    'assessment_type' => 'derived',
                ],
            ],

            'mot' => [
                'service_available' => true,

                'status' => data_get(
                    $mot,
                    'mot.motStatus'
                ),

                'expiry_date' => data_get(
                    $mot,
                    'mot.motDueDate'
                ),

                'summary' => $mot[
                    'motHistorySummary'
                ] ?? null,

                'tests' => $motHistory,

                'mileage_history' => collect($motHistory)
                    ->map(fn (array $test) => [
                        'date' => $test[
                            'completedDate'
                        ] ?? null,

                        'mileage' => isset(
                            $test['odometerValue']
                        )
                            ? (int) $test['odometerValue']
                            : null,

                        'unit' => $test[
                            'odometerUnit'
                        ] ?? null,

                        'result' => $test[
                            'testResult'
                        ] ?? null,
                    ])
                    ->values()
                    ->all(),
            ],

            'mileage' => [
                'summary' => $mileage[
                    'summary'
                ] ?? null,

                'assessment_type' => 'provider',
            ],
        ];
    }
}
