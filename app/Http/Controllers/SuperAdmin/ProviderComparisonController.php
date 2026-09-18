<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\EmissionsComplianceService;
use App\Services\Reports\CheckCarDetailsReportNormalizer;
use App\Services\Reports\OneAutoReportNormalizer;
use App\Services\VehicleDataService;
use App\Services\VehicleProviders\OneAutoProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ProviderComparisonController extends Controller
{
    public function __construct(
        private VehicleDataService $checkCarDetails,
        private OneAutoProvider $oneAuto,
        private CheckCarDetailsReportNormalizer $checkCarDetailsNormalizer,
        private OneAutoReportNormalizer $oneAutoNormalizer,
        private EmissionsComplianceService $emissionsCompliance,
    ){

    }
    public function index(): Response
    {
        return Inertia::render('super-admin/provider-lab', [
            'providers' => [
                [
                    'id' => 'checkcardetails',
                    'name' => 'CheckCarDetails',
                ],
                [
                    'id' => 'oneauto',
                    'name' => 'OneAuto',
                ],
            ],

            'csrfToken' => csrf_token(),
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => [
                'required',
                'in:checkcardetails,oneauto',
            ],
            'vrm' => [
                'required',
                'string',
                'max:10',
            ],
        ]);

        $vrm = strtoupper(preg_replace('/\s+/', '', $validated['vrm']));

        $startedAt = microtime(true);

        try{
            $result = match ($validated['provider']) {
                'checkcardetails' => $this->runCheckCarDetails($vrm),
                'oneauto'=> $this->runOneAuto($vrm),
            };

            return response()->json([
                'provider' => $validated['provider'],
                'vrm' => $vrm,

                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),

                'meta' => $result['meta'] ?? [],
                'normalized' => $result['normalized'],
                'raw' => $result['raw'],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    private function runCheckCarDetails(string $vrm): array
    {
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

        $compliance = $this->emissionsCompliance->assess(
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
                $this->checkCarDetailsNormalizer->normalize($raw),

            'raw' => $raw,

            'meta' => [
                'provider' => 'checkcardetails',
            ],
        ];
    }

    private function runOneAuto(string $vrm): array
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
         * OneAuto valuation membutuhkan mileage.
         * Ambil observation mileage dari MOT terbaru.
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
            $latestMot['observation_mileage'] ?? 0
        );

        $valuation = $this->oneAuto->getValuation(
            $vrm,
            $valuationMileage
        );

        $derivativeId = data_get(
            $valuation,
            'result.autotrader_derivative_id'
        );

        /*
         * Specs endpoint butuh effective_date.
         * First registration date sudah ada dari AutoCheck.
         */
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
            ? $this->oneAuto->getAutoTraderSpecs(
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
            ? $this->oneAuto->getInsuranceCosts(
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

        $compliance = $this->emissionsCompliance->assess(
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
                $this->oneAutoNormalizer->normalize($raw),

            'raw' => $raw,

            'meta' => [
                'provider' => 'oneauto',
                'environment' =>
                    config('services.oneauto.environment'),

                'valuation_mileage' =>
                    $valuationMileage,

                'effective_date' =>
                    $effectiveDate,
            ],
        ];
    }
}
