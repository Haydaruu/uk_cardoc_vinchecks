<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Transaction;
use App\Services\Admin\AdminMetricsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    public function __construct(
        protected AdminMetricsService $metricsService,
    ) {}

    public function index(Request $request): Response
    {
        $stats = $this->metricsService->getOverviewStatistics();

        // Recent 5 registered transactions
        $recentRegTransactions = Transaction::with('user')
            ->latest('created_at')
            ->take(5)
            ->get()
            ->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'reference' => $tx->invoice_id ?: ('TX-'.$tx->id),
                    'source_type' => 'registered',
                    'customer_name' => $tx->user?->name ?: 'Unknown',
                    'customer_email' => $tx->user?->email ?: '-',
                    'amount' => (float) $tx->amount,
                    'currency' => strtoupper($tx->currency ?: 'GBP'),
                    'status' => $tx->status,
                    'category' => $tx->category ?: $tx->type,
                    'payment_method' => $tx->payment_method ?: 'Stripe',
                    'created_at' => $tx->created_at?->toIso8601String(),
                ];
            });

        // Recent 5 guest report purchases
        $recentGuestTransactions = GuestReportPurchase::latest('created_at')
            ->take(5)
            ->get()
            ->map(function ($gp) {
                return [
                    'id' => $gp->id,
                    'reference' => $gp->provider_order_id ?: ('GP-'.$gp->id),
                    'source_type' => 'guest',
                    'customer_name' => $gp->name ?: 'Guest Customer',
                    'customer_email' => $gp->email ?: '-',
                    'amount' => round(((float) $gp->amount_minor) / 100.0, 2),
                    'currency' => strtoupper($gp->currency ?: 'GBP'),
                    'status' => $gp->payment_status,
                    'category' => 'guest_report',
                    'payment_method' => $gp->payment_provider ?: 'Stripe',
                    'created_at' => $gp->created_at?->toIso8601String(),
                ];
            });

        // Merge and take top 5 latest
        $recentTransactions = $recentRegTransactions->concat($recentGuestTransactions)
            ->sortByDesc('created_at')
            ->take(6)
            ->values();

        // Recent 6 vehicle reports
        $recentReports = Report::with(['user', 'vinCheck', 'vehicle'])
            ->latest('created_at')
            ->take(6)
            ->get()
            ->map(function ($report) {
                return [
                    'id' => $report->id,
                    'registration_number' => $report->vehicle?->registration_number
                        ?: $report->vinCheck?->registration_number
                        ?: '-',
                    'vin' => $report->vin ?: $report->vinCheck?->vin ?: '-',
                    'report_type' => $report->report_type ?: 'basic',
                    'customer_name' => $report->user?->name ?: 'Guest',
                    'customer_email' => $report->user?->email ?: '-',
                    'status' => $report->vinCheck?->status ?: 'completed',
                    'created_at' => $report->created_at?->toIso8601String(),
                ];
            });

        return Inertia::render('admin/dashboard', [
            'stats' => $stats,
            'recentTransactions' => $recentTransactions,
            'recentReports' => $recentReports,
        ]);
    }
}
