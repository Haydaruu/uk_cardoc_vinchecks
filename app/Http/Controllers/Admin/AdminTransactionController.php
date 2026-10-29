<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GuestReportPurchase;
use App\Models\Transaction;
use App\Services\Admin\AdminMetricsService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class AdminTransactionController extends Controller
{
    public function __construct(
        protected AdminMetricsService $metricsService,
    ) {}

    public function index(Request $request): Response
    {
        $source = $request->input('source', 'all');
        $status = $request->input('status', 'all');
        $currency = $request->input('currency', 'all');
        $search = trim((string) $request->input('search', ''));
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 15;

        $items = collect();

        // 1. Fetch Registered Transactions
        if ($source === 'all' || $source === 'registered') {
            $txQuery = Transaction::with('user');

            if ($status !== 'all') {
                $txQuery->where('status', $status);
            }

            if ($currency !== 'all') {
                $txQuery->where('currency', $currency);
            }

            if ($search !== '') {
                $txQuery->where(function ($q) use ($search) {
                    $q->where('invoice_id', 'like', "%{$search}%")
                        ->orWhere('payment_gateway_ref', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($uq) use ($search) {
                            $uq->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            }

            $regItems = $txQuery->get()->map(function ($tx) {
                return [
                    'id' => 'reg_'.$tx->id,
                    'raw_id' => $tx->id,
                    'reference' => $tx->invoice_id ?: ('TX-'.$tx->id),
                    'source_type' => 'registered',
                    'customer_id' => $tx->user_id,
                    'customer_name' => $tx->user?->name ?: 'Customer #'.$tx->user_id,
                    'customer_email' => $tx->user?->email ?: '-',
                    'amount' => (float) $tx->amount,
                    'currency' => strtoupper($tx->currency ?: 'GBP'),
                    'status' => $tx->status,
                    'category' => $tx->category ?: $tx->type,
                    'payment_method' => $tx->payment_method ?: 'Stripe',
                    'payment_gateway_ref' => $tx->payment_gateway_ref,
                    'paid_at' => $tx->paid_at?->toIso8601String(),
                    'refunded_at' => $tx->refunded_at?->toIso8601String(),
                    'created_at' => $tx->created_at?->toIso8601String(),
                ];
            });

            $items = $items->concat($regItems);
        }

        // 2. Fetch Guest Purchases
        if ($source === 'all' || $source === 'guest') {
            $gpQuery = GuestReportPurchase::query();

            if ($status !== 'all') {
                $gpQuery->where('payment_status', $status);
            }

            if ($currency !== 'all') {
                $gpQuery->where('currency', strtolower($currency));
            }

            if ($search !== '') {
                $gpQuery->where(function ($q) use ($search) {
                    $q->where('provider_order_id', 'like', "%{$search}%")
                        ->orWhere('provider_payment_id', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $guestItems = $gpQuery->get()->map(function ($gp) {
                return [
                    'id' => 'guest_'.$gp->id,
                    'raw_id' => $gp->id,
                    'reference' => $gp->provider_order_id ?: ('GP-'.$gp->id),
                    'source_type' => 'guest',
                    'customer_id' => null,
                    'customer_name' => $gp->name ?: 'Guest Customer',
                    'customer_email' => $gp->email ?: '-',
                    'amount' => round(((float) $gp->amount_minor) / 100.0, 2),
                    'currency' => strtoupper($gp->currency ?: 'GBP'),
                    'status' => $gp->payment_status,
                    'category' => 'guest_report',
                    'payment_method' => $gp->payment_provider ?: 'Stripe',
                    'payment_gateway_ref' => $gp->provider_payment_id,
                    'paid_at' => $gp->paid_at?->toIso8601String(),
                    'refunded_at' => $gp->refunded_at?->toIso8601String(),
                    'created_at' => $gp->created_at?->toIso8601String(),
                ];
            });

            $items = $items->concat($guestItems);
        }

        // Sort descending by created_at
        $sortedItems = $items->sortByDesc('created_at')->values();

        $total = $sortedItems->count();
        $slice = $sortedItems->slice(($page - 1) * $perPage, $perPage)->values();

        $paginated = new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => route('admin.transactions.index')]
        );

        $financialSummary = $this->metricsService->getFinancialSummary();

        return Inertia::render('admin/transactions', [
            'transactions' => $paginated,
            'filters' => [
                'source' => $source,
                'status' => $status,
                'currency' => $currency,
                'search' => $search,
            ],
            'financialSummary' => $financialSummary,
        ]);
    }
}
