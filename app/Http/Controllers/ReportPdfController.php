<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\Request;
use Spatie\LaravelPdf\Facades\Pdf;

class ReportPdfController extends Controller
{
    public function download(Report $report, Request $request)
    {
        $user = $request->user();

        if ($report->user_id && (!$user || $report->user_id !== $user->id)) {
            abort(403);
        }

        $reportData = $report->report_data ?? [];

        $isNormalized = data_get($reportData, 'meta.format') === 'normalized'
            && (int) data_get($reportData, 'meta.schema_version', 0) >= 2;

        abort_unless(
            $report->report_type === 'premium' && $isNormalized,
            404
        );

        return Pdf::view('pdf.reports.vehicle-report', [
            'report' => $report,
            'data' => $reportData,
        ])
            ->format('a4')
            ->margins(12, 12, 14, 12)
            ->download(
                'vehicle-report-' . ($reportData['vehicle']['vrm'] ?? $report->id) . '.pdf'
            );
    }
}
