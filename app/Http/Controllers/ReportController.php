<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Services\GuestReportAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function show(Report $report, Request $request, GuestReportAccessService $guestReportAccess): Response
    {
        $user = $request->user();

        if ($report->user_id !== null && (! $user || (int) $report->user_id !== $user->id)) {
            abort(403);
        }

        if ($report->user_id === null && $report->report_type === 'premium') {
            abort_unless($guestReportAccess->hasCompletedPurchase($report, $request->query('guest_token')), 403);
        }

        $reportData = $report->report_data ?? [];

        $isNormalized = data_get($reportData, 'meta.format') === 'normalized'
            && (int) data_get($reportData, 'meta.schema_version', 0) >= 2;

        if ($report->report_type === 'premium') {
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
