<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\User;
use App\Models\VinCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DashboardController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        return Inertia::render('user/dashboard', [
            'recentReports' => $user ? $this->recentReports($user) : [],
            'recentSearches' => $user ? $this->recentSearches($user) : [],
        ]);
    }

    /**
     * @return Collection<int, array{
     *     id: int,
     *     make: string|null,
     *     model: string|null,
     *     registration: string|null,
     *     checkedOn: string|null,
     *     imageUrl: string|null,
     *     reportType: 'basic'|'full'|'premium'|null,
     *     status: 'alert'|'completed',
     *     badges: array{
     *         0: array{label: 'Finance Recorded'|'No Finance', tone: 'alert'|'neutral'},
     *         1: array{label: string, tone: 'alert'|'neutral}
     *     }
     * }>
     */
    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentReports(User $user): array
    {
        return Report::with('vehicle')
            ->where('user_id', $user->id)
            ->whereNotNull('vehicle_id')
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
            ])
            ->all();
    }

    /**
     * @return Collection<int, string>
     */
    private function recentSearches(User $user): Collection
    {
        return VinCheck::where('user_id', $user->id)
            ->latest()
            ->pluck('registration_number')
            ->unique()
            ->take(2)
            ->values();
    }
}
