<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VinCheck;
use App\Services\Admin\AdminMetricsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminAnalyticsController extends Controller
{
    public function __construct(
        protected AdminMetricsService $metricsService,
    ) {}

    public function index(Request $request): Response
    {
        $period = $request->input('period', '30d');
        $days = match ($period) {
            '7d' => 7,
            '90d' => 90,
            '1y' => 365,
            default => 30,
        };

        $startDate = Carbon::now()->subDays($days)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        // 1. Revenue Summary for selected period
        $financialSummary = $this->metricsService->getFinancialSummary($startDate, $endDate);

        // 2. Customer Registrations Trend
        $customerRegistrations = DB::table('users')
            ->where('role', 'user')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as reg_date, count(*) as reg_count')
            ->groupBy('reg_date')
            ->orderBy('reg_date')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->reg_date,
                'count' => (int) $row->reg_count,
            ]);

        // 3. Reports / Checks Outcome
        $vinChecksSummary = [
            'success' => VinCheck::whereBetween('created_at', [$startDate, $endDate])
                ->where('status', 'success')
                ->count(),
            'failed' => VinCheck::whereBetween('created_at', [$startDate, $endDate])
                ->where('status', 'failed')
                ->count(),
            'pending' => VinCheck::whereBetween('created_at', [$startDate, $endDate])
                ->whereIn('status', ['pending', 'queued', 'processing'])
                ->count(),
        ];

        $totalChecks = array_sum($vinChecksSummary);
        $successRate = $totalChecks > 0 ? round(($vinChecksSummary['success'] / $totalChecks) * 100, 1) : 0;

        // 4. Subscriptions distribution
        $subscriptionDistribution = DB::table('subscriptions')
            ->where('status', 'active')
            ->selectRaw('plan_name, count(*) as sub_count')
            ->groupBy('plan_name')
            ->get()
            ->map(fn ($row) => [
                'plan_name' => $row->plan_name ?: 'Unknown Plan',
                'count' => (int) $row->sub_count,
            ]);

        return Inertia::render('admin/analytics', [
            'period' => $period,
            'financialSummary' => $financialSummary,
            'customerRegistrations' => $customerRegistrations,
            'vinChecksSummary' => [
                ...$vinChecksSummary,
                'total' => $totalChecks,
                'success_rate' => $successRate,
            ],
            'subscriptionDistribution' => $subscriptionDistribution,
        ]);
    }
}
