<?php

namespace App\Http\Controllers;

use App\Http\Requests\VehicleCheckRequest;
use App\Jobs\ProcessVehicleCheck;
use App\Models\Report;
use App\Models\VinCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class VehicleCheckController extends Controller
{
    public function store(VehicleCheckRequest $request): RedirectResponse
    {
        $user = $request->user();
        $regNumber = $request->validated('registration_number');

        $isPremiumEligible = $user && $user->canPerformCheck();

        if (! $isPremiumEligible) {
            $key = $user
                ? 'free-check:user'.$user->id
                : 'free-check:ip'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, maxAttempts: 3)) {
                return back()->withErrors(['registration_number' => $user
                ? 'Batas cek gratis harian tercapai.'.'Upgrade akun untuk cek lebih lanjut.'
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

    public function loading(VinCheck $vinCheck): InertiaResponse|RedirectResponse
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

    public function unlock(Report $report, Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return back()->with('modal', 'login_required');
        }

        // An authenticated user cannot unlock another user's report,
        // and unowned guest reports cannot be claimed merely by providing their report ID.
        if ($report->user_id === null || (int) $report->user_id !== (int) $user->id) {
            abort(403);
        }

        $vinCheck = $report->vinCheck;

        if (! $vinCheck || ($vinCheck->user_id !== null && (int) $vinCheck->user_id !== (int) $user->id)) {
            abort(403);
        }

        if ($report->report_type === 'premium') {
            return redirect()->route('page.my-report.show', $report->id);
        }

        if (! $user->canPerformCheck()) {
            return back()->with('modal', 'credits_exhausted');
        }

        $vinCheck->update([
            'user_id' => $user->id,
            'check_type' => 'premium',
            'status' => 'pending',
            'stage' => 'queued',
        ]);

        ProcessVehicleCheck::dispatch($vinCheck->id, $report->id);

        return redirect()->route('vehicle-check.loading', $vinCheck->id);
    }
}
