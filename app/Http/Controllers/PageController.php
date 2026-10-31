<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PageController extends Controller
{
    public function home(): InertiaResponse
    {
        return Inertia::render('home');
    }

    public function support(): InertiaResponse
    {
        return Inertia::render('support');
    }

    public function pricing(): InertiaResponse
    {
        return Inertia::render('pricing');
    }

    public function myReport(Request $request): InertiaResponse
    {
        $user = $request->user();
        $search = $request->query('q');
        $search = is_string($search) ? $search : '';

        $reportsQuery = Report::with('vehicle')
            ->where('user_id', $user->id);

        if ($search !== '') {
            $reportsQuery->whereHas('vehicle', function ($query) use ($search) {
                $query->where('registration_number', 'like', "%{$search}%");
            });
        }

        $reports = $reportsQuery
            ->latest()
            ->paginate(9)
            ->withQueryString();

        return Inertia::render('my-report', [
            'reports' => $reports->through(fn ($report) => [
                'id' => $report->id,
                'reportType' => $report->report_type,
                'registrationNumber' => $report->vehicle->registration_number ?? '—',
                'make' => $report->vehicle->brand ?? $report->report_data['make'] ?? 'Unknown',
                'model' => $report->vehicle->model ?? $report->report_data['model'] ?? '',
                'imageUrl' => $report->vehicle->image_url ?? null,
                'checkedOn' => $report->generated_at?->format('d M Y') ?? $report->created_at->format('d M Y'),
                'financeRecord' => $report->report_data['VehicleHistory']['financeRecord'] ?? null,
                'writeOffRecord' => $report->report_data['VehicleHistory']['writeOffRecord'] ?? null,
            ])->items(),
            'links' => $reports->linkCollection(),
            'search' => $search,
            'totalReports' => Report::where('user_id', $user->id)->count(),
            'premiumReports' => Report::where('user_id', $user->id)->where('report_type', 'premium')->count(),
        ]);
    }
}
