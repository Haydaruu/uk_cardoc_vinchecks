<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Report;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function show(Report $report)
    {
        return Inertia::render('my-report/show', [
            'report' => [
                'id' => $report->id,
                'report_type' => $report->report_type, 
                'data' => $report->report_data,
                'generated_at' => $report->generated_at,
            ],
        ]);
    }
}
