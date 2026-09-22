<?php

namespace App\Services\Reports;

use RuntimeException;

class CompositeReportBuilder
{
    public function __construct(private NormalizedReportBuilder $reportBuilder,)
    {
    }
    
    public function build(string $vrm): array
    {
        $vrm = $this->normalizeVrm($vrm);

        $checkCarDetails = $this->reportBuilder->build(
            vrm: $vrm,
            provider: 'checkcardetails',
        );

        $oneAuto = $this->reportBuilder->build(
            vrm: $vrm,
            provider: 'oneauto'
        );

        $ccd = $checkCarDetails['normalized'];
        $one = $oneAuto['normalized'];

        $this->assertSameVehicle(
            requestedVrm: $vrm,
            checkCarDetails: $ccd,
            oneAuto: $one,
        );

       return $this->compose(
            checkCarDetails: $checkCarDetails,
            oneAuto: $oneAuto,
            vrm: $vrm,
            fixture: false,
       );
    }

    public function buildFixture(string $checkCarDetailsVrm, string $oneAutoVrm): array
    {
        $checkCarDetailsVrm = $this->normalizeVrm(
            $checkCarDetailsVrm
        );

        $oneAutoVrm = $this->normalizeVrm(
            $oneAutoVrm
        );

        $checkCarDetails = $this->reportBuilder->build(
            vrm: $checkCarDetailsVrm,
            provider: 'checkcardetails',
        );

        $oneAuto = $this->reportBuilder->build(
            vrm: $oneAutoVrm,
            provider: 'oneauto',
        );

        /*
        * IMPORTANT:
        *
        * Fixture mode SENGAJA tidak memanggil assertSameVehicle()
        * karena sample sandbox masing-masing provider bisa berbeda.
        *
        * Hasilnya hanya untuk development / lab.
        */
        return $this->compose(
            checkCarDetails: $checkCarDetails,
            oneAuto: $oneAuto,

            /*
            * Display identity tetap memakai base provider CCD.
            */
            vrm: $checkCarDetailsVrm,

            fixture: true,
        );
    }

    private function sourceMap(): array
    {
        return config(
            'vehicle-report.hybrid.sources',
            []
        );
    }

    private function assertSameVehicle(
        string $requestedVrm,
        array $checkCarDetails,
        array $oneAuto,
    ): void 
    {
        $ccdVrm = $this->normalizeVrm(
            data_get(
                $checkCarDetails,
                'vehicle.vrm',
                ''
            )
        );

        $oneAutoVrm = $this->normalizeVrm(
            data_get(
                $oneAuto,
                'vehicle.vrm',
                ''
            )
        );

        if ($ccdVrm === '' || $oneAutoVrm === '') {
                throw new RuntimeException('Unable to verify vehicle identity across providers.');
        }

        if ($ccdVrm !== $requestedVrm || $oneAutoVrm !== $requestedVrm) {
             throw new RuntimeException(
                sprintf(
                    'Provider vehicle mismatch. Requested %s, '
                    . 'CheckCarDetails returned %s, '
                    . 'OneAuto returned %s.',
                    $requestedVrm,
                    $ccdVrm,
                    $oneAutoVrm,
                )
            );
        }

        $ccdVin = $this->normalizeVin(
            data_get(
                $checkCarDetails,
                'vehicle.vrm',
                ''
            )
        );

        $oneAutoVin = $this->normalizeVin(
            data_get(
                $oneAuto,
                'vehicle.vrm',
                ''
            )
        );

        if ( $ccdVin !== '' && $oneAutoVin !== '' && $ccdVin !== $oneAutoVin){
            throw new RuntimeException('Provider VIN mismatch. Hybrid report was not generated.');
        }

         $ccdMake = $this->normalizeTextIdentity(
            data_get(
                $checkCarDetails,
                'vehicle.make',
                ''
            )
        );

        $oneAutoMake = $this->normalizeTextIdentity(
            data_get(
                $oneAuto,
                'vehicle.make',
                ''
            )
        );

        if ($ccdMake !== '' && $oneAutoMake !== '' && $ccdMake !== $oneAutoMake) {
            throw new RuntimeException('Provider vehicle make mismatch. Hybrid report was not generated.');
        }
    }

     private function normalizeVrm(
        ?string $vrm
    ): string {
        return strtoupper(
            preg_replace(
                '/\s+/',
                '',
                trim((string) $vrm)
            )
        );
    }

    private function normalizeVin(
        ?string $vin
    ): string {
        return strtoupper(
            preg_replace(
                '/\s+/',
                '',
                trim((string) $vin)
            )
        );
    }

    private function normalizeTextIdentity(
        ?string $value
    ): string {
        return strtoupper(
            preg_replace(
                '/[^A-Z0-9]/i',
                '',
                trim((string) $value)
            )
        );
    }

    private function compose(array $checkCarDetails, array $oneAuto, string $vrm, bool $fixture): array
    {
        $ccd = $checkCarDetails['normalized'];
        $one = $oneAuto['normalized'];

        $sources = $this->sourceMap();

        $sourceProfiles = config(
            'vehicle-report.hybrid.profiles',
            []
        );

        $normalized = [
            'meta' => [
                'schema_version' => 2,
                'format' => 'normalized',

                'provider' => 'hybrid',
                'strategy' => 'hybrid',

                'providers' => [
                    'checkcardetails',
                    'oneauto',
                ],

                'generated_at' =>
                    now()->toISOString(),

                'sources' =>
                    $sources,
                
                'source_profiles' =>
                    $sourceProfiles,

                /*
                * Sangat penting.
                *
                * Frontend bisa membedakan hybrid asli
                * dengan synthetic sandbox composition.
                */
                'lab_fixture' =>
                    $fixture,

                'identity_verified' =>
                    !$fixture,

                /*
                * Hanya muncul untuk fixture mode.
                */
                'fixture_inputs' =>
                    $fixture
                        ? [
                            'checkcardetails' =>
                                $checkCarDetails['vrm'],

                            'oneauto' =>
                                $oneAuto['vrm'],
                        ]
                        : null,

                'warning' =>
                    $fixture
                        ? 'Development fixture composed from different provider sandbox vehicles. Not a real vehicle report.'
                        : null,
            ],

            /*
            * Base vehicle identity.
            */
            'vehicle' =>
                $ccd['vehicle'],

            /*
            * Critical / provenance data.
            */
            'status' =>
                $one['status'],

            'v5c' =>
                $ccd['v5c'],

            'history' => [
                'finance' =>
                    $one['history']['finance'],

                'stolen' =>
                    $one['history']['stolen'],

                'write_off' =>
                    $one['history']['write_off'],

                'high_risk' =>
                    $one['history']['high_risk'],

                'keepers' =>
                    $one['history']['keepers'],

                'plate_changes' =>
                    $one['history']['plate_changes'],

                'colour_changes' =>
                    $ccd['history']['colour_changes'],
            ],

            'salvage' =>
                $one['salvage'],

            'specifications' =>
                $ccd['specifications'],

            'dimensions' =>
                $ccd['dimensions'],

            'valuation' =>
                $ccd['valuation'],

            'recalls' =>
                $one['recalls'],

            'emissions_compliance' =>
                $ccd['emissions_compliance'],

            'running_costs' => [
                'road_tax' =>
                    $ccd['running_costs']['road_tax'],

                'insurance' =>
                    $one['running_costs']['insurance'],

                'fuel' =>
                    $ccd['running_costs']['fuel'],
            ],

            'mot' =>
                $ccd['mot'],
        ];

        return [
            'provider' =>
                'hybrid',

            'vrm' =>
                $vrm,

            'meta' => [
                'strategy' =>
                    'hybrid',

                'lab_fixture' =>
                    $fixture,

                'identity_verified' =>
                    !$fixture,

                'providers' => [
                    'checkcardetails',
                    'oneauto',
                ],

                'sources' =>
                    $sources,
            ],

            'normalized' =>
                $normalized,

            'raw' => [
                'checkcardetails' =>
                    $checkCarDetails['raw'],

                'oneauto' =>
                    $oneAuto['raw'],
            ],
        ];
    }
}
