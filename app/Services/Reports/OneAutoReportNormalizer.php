<?php

namespace App\Services\Reports;

use Carbon\Carbon;

class OneAutoReportNormalizer
{
    public function normalize(array $data): array
    {
        $autoCheck = $data['auto_check'] ?? [];
        $salvage = $data['salvage'] ?? [];
        $recall = $data['recall'] ?? [];
        $valuation = $data['valuation'] ?? [];
        $specs = $data['specs'] ?? [];
        $insurance = $data['insurance'] ?? [];
        $tax = $data['tax'] ?? [];
        $compliance = $data['compliance'] ?? [];
        $mot = $data['mot'] ?? [];
        $motTests = collect(
            data_get(
                $mot,
                'result.dvsa_data.mot_tests',
                []
            )
        );

        $latestMot = $motTests
            ->sortByDesc(
                fn (array $test) =>
                    $test['mot_test_date']
                    ?? ''
            )
            ->first();

        $latestMotResult =
            $latestMot['mot_test_result']
            ?? null;

        $latestMotExpiry =
            $latestMot['mot_expiry_date']
            ?? null;

        $currentMotStatus =
            $this->deriveMotStatus(
                $latestMotResult,
                $latestMotExpiry,
            );

        return [
            'meta' => [
                'provider' => 'oneauto',
                'environment' => config('services.oneauto.environment'),
                'generated_at' => now()->toISOString(),
            ],

            'vehicle' => [
                'vrm' => data_get(
                    $autoCheck,
                    'result.vehicle_registration_mark'
                ),

                'vin' => data_get(
                    $autoCheck,
                    'result.vehicle_identification_number'
                ),

                'make' => data_get(
                    $autoCheck,
                    'result.dvla_manufacturer_desc'
                ),

                'model' => data_get(
                    $autoCheck,
                    'result.dvla_model_desc'
                ),

                'year' => data_get(
                    $autoCheck,
                    'result.manufactured_year'
                ),

                'colour' => data_get(
                    $autoCheck,
                    'result.colour'
                ),

                'fuel_type' => data_get(
                    $specs,
                    'result.basic_vehicle_info.autotrader_fuel_type_desc'
                ),

                'body_type' => data_get(
                    $specs,
                    'result.basic_vehicle_info.autotrader_body_type_desc'
                ),

                'transmission' => data_get(
                    $specs,
                    'result.basic_vehicle_info.autotrader_transmission_desc'
                ),

                'engine_capacity_cc' => data_get(
                    $specs,
                    'result.engine_data.engine_capacity_cc'
                ),

                'image_url' => null,
            ],

            'status' => [
                'scrapped' => data_get(
                    $autoCheck,
                    'result.is_scrapped'
                ),

                'scrapped_date' => data_get(
                    $autoCheck,
                    'result.scrapped_date'
                ),

                'certificate_of_destruction_issued' => data_get(
                    $autoCheck,
                    'result.certificate_of_destruction_issued'
                ),

                'imported' => data_get(
                    $autoCheck,
                    'result.is_imported'
                ),

                'exported' => data_get(
                    $autoCheck,
                    'result.is_exported'
                ),
            ],

            'v5c' => [
                'count' => data_get(
                    $autoCheck,
                    'result.v5c_data_qty'
                ),

                'history' => collect(
                    data_get(
                        $autoCheck,
                        'result.v5c_data_items',
                        []
                    )
                )
                    ->map(fn (array $item) => [
                        'issue_date' => $item['date_v5c_issued'] ?? null,
                    ])
                    ->values()
                    ->all(),

                'latest_issue_date' => data_get(
                    $autoCheck,
                    'result.v5c_data_items.0.date_v5c_issued'
                ),

                'serial_verified' => null,
                'verification_available' => false,
            ],

            'history' => [
                'finance' => [
                    'count' => data_get(
                        $autoCheck,
                        'result.finance_data_qty',
                        0
                    ),

                    'records' => data_get(
                        $autoCheck,
                        'result.finance_data_items',
                        []
                    ),

                    'available' => true,
                ],

                'stolen' => [
                    'count' => data_get(
                        $autoCheck,
                        'result.stolen_vehicle_data_qty',
                        0
                    ),

                    'records' => data_get(
                        $autoCheck,
                        'result.stolen_vehicle_data_items',
                        []
                    ),

                    'available' => true,
                ],

                'write_off' => [
                    'count' => data_get(
                        $autoCheck,
                        'result.condition_data_qty',
                        0
                    ),

                    'records' => data_get(
                        $autoCheck,
                        'result.condition_data_items',
                        []
                    ),

                    'available' => true,
                ],

                'high_risk' => [
                    'count' => data_get(
                        $autoCheck,
                        'result.high_risk_data_qty',
                        0
                    ),

                    'records' => data_get(
                        $autoCheck,
                        'result.high_risk_data_items',
                        []
                    ),

                    'available' => true,
                ],

                'keepers' => [
                    'available' => true,

                    'count' => data_get(
                        $autoCheck,
                        'result.keeper_changes_qty',
                        0
                    ),

                    'records' => collect(
                        data_get(
                            $autoCheck,
                            'result.keeper_data_items',
                            []
                        )
                    )
                        ->map(fn (array $item) => [
                            'previous_keepers' =>
                                $item['number_previous_keepers'] ?? null,

                            'change_date' =>
                                $item['date_of_last_keeper_change'] ?? null,
                        ])
                        ->values()
                        ->all(),
                ],

                'plate_changes' => [
                    'available' => true,

                    'count' => data_get(
                        $autoCheck,
                        'result.cherished_data_qty',
                        0
                    ),

                    'records' => collect(
                        data_get(
                            $autoCheck,
                            'result.cherished_data_items',
                            []
                        )
                    )
                        ->map(fn (array $item) => [
                            'current_vrm' =>
                                $item['current_vehicle_registration_mark'] ?? null,

                            'previous_vrm' =>
                                $item['previous_vehicle_registration_mark'] ?? null,

                            'transfer_date' =>
                                $item['cherished_plate_transfer_date'] ?? null,

                            'receipt_date' =>
                                $item['date_of_receipt'] ?? null,

                            'transfer_type' =>
                                $item['transfer_type'] ?? null,
                        ])
                        ->values()
                        ->all(),
                ],

                'colour_changes' => [
                    'available' => true,
                    'count' => data_get(
                        $autoCheck,
                        'result.colour_changes_qty',
                        0
                    ),

                    'records' => data_get(
                        $autoCheck,
                        'result.colour_data_items',
                        []
                    ),
                ],
            ],

            'salvage' => [
                'record_found' => data_get(
                    $salvage,
                    'result.salvage_auction_record_found',
                    false
                ),

                'records' => collect(
                    data_get(
                        $salvage,
                        'result.salvage_auction_records',
                        []
                    )
                )
                    ->map(fn (array $record) => [
                        'salvage_auction_record_id' =>
                            $record['salvage_auction_record_id']
                            ?? null,

                        'salvage_auction_reference' =>
                            $record['salvage_auction_reference']
                            ?? null,

                        'salvage_auction_lot_desc' =>
                            $record['salvage_auction_lot_desc']
                            ?? null,

                        'salvage_auction_lot_date' =>
                            $record['salvage_auction_lot_date']
                            ?? null,

                        'mileage' =>
                            isset($record['mileage'])
                                ? (int) $record['mileage']
                                : null,

                        'primary_damage_desc' =>
                            $record['primary_damage_desc']
                            ?? null,

                        'secondary_damage_desc' =>
                            $record['secondary_damage_desc']
                            ?? null,

                        'salvage_auction_location' =>
                            $record['salvage_auction_location']
                            ?? null,

                        'external_image_urls' =>
                            array_values(
                                array_filter(
                                    $record['external_image_urls']
                                    ?? [],
                                    fn ($url) =>
                                        is_string($url)
                                        && $url !== ''
                                )
                            ),
                    ])
                    ->values()
                    ->all(),

                'available' => true,
            ],

            'specifications' => [
                'power_bhp' => data_get(
                    $specs,
                    'result.engine_data.power_bhp'
                ),

                'power_ps' => data_get(
                    $specs,
                    'result.engine_data.power_ps'
                ),

                'power_kw' => null,

                'torque_nm' => data_get(
                    $specs,
                    'result.engine_data.torque_nm'
                ),

                'torque_lbft' => data_get(
                    $specs,
                    'result.engine_data.torque_lbft'
                ),

                'euro_standard' => data_get(
                    $specs,
                    'result.engine_data.emission_class'
                ),

                'co2_gkm' => data_get(
                    $specs,
                    'result.engine_data.co2_gkm'
                ),

                'combined_mpg' => data_get(
                    $specs,
                    'result.engine_data.wltp_combined_mpg'
                ) ?? data_get(
                    $specs,
                    'result.engine_data.nedc_combined_mpg'
                ),

                'top_speed_mph' => data_get(
                    $specs,
                    'result.performance_data.top_speed_mph'
                ),

                'zero_to_sixty_mph' => data_get(
                    $specs,
                    'result.performance_data.0to60_mph'
                ),

                'insurance_group' => data_get(
                    $specs,
                    'result.insurance_data.insurance_group_1to50'
                ),
            ],

            'valuation' => [
                'retail' => data_get(
                    $valuation,
                    'result.retail_valuation'
                ),

                'trade_in' => data_get(
                    $valuation,
                    'result.part_exchange_valuation'
                ),

                'private' => data_get(
                    $valuation,
                    'result.private_valuation'
                ),

                'trade' => data_get(
                    $valuation,
                    'result.trade_valuation'
                ),
            ],

            'recalls' => [
                'status' => data_get(
                    $recall,
                    'result.recall_status'
                ),

                'source' => data_get(
                    $recall,
                    'result.recall_status_source'
                ),

                'count' => data_get(
                    $recall,
                    'result.recall_qty',
                    0
                ),

                'records' => data_get(
                    $recall,
                    'result.recall_data_items',
                    []
                ),

                'manufacturer_url' => data_get(
                    $recall,
                    'result.manufacturer_recall_check_url'
                ),

                'available' => true,
            ],

            'emissions_compliance' => [
                'fuel_type' => data_get(
                    $specs,
                    'result.basic_vehicle_info.autotrader_fuel_type_desc'
                ),

                'euro_standard' => data_get(
                    $specs,
                    'result.engine_data.emission_class'
                ),

                'meets_minimum_standard' => $compliance[
                    'meets_minimum_standard'
                ] ?? null,

                'assessment_type' => $compliance[
                    'assessment_type'
                ] ?? 'derived',

                'officially_verified' => $compliance[
                    'officially_verified'
                ] ?? false,

                'reason' => $compliance['reason'] ?? null,
            ],

            'running_costs' => [
                'road_tax' => [
                    'first_year' => data_get(
                        $tax,
                        'result.12_month_rfl_y1'
                    ),

                    'annual' => data_get(
                        $tax,
                        'result.12_month_rfl'
                    ),

                    'premium_annual' => data_get(
                        $tax,
                        'result.12_month_rfl_premium'
                    ),

                    'is_premium' => data_get(
                        $tax,
                        'result.is_premium'
                    ),

                    'available' => true,
                ],

                'insurance' => [
                    'age_20' => data_get(
                        $insurance,
                        'result.insurance_cost_20yo'
                    ),

                    'age_30' => data_get(
                        $insurance,
                        'result.insurance_cost_30yo'
                    ),

                    'age_40' => data_get(
                        $insurance,
                        'result.insurance_cost_40yo'
                    ),

                    'age_50' => data_get(
                        $insurance,
                        'result.insurance_cost_50yo'
                    ),

                    'assessment_type' => 'estimate',

                    'available' => true,
                ],

                'fuel' => [
                    'combined_mpg' => data_get(
                        $specs,
                        'result.engine_data.wltp_combined_mpg'
                    ) ?? data_get(
                        $specs,
                        'result.engine_data.nedc_combined_mpg'
                    ),

                    'assessment_type' => 'derived',

                    'available' => true,
                ],
            ],

            'mot' => [
                'service_available' => data_get(
                    $mot,
                    'result.is_dvsa_service_available',
                    false
                ),

                'tax_status' => data_get(
                    $mot,
                    'result.dvla_data.tax_status'
                ),

                'tax_expiry_date' => data_get(
                    $mot,
                    'result.dvla_data.tax_expiry_date'
                ),

                'vehicle' => [
                    'vrm' => data_get(
                        $mot,
                        'result.dvsa_data.dvsa_vehicle_Data.vehicle_registration_mark'
                    ),

                    'make' => data_get(
                        $mot,
                        'result.dvsa_data.dvsa_vehicle_Data.manufacturer_desc'
                    ),

                    'model' => data_get(
                        $mot,
                        'result.dvsa_data.dvsa_vehicle_Data.model_range_desc'
                    ),

                    'first_registration_date' => data_get(
                        $mot,
                        'result.dvsa_data.dvsa_vehicle_Data.first_registration_date'
                    ),

                    'fuel_type' => data_get(
                        $mot,
                        'result.dvsa_data.dvsa_vehicle_Data.fuel_type_desc'
                    ),

                    'colour' => data_get(
                        $mot,
                        'result.dvsa_data.dvsa_vehicle_Data.colour'
                    ),

                    'image_url' => null,

                ],

                'current' => [
                    'mot_status' =>
                        $currentMotStatus,

                    'mot_status_source' =>
                        $currentMotStatus !== null
                            ? 'derived'
                            : 'unavailable',

                    'mot_expiry_date' =>
                        $latestMotExpiry,

                    'tax_service_available' =>
                        (bool) data_get(
                            $mot,
                            'result.is_dvla_service_available',
                            false
                        ),

                    'tax_status' =>
                        data_get(
                            $mot,
                            'result.dvla_data.tax_status'
                        ),

                    'tax_expiry_date' =>
                        data_get(
                            $mot,
                            'result.dvla_data.tax_expiry_date'
                        ),
                ],

                'tests' => collect(
                    data_get(
                        $mot,
                        'result.dvsa_data.mot_tests',
                        []
                    )
                )
                    ->map(fn (array $test) => [
                        'test_number' => $test['mot_test_number'] ?? null,
                        'date' => $test['mot_test_date'] ?? null,
                        'expiry_date' => $test['mot_expiry_date'] ?? null,
                        'result' => $test['mot_test_result'] ?? null,

                        'mileage' => $test['observation_mileage'] ?? null,
                        'mileage_unit' => 'MI',

                        'defects' => collect(
                            $test['reason_for_refusal_and_comments'] ?? []
                        )
                            ->map(fn (array $defect) => [
                                'type' => $defect['type'] ?? null,
                                'description' => $defect['comments'] ?? null,
                                'dangerous' => false,
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),

                'mileage_history' => collect(
                    data_get($mot, 'result.dvsa_data.mot_tests', [])
                )
                    ->map(fn (array $test) => [
                        'date' => $test['mot_test_date'] ?? null,
                        'mileage' => $test['observation_mileage'] ?? null,
                        'result' => $test['mot_test_result'] ?? null,
                    ])
                    ->values()
                    ->all(),
            ],

            'dimensions' => [
                'height_mm' => data_get(
                    $specs,
                    'result.weight_dimension_capacities_data.vehicle_height_mm'
                ),

                'length_mm' => data_get(
                    $specs,
                    'result.weight_dimension_capacities_data.vehicle_length_mm'
                ),

                'width_mm' => data_get(
                    $specs,
                    'result.weight_dimension_capacities_data.vehicle_width_mm'
                ),

                'wheelbase_mm' => data_get(
                    $specs,
                    'result.weight_dimension_capacities_data.vehicle_wheelbase_mm'
                ),

                'kerb_weight_kg' => data_get(
                    $specs,
                    'result.weight_dimension_capacities_data.min_kerbweight_kg'
                ),

                'gross_weight_kg' => data_get(
                    $specs,
                    'result.weight_dimension_capacities_data.gross_vehicleweight_kg'
                ),

                'seats' => data_get(
                    $specs,
                    'result.basic_vehicle_info.number_seats'
                ),

                'doors' => data_get(
                    $specs,
                    'result.basic_vehicle_info.number_doors'
                ),
            ],
        ];
    }

    private function deriveMotStatus(
        ?string $result,
        ?string $expiryDate,
    ): ?string {
        $normalizedResult = strtoupper(
            trim(
                (string) $result
            )
        );

        /*
        * Latest test failed:
        * langsung dianggap failed.
        */
        if (
            str_contains(
                $normalizedResult,
                'FAIL'
            )
        ) {
            return 'Failed';
        }

        /*
        * Kalau punya expiry,
        * kita bisa menentukan valid / expired.
        */
        if ($expiryDate) {
            try {
                $expiry = Carbon::parse(
                    $expiryDate
                )->endOfDay();

                return $expiry->isPast()
                    ? 'Expired'
                    : 'Valid';
            } catch (\Throwable) {
                // fallback below
            }
        }

        /*
        * Kita masih bisa expose latest result,
        * tapi jangan mengarang current validity.
        */
        if (
            $normalizedResult !== ''
        ) {
            return ucfirst(
                strtolower(
                    $normalizedResult
                )
            );
        }

        return null;
    }
}


