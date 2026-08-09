<?php

namespace App\Jobs;

use App\Models\CreditTransaction;
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
        private ?int $upgradeReportId = null,
    ){}

    public function handle(VehicleDataProviderInterface $provider): void
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

                try{
                    $historyData = $provider->getHistoryCheck($regNumber);
                    $actualReportType = 'premium';
                } catch (\Throwable $e) {
                    Log::warning('Premium history check failed', [
                        'vin_check_id' => $vinCheck->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                
                $premiumData = [];

                foreach ([
                    'history' => fn () => $provider->getHistoryCheck($regNumber),
                    'mileage' => fn () => $provider->getMileageHistory($regNumber),
                    'image' => fn () => $provider->getVehicleImage($regNumber),
                    'valuation' => fn () => $provider->getVehicleValuation($regNumber),
                ] as $key => $call) {
                    try {
                        $premiumData[$key] = $call();
                    } catch (\Throwable $e) {
                        Log::warning("Premium data point '{$key}' failed", [
                            'vin_check_id' => $vinCheck->id,
                            'error' => $e->getMessage(),
                        ]);
                        $premiumData[$key] = null; // ditandai gagal, bukan bikin seluruh proses berhenti
                    }
                }

                $historyData = array_filter($premiumData); // buang yang null
                $actualReportType = !empty($premiumData['history']) ? 'premium' : 'basic';
            }

            $vinCheck->update(['stage' => 'finalizing']);

            $mergeData = array_merge($registrationData, $historyData);

            $vehicle = Vehicle::updateOrCreate(
                ['registration_number' => $regNumber],
                $this->mapApiDataToVehicle($mergeData),
            );

            DB::Transaction(function () use ($vinCheck, $vehicle, $isPremium, $mergeData, $actualReportType) {
                    if ($this->upgradeReportId) {
                        Report::where('id', $this->upgradeReportId)->update([
                        'report_data' => $mergeData,
                        'report_type' => $actualReportType,
                    ]);
                    $report = Report::find($this->upgradeReportId);
                } else {
                    $report = Report::create([
                    'user_id' => $vinCheck->user_id,
                    'vehicle_id' => $vehicle->id,
                    'vin_check_id' => $vinCheck->id,
                    'vin' => $vehicle->vin,
                    'report_data' => $mergeData,
                    'report_type' => $actualReportType,
                    'generated_at' => now(),
                ]);
            }
                if ($actualReportType === 'premium' && $isPremium && $vinCheck->user_id) {
                    $user = $vinCheck->user;
                    $subscription = $user->activeSubscription();

                    if($subscription) {
                        $subscription->increment('reports_used');
                    } else {
                        $newBalance = $user->credits - 1;
                        $user->update(['credits' => $newBalance]);

                    CreditTransaction::create([
                        'user_id' => $user->id,
                        'type' => 'usage',
                        'amount' => -1,
                        'balance_after' => $newBalance,
                        'reference_id' => (string) $report->id,
                        'description' => "Vehicle check: {$vinCheck->registration_number}",
                    ]);
                }
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
            'raw_api_response' => $apiData,
            'last_refreshed_at' => now(),
        ];
    }
}
