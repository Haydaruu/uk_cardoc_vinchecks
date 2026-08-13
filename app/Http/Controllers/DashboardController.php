<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Report;
use App\Models\VinCheck;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
 
        return Inertia::render('user/dashboard', [
            'recentReports' => $user ? $this->recentReports($user) : [],
            'recentSearches' => $user ? $this->recentSearches($user): [],
        ]);
    }

    private function recentReports($user)
    {
         return Report::with('vehicle')
            ->where('user_id', $user->id)
            ->whereNotNull('vehicle_id') // saring report lama yang vehicle_id-nya null (dari bug sebelum fix)
            ->latest('generated_at')
            ->take(2)
            ->get()
            ->map(fn (Report $report) => [
                'id' => $report->id,
                'make' => $report->vehicle->brand,
                'model' => $report->vehicle->model,
                'registration' => $report->vehicle->registration_number,
                'checkedOn' => optional($report->generated_at)->format('d M Y'),
                'imageUrl' => $report->vehicle->image_url,
                // report_type ikut dikirim supaya frontend bisa nampilin badge
                // "Basic"/"Premium" kalau nanti dibutuhkan, tanpa harus nebak dari status.
                'reportType' => $report->report_type,
                'status' => ($report->vehicle->outstanding_finance || $report->vehicle->write_off_category)
                    ? 'alert'
                    : 'completed',
                'badges' => [
                    [
                        'label' => $report->vehicle->outstanding_finance ? 'Finance Recorded' : 'No Finance',
                        'tone' => $report->vehicle->outstanding_finance ? 'alert' : 'neutral',
                    ],
                    [
                        'label' => $report->vehicle->write_off_category ?? 'Clear',
                        'tone' => $report->vehicle->write_off_category ? 'alert' : 'neutral',
                    ],
                ],
            ]);
    }

    private function recentSearches($user)
    {
        return VinCheck::where('user_id', $user->id)
            ->latest()
            ->pluck('registration_number')
            ->unique()
            ->take(2)
            ->values();
    }
}
