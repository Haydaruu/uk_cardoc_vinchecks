<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Report;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $type = $request->input('type', 'all');
        $status = $request->input('status', 'all');

        $query = Report::with(['user', 'vinCheck', 'vehicle'])
            ->latest('created_at');

        if ($type !== 'all') {
            $query->where('report_type', $type);
        }

        if ($status !== 'all') {
            $query->whereHas('vinCheck', function ($q) use ($status) {
                $q->where('status', $status);
            });
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('vin', 'like', "%{$search}%")
                    ->orWhereHas('vehicle', function ($vq) use ($search) {
                        $vq->where('registration_number', 'like', "%{$search}%");
                    })
                    ->orWhereHas('vinCheck', function ($vcq) use ($search) {
                        $vcq->where('registration_number', 'like', "%{$search}%")
                            ->orWhere('vin', 'like', "%{$search}%");
                    })
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $reports = $query->paginate(15)->through(function ($report) {
            $regNum = $report->vehicle?->registration_number
                ?: $report->vinCheck?->registration_number
                ?: '-';

            $vin = $report->vin ?: $report->vinCheck?->vin ?: '-';
            $vcStatus = $report->vinCheck?->status ?: 'completed';

            return [
                'id' => $report->id,
                'registration_number' => $regNum,
                'vin' => $vin,
                'report_type' => $report->report_type ?: 'basic',
                'customer_id' => $report->user_id,
                'customer_name' => $report->user?->name ?: 'Guest / Direct',
                'customer_email' => $report->user?->email ?: '-',
                'status' => $vcStatus,
                'stage' => $report->vinCheck?->stage ?: '-',
                'generated_at' => $report->generated_at?->toIso8601String()
                    ?: $report->created_at?->toIso8601String(),
                'download_count' => $report->download_count ?? 0,
            ];
        });

        return Inertia::render('admin/reports', [
            'reports' => $reports,
            'filters' => [
                'search' => $search,
                'type' => $type,
                'status' => $status,
            ],
        ]);
    }
}
