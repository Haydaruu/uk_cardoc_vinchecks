<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Report;
use App\Models\GuestReportPurchase;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function show(Report $report, Request $request)
    {
        $user = $request->user();

        if ($report->user_id && (!$user || $report->user_id !== $user->id)){
            abort(403);
        }

        $guestPurchase = $report->user_id ? null : GuestReportPurchase::where('report_id', $report->id)->first();

        if ($guestPurchase) {
            $token = (string) $request->query('token', '');
            abort_unless(
                $guestPurchase->status === 'completed'
                && $token !== ''
                && $guestPurchase->access_token_hash
                && hash_equals($guestPurchase->access_token_hash, hash('sha256', $token)),
                404,
            );
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
                'pdf_url' => $guestPurchase ? route('report.pdf.download', $report->id) . '?token=' . urlencode((string) $request->query('token')) : null,
            ],
        ]);
    }
}
