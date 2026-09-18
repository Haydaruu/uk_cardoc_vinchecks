<?php

namespace App\Services\VehicleProviders;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OneAutoProvider
{
    public function getVehicleDetails(string $vrm): array
    {
        $response = Http::acceptJson()
            ->withHeaders([
                'x-api-key' => config('services.oneauto.api_key'),
            ])
            ->get(
                rtrim(config('services.oneauto.base_url'), '/')
                .'/ukvehicledata/vehicledetailsfromvrm/v2',
                [
                    'vehicle_registration_mark' => strtoupper($vrm),
                ]
            );
        if($response->failed()) {
            throw new RuntimeException('OneAuto vehicle details request failed: '. $response->body());
        }

        return $response->json();
    }

    public function getAutoCheck(string $vrm, ?string $vin = null): array
    {
        $params = [
            'vehicle_registration_mark' => strtoupper($vrm),
        ];

        if($vin) {
            $params['vehicle_identification_number'] = $vin;
        }

        $response = Http::acceptJson()
            ->withHeaders([
                'x-api-key' => config('services.oneauto.api_key'),
            ])
            ->get(
                rtrim(config('services.oneauto.base_url'), '/')
                .'/experian/autocheck/v3',
                $params
            );
        
        if ($response->failed()) {
            throw new RuntimeException('OneAuto AutoCheck request failed: '. $response->body());
        }

        return $response->json();
    }

    public function getSalvageCheck(string $vrm): array
    {
        $response = Http::acceptJson()
            ->withHeaders([
                'x-api-key' => config('services.oneauto.api_key'),
            ])
            ->get(
                rtrim(config('services.oneauto.base_url'), '/')
                . '/carguide/salvagecheck/v2',
                [
                    'vehicle_registration_mark' => strtoupper($vrm),
                ]
            );

        if($response->failed()) {
            throw new RuntimeException(
                'OneAuto salvage check request failed: '. $response->body()
            );
        }

        return $response->json();
    }

    public function  getRecallCheck(string $vrm): array
    {
            $response = Http::acceptJson()
            ->withHeaders([
                'x-api-key' => config('services.oneauto.api_key'),
            ])
            ->get(
                rtrim(config('services.oneauto.base_url'), '/')
                . '/oneauto/combinedrecallcheckfromvrm/v2',
                [
                    'vehicle_registration_mark' => strtoupper($vrm),
                ]
            );

        if($response->failed()) {
            throw new RuntimeException(
                'OneAuto recall check request failed: '. $response->body()
            );
        }

        return $response->json();
    }

    public function getVehicleAndModelDetails(string $vrm): array
    {
        $response = Http::acceptJson()
            ->withHeaders([
                'x-api-key' => config('services.oneauto.api_key'),
            ])
            ->get(
                rtrim(config('services.oneauto.base_url'), '/')
                . '/ukvehicledata/vehicleandmodeldetailsfromvrm/v2',
                [
                    'vehicle_registration_mark' => strtoupper($vrm),
                ]
            );

        if($response->failed()) {
            throw new RuntimeException(
                'OneAuto vehicle and model details request failed: '
                . $response->body()
            );
        }

        return $response->json();
    }

    public function getValuation(string $vrm, int $mileage): array
    {
        $response = Http::acceptJson()
            ->withHeaders([
                'x-api-key' => config('services.oneauto.api_key'),
            ])
            ->get(
                rtrim(config('services.oneauto.base_url'), '/')
                . '/autotrader/valuationfromvrm/v2',
                [
                    'vehicle_registration_mark' => strtoupper($vrm),
                    'current_mileage' => $mileage,
                ]
            );

        if($response->failed()) {
            throw new RuntimeException(
                'OneAuto valuation request failed: '. $response->body()
            );
        }

        return $response->json();
    }

    public function getInsuranceCosts(string $insuranceGroup, string $fuelType): array
    {
            $response = Http::acceptJson()
            ->withHeaders([
                'x-api-key' => config('services.oneauto.api_key'),
            ])
            ->get(
                rtrim(config('services.oneauto.base_url'), '/')
                . '/oneauto/insurancecosts/v2',
                [
                    'insurance_group_1to50' => $insuranceGroup,
                    'fuel_type_desc' => $fuelType,
                ]
            );

        if($response->failed()) {
            throw new RuntimeException(
                'OneAuto insurance costs request failed: '
                . $response->body()
            );
        }

        return $response->json();
    }

    public function getAutoTraderSpecs(string $derivativeId, string $effectiveDate): array 
    {
        $response = Http::acceptJson()
            ->withHeaders([
                'x-api-key' => config('services.oneauto.api_key'),
            ])
            ->get(
                rtrim(config('services.oneauto.base_url'), '/')
                . '/autotrader/specandtechdatafromid/v2',
                [
                    'autotrader_derivative_id' => $derivativeId,
                    'effective_date' => $effectiveDate,
                ]
            );

        if($response->failed()) {
            throw new RuntimeException(
                'OneAuto AutoTrader specs request failed: '
                . $response->body()
            );
        }

        return $response->json();
    }

    public function getVehicleTax(string $vrm, ?float $listPrice = null): array
    {
        $params = ['vehicle_registration_mark' => strtoupper($vrm),];

        if($listPrice !== null) {
            $params['list_price_inc_options_delivery_vat'] = $listPrice;
        }

        $response = Http::acceptJson()
            ->withHeaders([
                'x-api-key' => config('services.oneauto.api_key'),
            ])
            ->get(
                rtrim(config('services.oneauto.base_url'), '/')
                . '/oneauto/vehicletaxfromvrm/v2',
                $params
            );

        if($response->failed()) {
            throw new RuntimeException('OneAuto vehicle tax request failed: '. $response->body());
        }

        return $response->json();
    }

    public function getMotHistory(string $vrm): array
    {
        $response = Http::acceptJson()
            ->withHeaders([
                'x-api-key' => config('services.oneauto.api_key'),
            ])
            ->get(
                rtrim(config('services.oneauto.base_url'), '/')
                . '/oneauto/mothistoryandtaxstatus/v2',
                [
                    'vehicle_registration_mark' => strtoupper($vrm),
                ]
            );

        if ($response->failed()) {
            throw new RuntimeException('OneAuto MOT history request failed: '. $response->body());
        }

        return $response->json();
    }
}
