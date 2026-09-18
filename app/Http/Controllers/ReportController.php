<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Report;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function show(Report $report, Request $request)
    {
        $user = $request->user();

        if ($report->user_id && (!$user || $report->user_id !== $user->id)){
            abort(403);
        }

        $reportData = $report->report_data ?? [];

        $isNormalized = data_get($reportData, 'meta.format') === 'normalized'
            && (int) data_get($reportData, 'meta.schema_version', 0) >= 2;
        
        if($report->report_type === 'premium') {
            $component = $isNormalized
                ? 'report/normalized-full-report'
                : 'report/full-report';
        } else {
            $component = 'report/show';
        }
    
        return Inertia::render($component, [
            'report' => [
                'id' => $report->id,
                'report_type' => $report->report_type, 
                'data' => $reportData,
                'generated_at' => $report->generated_at,
            ],
        ]);
    }
}
