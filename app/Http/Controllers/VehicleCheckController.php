<?php

namespace App\Http\Controllers;

use App\Contracts\VehicleDataProviderInterface;
use App\Http\Requests\VehicleCheckRequest;
use App\Models\CreditTransaction;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use App\Jobs\ProcessVehicleCheck;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VehicleCheckController extends Controller
{
    public function __construct(
        private VehicleDataProviderInterface $vehicleData,
    ) {}

    public function store(VehicleCheckRequest $request)
    {
        $user = $request->user();
        $regNumber = $request->validated('registration_number');
        $tier = $user ? 'premium' : 'free';

        if($user && !$user->canPerformCheck()){
            return redirect()->route('page.pricing')->with('error', 'Kuota Habis. Silahkan top up atau upgrade.');
        }

        if (!$user) {
            $key = 'guest-check: ' .$request->ip();
            if (RateLimiter::tooManyAttempts($key, maxAttempts: 3)) {
                return back()->withErrors(['registration_number' => 'Batas cek gratis harian tercapai. Silakan login untuk cek lebih lanjut.']);
            }
            RateLimiter::hit($key, decaySeconds:86400);
        } elseif ($user->credits < 1) {
            return redirect()->route('page.pricing')
                ->with('error', 'Credit habis');
        }

        $vinCheck = VinCheck::create([
            'user_id' => $user?->id,
            'registration_number' => $regNumber,
            'check_type' => $tier === 'premium' ? 'premium' : 'free',
            'ip_address' => $request->ip(),
            'status' => 'pending',
            'stage' => 'queued',
        ]);
        
        ProcessVehicleCheck::dispatch($vinCheck->id);

        return redirect()->route('vehicle-check.loading', $vinCheck->id);

    }

    public function loading(VinCheck $vinCheck)
    {
        if ($vinCheck->status === 'success') {
            $report = $vinCheck->reports()->latest()->first();
            return redirect()->route('page.my-report.show', $report->id);
        }
        return Inertia::render('VehicleCheck/Loading', [
            'vinCheck' => [
                'id' => $vinCheck->id,
                'stage' => $vinCheck->stage,
                'status' => $vinCheck->status,
                'check_type' => $vinCheck->check_type,
                'registration_number' => $vinCheck->registration_number,
            ],
        ]);
    }

    private function mapApiToVehicle(array $apiData): array
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
            'raw_api_response' => $apiData,
            'last_refreshed_at' => now(),
        ];
    }
}
