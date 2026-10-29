<?php

namespace App\Services\Admin;

use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VinCheck;
use Carbon\Carbon;

class AdminMetricsService
{
    /**
     * Get aggregate financial metrics grouped strictly by currency.
     *
     * @return array<string, array{currency: string, gross: float, refunds: float, net_collected: float, transaction_count: int}>
     */
    public function getFinancialSummary(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $currencies = [];

        // 1. Registered Customer Transactions
        $txQuery = Transaction::query();
        if ($startDate && $endDate) {
            $txQuery->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('paid_at', [$startDate, $endDate])
                    ->orWhereBetween('refunded_at', [$startDate, $endDate]);
            });
        }

        $transactions = $txQuery->get();

        foreach ($transactions as $tx) {
            $curr = strtoupper($tx->currency ?: 'GBP');
            if (! isset($currencies[$curr])) {
                $currencies[$curr] = [
                    'currency' => $curr,
                    'gross' => 0.0,
                    'refunds' => 0.0,
                    'net_collected' => 0.0,
                    'transaction_count' => 0,
                ];
            }

            $amount = (float) $tx->amount;

            // Check if within date filter for paid_at
            $isPaidInPeriod = ! ($startDate && $endDate) || ($tx->paid_at && $tx->paid_at->between($startDate, $endDate));
            $isRefundedInPeriod = ! ($startDate && $endDate) || ($tx->refunded_at && $tx->refunded_at->between($startDate, $endDate));

            if ($tx->paid_at && $isPaidInPeriod) {
                $currencies[$curr]['gross'] += $amount;
                $currencies[$curr]['transaction_count']++;
            }

            if ($tx->status === 'refunded' && $tx->refunded_at && $isRefundedInPeriod) {
                $currencies[$curr]['refunds'] += $amount;
            }
        }

        // 2. Guest Report Purchases
        $guestQuery = GuestReportPurchase::query();
        if ($startDate && $endDate) {
            $guestQuery->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('paid_at', [$startDate, $endDate])
                    ->orWhereBetween('refunded_at', [$startDate, $endDate]);
            });
        }

        $guestPurchases = $guestQuery->get();

        foreach ($guestPurchases as $gp) {
            $curr = strtoupper($gp->currency ?: 'GBP');
            if (! isset($currencies[$curr])) {
                $currencies[$curr] = [
                    'currency' => $curr,
                    'gross' => 0.0,
                    'refunds' => 0.0,
                    'net_collected' => 0.0,
                    'transaction_count' => 0,
                ];
            }

            $amount = ((float) $gp->amount_minor) / 100.0;

            $isPaidInPeriod = ! ($startDate && $endDate) || ($gp->paid_at && $gp->paid_at->between($startDate, $endDate));
            $isRefundedInPeriod = ! ($startDate && $endDate) || ($gp->refunded_at && $gp->refunded_at->between($startDate, $endDate));

            if ($gp->paid_at && $isPaidInPeriod) {
                $currencies[$curr]['gross'] += $amount;
                $currencies[$curr]['transaction_count']++;
            }

            if ($gp->payment_status === GuestReportPurchase::STATUS_REFUNDED && $gp->refunded_at && $isRefundedInPeriod) {
                $currencies[$curr]['refunds'] += $amount;
            }
        }

        // Calculate Net Collected per currency
        foreach ($currencies as $curr => $data) {
            $currencies[$curr]['gross'] = round($data['gross'], 2);
            $currencies[$curr]['refunds'] = round($data['refunds'], 2);
            $currencies[$curr]['net_collected'] = round($data['gross'] - $data['refunds'], 2);
        }

        return $currencies;
    }

    /**
     * Get aggregate overview statistics for dashboard cards.
     *
     * @return array<string, mixed>
     */
    public function getOverviewStatistics(): array
    {
        $now = Carbon::now();
        $thirtyDaysAgo = $now->copy()->subDays(30);

        // Customers
        $totalCustomers = User::where('role', 'user')->count();
        $newCustomersLast30Days = User::where('role', 'user')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->count();
        $suspendedCustomers = User::where('role', 'user')
            ->where('is_suspended', true)
            ->count();

        // Subscriptions
        $activeSubscriptions = Subscription::where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', Carbon::now());
            })
            ->count();

        // Reports
        $totalReports = Report::count();
        $reportsLast30Days = Report::where('created_at', '>=', $thirtyDaysAgo)->count();

        // VinChecks
        $vinChecksSuccess = VinCheck::where('status', 'success')->count();
        $vinChecksFailed = VinCheck::where('status', 'failed')->count();
        $vinChecksPending = VinCheck::whereIn('status', ['pending', 'queued', 'processing'])->count();

        // Guest Purchases fulfillment breakdown
        $guestFulfillment = [
            'pending' => GuestReportPurchase::where('fulfillment_status', GuestReportPurchase::FULFILLMENT_PENDING)->count(),
            'processing' => GuestReportPurchase::where('fulfillment_status', GuestReportPurchase::FULFILLMENT_PROCESSING)->count(),
            'completed' => GuestReportPurchase::where('fulfillment_status', GuestReportPurchase::FULFILLMENT_COMPLETED)->count(),
            'failed' => GuestReportPurchase::where('fulfillment_status', GuestReportPurchase::FULFILLMENT_FAILED)->count(),
        ];

        // Financial totals (all time and last 30 days)
        $financialAllTime = $this->getFinancialSummary();
        $financialLast30Days = $this->getFinancialSummary($thirtyDaysAgo, $now);

        return [
            'customers' => [
                'total' => $totalCustomers,
                'new_last_30_days' => $newCustomersLast30Days,
                'suspended' => $suspendedCustomers,
            ],
            'subscriptions' => [
                'active' => $activeSubscriptions,
            ],
            'reports' => [
                'total' => $totalReports,
                'last_30_days' => $reportsLast30Days,
                'vin_checks_success' => $vinChecksSuccess,
                'vin_checks_failed' => $vinChecksFailed,
                'vin_checks_pending' => $vinChecksPending,
            ],
            'guest_fulfillment' => $guestFulfillment,
            'financial' => [
                'all_time' => $financialAllTime,
                'last_30_days' => $financialLast30Days,
            ],
        ];
    }
}
