<?php

namespace App\Services\Reports;

use RuntimeException;
use Illuminate\Support\Facades\Log;

class CompositeReportBuilder
{
    public function __construct(private NormalizedReportBuilder $reportBuilder,)
    {
    }
    
   public function build(string $vrm): array
    {
        $vrm = $this->normalizeVrm($vrm);

        $results = [];
        $failures = [];

        foreach (['checkcardetails', 'oneauto'] as $provider) {
            try {
                $results[$provider] = $this->reportBuilder->build(
                    vrm: $vrm,
                    provider: $provider,
                );
            } catch (\RuntimeException $e) {
                $failures[$provider] = $e;

                Log::warning(
                    'Composite report provider failed.',
                    [
                        'vrm' => $vrm,
                        'provider' => $provider,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ]
                );
            }
        }

        if ($results === []) {
            $failedProviders = implode(
                ', ',
                array_keys($failures)
            );

            $firstFailure = reset($failures);

            throw new \RuntimeException(
                "No provider could build a verified report for {$vrm}. "
                    . "Failed providers: {$failedProviders}",
                0,
                $firstFailure ?: null,
            );
        }

        if (
            !isset($results['checkcardetails'])
            || !isset($results['oneauto'])
        ) {
            $provider = array_key_first($results);

            return $this->buildSingleProviderFallback(
                built: $results[$provider],
                provider: $provider,
                vrm: $vrm,
                failedProviders: array_keys($failures),
            );
        }

        $checkCarDetails = $results['checkcardetails'];
        $oneAuto = $results['oneauto'];

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

    private function buildSingleProviderFallback(
        array $built,
        string $provider,
        string $vrm,
        array $failedProviders,
    ): array {
        $normalized = $built['normalized'];
        $normalizedMeta = $normalized['meta'] ?? [];

        $sources = [];

        foreach (array_keys($normalized) as $section) {
            if ($section !== 'meta') {
                $sources[$section] = $provider;
            }
        }

        $normalized['meta'] = [
            ...$normalizedMeta,
            'schema_version' => $normalizedMeta['schema_version'] ?? 2,
            'format' => $normalizedMeta['format'] ?? 'normalized',
            'provider' => 'hybrid',
            'strategy' => 'hybrid_fallback',
            'providers' => [$provider],
            'sources' => $sources,
            'identity_verified' => true,
            'fallback' => [
                'used' => true,
                'successful_provider' => $provider,
                'failed_providers' => $failedProviders,
            ],
        ];

        return [
            'provider' => 'hybrid',
            'vrm' => $vrm,
            'meta' => [
                'strategy' => 'hybrid_fallback',
                'providers' => [$provider],
                'identity_verified' => true,
                'failed_providers' => $failedProviders,
            ],
            'normalized' => $normalized,
            'raw' => [
                $provider => $built['raw'] ?? [],
            ],
        ];
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
                'vehicle.vin',
                ''
            )
        );

        $oneAutoVin = $this->normalizeVin(
            data_get(
                $oneAuto,
                'vehicle.vin',
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

    private function sectionIsAvailable(mixed $section): bool
    {
        if ($section === null) {
            return false;
        }

        if (is_string($section)) {
            return trim($section) !== '';
        }

        if (! is_array($section)) {
            return true;
        }

        if (array_key_exists('available', $section)) {
            return $section['available'] === true;
        }

        return $this->hasMeaningfulValue($section);
    }

    private function mergeMissingFields(
        mixed $preferred,
        mixed $fallback,
        string $fallbackProvider,
        string $path,
        array &$sourceOverrides,
    ): mixed {
        // Hanya gabungkan object/array asosiatif.
        // Array berisi daftar records tidak digabung sembarangan.
        if (
            ! is_array($preferred)
            || ! is_array($fallback)
            || array_is_list($preferred)
            || array_is_list($fallback)
        ) {
            return $preferred;
        }

        foreach ($fallback as $key => $fallbackValue) {
            $fieldPath = $path . '.' . $key;

            // Field sama sekali belum tersedia di provider utama.
            if (! array_key_exists($key, $preferred)) {
                if ($this->hasMeaningfulValue($fallbackValue)) {
                    $preferred[$key] = $fallbackValue;
                    $sourceOverrides[$fieldPath] = $fallbackProvider;
                }

                continue;
            }

            $preferredValue = $preferred[$key];

            // Null atau string kosong boleh diisi dari provider cadangan.
            // Nilai 0 dan false tetap dianggap sebagai nilai yang valid.
            $isMissing = $preferredValue === null
                || (
                    is_string($preferredValue)
                    && trim($preferredValue) === ''
                );

            if ($isMissing) {
                if ($this->hasMeaningfulValue($fallbackValue)) {
                    $preferred[$key] = $fallbackValue;
                    $sourceOverrides[$fieldPath] = $fallbackProvider;
                }

                continue;
            }

            // Jika keduanya berupa object asosiatif, periksa field turunannya.
            if (
                is_array($preferredValue)
                && is_array($fallbackValue)
                && ! array_is_list($preferredValue)
                && ! array_is_list($fallbackValue)
            ) {
                $preferred[$key] = $this->mergeMissingFields(
                    preferred: $preferredValue,
                    fallback: $fallbackValue,
                    fallbackProvider: $fallbackProvider,
                    path: $fieldPath,
                    sourceOverrides: $sourceOverrides,
                );
            }
        }

        return $preferred;
    }

    private function hasMeaningfulValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (! is_array($value)) {
            return true;
        }

        foreach ($value as $key => $nestedValue) {
            if (in_array($key, [
                'available',
                'verification_available',
            ], true)) {
                continue;
            }

            if ($this->hasMeaningfulValue($nestedValue)) {
                return true;
            }
        }

        return false;
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

        $providerData = [
            'checkcardetails' => $ccd,
            'oneauto' => $one,
        ];

        $actualSources = $sources;

        // Fixture memakai data contoh yang bisa berasal dari
        // kendaraan berbeda. Jangan menerapkan fallback produksi
        // pada fixture Comparison Lab.
        if (! $fixture) {
            $sourceOverrides = [];
            foreach ($sources as $path => $preferredProvider) {
                $fallbackProvider = $preferredProvider === 'checkcardetails'
                    ? 'oneauto'
                    : 'checkcardetails';

                $preferredSection = data_get(
                    $providerData[$preferredProvider] ?? [],
                    $path
                );

                $fallbackSection = data_get(
                    $providerData[$fallbackProvider] ?? [],
                    $path
                );

                if ($this->sectionIsAvailable($preferredSection)) {
                    $resolvedSection = $preferredSection;

                    if (
                        $this->sectionIsAvailable($fallbackSection)
                        && is_array($preferredSection)
                        && is_array($fallbackSection)
                    ) {
                        $resolvedSection = $this->mergeMissingFields(
                            preferred: $preferredSection,
                            fallback: $fallbackSection,
                            fallbackProvider: $fallbackProvider,
                            path: $path,
                            sourceOverrides: $sourceOverrides,
                        );
                    }

                    data_set(
                        $normalized,
                        $path,
                        $resolvedSection
                    );

                    $actualSources[$path] = $preferredProvider;
                } elseif ($this->sectionIsAvailable($fallbackSection)) {
                    data_set(
                        $normalized,
                        $path,
                        $fallbackSection
                    );

                    $actualSources[$path] = $fallbackProvider;
                } else {
                    data_set(
                        $normalized,
                        $path,
                        $preferredSection
                    );

                    $actualSources[$path] = null;
                }
            }
        }

        $normalized['meta']['sources'] = $actualSources;

        $normalized['meta']['source_overrides'] = $sourceOverrides;

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
                    $actualSources,
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
