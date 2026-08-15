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

        $component = $report->report_type === 'premium'
            ? 'report/full-report'
            : 'report/show';
    
        
        return Inertia::render($component, [
            'report' => [
                'id' => $report->id,
                'report_type' => $report->report_type, 
                'data' => $report->report_data,
                'generated_at' => $report->generated_at,
            ],
        ]);
    }
}
