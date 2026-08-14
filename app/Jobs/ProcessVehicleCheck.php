<?php

namespace App\Jobs;

use App\Services\CreditService;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Contracts\VehicleDataProviderInterface;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessVehicleCheck implements ShouldQueue
{
    use Queueable, InteractsWithQueue, Dispatchable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;
    public function __construct(
        private int $vinCheckId,
        private ?int $existingReportId = null,
    ){}

    public function handle(VehicleDataProviderInterface $provider, CreditServices $creditService): void
    {
        $vinCheck = VinCheck::findOrFail($this->vinCheckId);
        $isPremium = $vinCheck->check_type === 'premium';
        $regNumber = $vinCheck->registration_number;

        try {
            
            $vinCheck->update(['stage' => 'connecting']);
            $registrationData= $provider->getRegistrationDetails($regNumber);

            $historyData= [];
            $actualReportType= 'basic';


            if ($isPremium) {
                $vinCheck->update(['stage' => 'verifying_history']);
                
                $premiumData = [];

                try {
                    $fullDataResponse = $provider->getFullVehicleData($regNumber);
                    $premiumData['ColourChangeDetails'] = $fullDataResponse['VehicleHistory']['ColourChangeDetails'] ?? null;
                    $premiumData['ColourChangeList'] = $fullDataResponse['VehicleHistory']['ColourChangeList'] ?? null;
                } catch (\Throwable $e) {
                    Log::warning("Premium data point 'ukvehicledata' failed", [
                        'vin_check_id' => $vinCheck->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                try {
                    $historyResponse = $provider->getHistoryCheck($regNumber);
                    $premiumData['VehicleRegistration'] = $historyResponse['VehicleRegistration'] ?? null;
                    $premiumData['VehicleHistory'] = $historyResponse['VehicleHistory'] ?? null;
                    $premiumData['Dimensions'] = $historyResponse['Dimensions'] ?? null; 
                } catch (\Throwable $e) {
                    Log::warning("Premium data point 'carhistorycheck' failed", [
                        'vin_check_id' => $vinCheck->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                try{
                    $mileageResponse = $provider->getMileageHistory($regNumber);
                    $premiumData['summary'] = $mileageResponse['summary'] ?? null;
                } catch (\Throwable $e){
                    Log::warning("Premium data point 'mileage' failed", [
                        'vin_check_id' => $vinCheck->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                try{
                    $imageResponse = $provider->getVehicleImage($regNumber);
                    $premiumData['VehicleImages'] = $imageResponse['VehicleImages'] ?? null;
                } catch (\Throwable $e){
                    Log::warning("Premium data point 'vehicleimage' failed", [
                        'vin_check_id' => $vinCheck->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                try {
                    $valuationResponse = $provider->getVehicleValuation($regNumber);
                    $premiumData['ValuationList'] = $valuationResponse['ValuationList'] ?? null;
                } catch (\Throwable $e) {
                    Log::warning("Premium data point 'vehiclevaluation' failed", [
                        'vin_check_id' => $vinCheck->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                try {
                    $motResponse = $provider->getMotHistory($regNumber);
                    $premiumData['motHistory'] = $motResponse['motHistory'] ?? null;
                    $premiumData['motHistorySummary'] = $motResponse['motHistorySummary'] ?? null;
                } catch (\Throwable $e) {
                    Log::warning("Premium data point 'mot' failed", [
                        'vin_check_id' => $vinCheck->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $historyData = array_filter($premiumData, fn ($v) => $v !== null && $v !== []);
                $actualReportType = !empty($historyData) ? 'premium' : 'basic';
            }

            $vinCheck->update(['stage' => 'finalizing']);
            $mergeData = array_merge($registrationData, $historyData);

            $vehicle = Vehicle::updateOrCreate(
                ['registration_number' => $regNumber],
                $this->mapApiDataToVehicle($mergeData),
            );

            DB::transaction(function () use ($vinCheck, $vehicle, $isPremium, $actualReportType, $mergeData) {
                if ($this->existingReportId) {
                    $report = Report::findOrFail($this->existingReportId);
                    $report->update([
                        'vehicle_id' => $vehicle->id,
                        'report_data' => $mergeData,
                        'report_type' => $isPremium ? 'premium' : 'basic',
                        'vin_check_id' => $vinCheck->id,
                    ]);
                } else {
                    $report = Report::create([
                        'user_id' => $vinCheck->user_id,
                        'vehicle_id' => $vehicle->id,
                        'vin_check_id' => $vinCheck->id,
                        'vin' => $vehicle->vin,
                        'report_data' => $mergeData,
                        'report_type' => $isPremium ? 'premium' : 'basic',
                        'generated_at' => now(),
                    ]);
                }

                if ($actualReportType === 'premium' && $isPremium && $vinCheck->user_id) {
                    $creditService->consume(
                        user: $vinCheck->user,
                        referenceId: (string) $report->id,
                        description: "Vehicle check: {$vinCheck->registration_number}",
                    );
                }

                $vinCheck->update(['stage' => 'completed', 'status' => 'success']);
            });

        } catch (\Throwable $e) {
            Log::error('Vehicle check failed', [
                'vin_check_id' => $vinCheck->id,
                'error' => $e->getMessage(),
            ]);

            $vinCheck->update(['stage' => 'failed','status' => 'failed']);

            // Jangan throw lagi kalau ini percobaan terakhir — biar nggak retry sia-sia
            if ($this->attempts() >= $this->tries) {
                return;
            }

            throw $e; // lempar lagi biar Laravel retry otomatis
        }
    }

    private function mapApiDataToVehicle(array $apiData): array
    {
        return [
            'brand' => $apiData['make'] ?? null,
            'model' => $apiData['model'] ?? null,
            'colour' => $apiData['colour'] ?? null,
            'fuel_type' => $apiData['fuelType'] ?? null,
            'engine_capacity' => $apiData['engineCapacity'] ?? null,
            'year' => $apiData['yearOfManufacture'] ?? null,
            'year_of_manufacture' => $apiData['yearOfManufacture'] ?? null,
            'co2_emissions' => $apiData['co2Emissions'] ?? null,
            'tax_status' => $apiData['tax']['taxStatus'] ?? null,
            'mot_expiry_date' => $apiData['mot']['motDueDate'] ?? null,
            'outstanding_finance' => !empty($apiData['financeRecord']),
            'write_off_category' => $apiData['writeoff'][0]['status'] ?? null,
            'vin'=> $apiData['VehicleRegistration']['Vin'] ?? $apiData['vin'] ?? 'NOT FOUND',
            'image_url' => $apiData['VehicleImages']['ImageDetailsList'][0]['ImageUrl'] ?? 'NOT FOUND',
            'raw_api_response' => $apiData,
            'last_refreshed_at' => now(),
        ];
    }
}
