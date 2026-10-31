<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Services\GuestReportAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportPdfController extends Controller
{
    public function download(Report $report, Request $request, GuestReportAccessService $guestReportAccess): BinaryFileResponse
    {
        $startedAt = microtime(true);

        Log::info('PDF DEBUG: request started', [
            'report_id' => $report->id,
        ]);

        $user = $request->user();

        if ($report->user_id !== null && (! $user || (int) $report->user_id !== $user->id)) {
            abort(403);
        }
        if ($report->user_id === null && $report->report_type === 'premium') {
            abort_unless($guestReportAccess->hasCompletedPurchase($report, $request->query('guest_token')), 403);
        }

        $reportData = $report->report_data ?? [];

        $isNormalized =
            data_get($reportData, 'meta.format') === 'normalized'
            && (int) data_get($reportData, 'meta.schema_version', 0) >= 2;

        abort_unless(
            $report->report_type === 'premium' && $isNormalized,
            404
        );

        Log::info('PDF DEBUG: validation passed', [
            'elapsed_ms' => round(
                (microtime(true) - $startedAt) * 1000,
                2
            ),
        ]);

        $filename =
            'vehicle-report-'.
            ($reportData['vehicle']['vrm'] ?? $report->id).
            '.pdf';

        $directory = storage_path('app/pdf-temp');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $outputPath = $directory.DIRECTORY_SEPARATOR.$filename;

        Log::info('PDF DEBUG: starting actual render', [
            'path' => $outputPath,
            'elapsed_ms' => round(
                (microtime(true) - $startedAt) * 1000,
                2
            ),
        ]);

        Pdf::view('pdf.reports.vehicle-report', [
            'report' => $report,
            'data' => $reportData,
        ])
            ->format('a4')
            ->margins(12, 12, 14, 12)
            ->save($outputPath);

        Log::info('PDF DEBUG: actual render finished', [
            'elapsed_ms' => round(
                (microtime(true) - $startedAt) * 1000,
                2
            ),
            'file_exists' => file_exists($outputPath),
            'file_size' => file_exists($outputPath)
                ? filesize($outputPath)
                : 0,
        ]);

        return response()->file($outputPath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
