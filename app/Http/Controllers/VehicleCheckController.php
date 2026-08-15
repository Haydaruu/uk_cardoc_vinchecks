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

        $isPremiumEligible = $user && $user->canPerformCheck();

        if (!$isPremiumEligible) {
            $key = $user
                ? 'free-check:user' . $user->id
                : 'free-check:ip' . $request->ip();
            if (RateLimiter::tooManyAttempts($key, maxAttempts: 3)) {
                return back()->withErrors(['registration_number' => $user 
                ? 'Batas cek gratis harian tercapai.'. 'Upgrade akun untuk cek lebih lanjut.'
                : 'The daily limit for free checks has been reached.']);
            }
            RateLimiter::hit($key, decaySeconds: 86400);
        }

        $vinCheck = VinCheck::create([
            'user_id' => $user?->id,
            'registration_number' => $regNumber,
            'check_type' => $isPremiumEligible ? 'premium' : 'free', 
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
        return Inertia::render('user/vehicle-check/loading', [
            'vinCheck' => [
                'id' => $vinCheck->id,
                'stage' => $vinCheck->stage,
                'status' => $vinCheck->status,
                'check_type' => $vinCheck->check_type,
                'registration_number' => $vinCheck->registration_number,
            ],
        ]);
    }

    public function unlock(Report $report, Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return back()->with('modal', 'login_required');
        }

        if ($report->report_type === 'premium'){
            return redirect()->route('page.my-report.show', $report->id);
        }

        if (!$user->canPerformCheck()) {
            return back()->with('modal', 'credits_exhausted');
        }


        $vinCheck = $report->vinCheck;

        $vinCheck->update([
            'user_id' => $vinCheck->user_id ?? $user->id,
            'check_type' => 'premium',
            'status' => 'pending',
            'stage' => 'queued',
        ]);

        ProcessVehicleCheck::dispatch($vinCheck->id,  $report->id);

        return redirect()->route('vehicle-check.loading', $vinCheck->id);
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
