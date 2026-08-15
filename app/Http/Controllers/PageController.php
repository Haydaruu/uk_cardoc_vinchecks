<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Report;
use Inertia\Inertia;

class PageController extends Controller
{
    public function home() 
    {
        return Inertia::render('home');
    }

    public function support()
    {
        return Inertia::render('support');
    }

    public function pricing()
    {
        return Inertia::render('pricing');
    }
    public function myReport(Request $request){
        $user = $request->user();

        $reports = Report::with('vehicle')
            ->where('user_id', $user->id)
            ->when($request->query('q'), function ($query, $search) {
                $query->whereHas('vehicle', function ($q) use ($search) {
                    $q->where('registration_number', 'like', "%{$search}%");
                });
            })
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
                'search' => $request->query('q', ''),
                'totalReports' => Report::where('user_id', $user->id)->count(),
                'premiumReports' => Report::where('user_id', $user->id)->where('report_type', 'premium')->count(),
        ]);
    }
}
