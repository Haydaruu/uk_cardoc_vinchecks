<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustCreditsRequest;
use App\Http\Requests\Admin\ReactivateCustomerRequest;
use App\Http\Requests\Admin\SuspendCustomerRequest;
use App\Models\User;
use App\Services\AdminAuditService;
use App\Services\CreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminCustomerController extends Controller
{
    public function __construct(
        protected ?AdminAuditService $auditService = null,
    ) {
        $this->auditService = $this->auditService ?? app(AdminAuditService::class);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $status = $request->input('status', 'all');
        $role = $request->input('role', 'all');
        $verification = $request->input('verification', 'all');

        $query = User::with(['subscriptions' => function ($q) {
            $q->where('status', 'active')->latest();
        }])
            ->withCount(['reports', 'transactions'])
            ->latest('created_at');

        if ($role !== 'all') {
            $query->where('role', $role);
        }

        if ($status === 'active') {
            $query->where('is_suspended', false);
        } elseif ($status === 'suspended') {
            $query->where('is_suspended', true);
        }

        if ($verification === 'verified') {
            $query->whereNotNull('email_verified_at');
        } elseif ($verification === 'unverified') {
            $query->whereNull('email_verified_at');
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('id', $search);
            });
        }

        $customers = $query->paginate(15)->through(function ($user) {
            $activeSub = $user->subscriptions->first();

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'credits' => $user->credits,
                'is_suspended' => (bool) $user->is_suspended,
                'suspended_reason' => $user->suspended_reason,
                'is_verified' => $user->email_verified_at !== null,
                'created_at' => $user->created_at?->toIso8601String(),
                'active_plan' => $activeSub?->plan_name ?: 'None',
                'reports_count' => $user->reports_count ?? 0,
                'transactions_count' => $user->transactions_count ?? 0,
            ];
        });

        return Inertia::render('admin/customers', [
            'customers' => $customers,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'role' => $role,
                'verification' => $verification,
            ],
        ]);
    }

    public function show(User $user): Response
    {
        $user->load([
            'subscriptions' => fn ($q) => $q->latest(),
            'transactions' => fn ($q) => $q->latest()->take(10),
            'creditTransactions' => fn ($q) => $q->latest()->take(10),
            'reports' => fn ($q) => $q->latest()->take(10),
            'suspendedBy',
        ]);

        return Inertia::render('admin/customer-detail', [
            'customer' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'credits' => $user->credits,
                'is_suspended' => (bool) $user->is_suspended,
                'suspended_reason' => $user->suspended_reason,
                'suspended_at' => $user->suspended_at instanceof Carbon ? $user->suspended_at->toIso8601String() : null,
                'suspended_by' => $user->suspendedBy?->name,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'subscriptions' => $user->subscriptions->map(fn ($sub) => [
                    'id' => $sub->id,
                    'plan_name' => $sub->plan_name,
                    'price' => (float) $sub->price,
                    'status' => $sub->status,
                    'start_date' => $sub->start_date?->toDateString(),
                    'end_date' => $sub->end_date?->toDateString(),
                    'monthly_credits' => $sub->monthly_credits,
                    'payment_method' => $sub->payment_method,
                ]),
                'transactions' => $user->transactions->map(fn ($tx) => [
                    'id' => $tx->id,
                    'invoice_id' => $tx->invoice_id,
                    'amount' => (float) $tx->amount,
                    'currency' => $tx->currency,
                    'status' => $tx->status,
                    'created_at' => $tx->created_at?->toIso8601String(),
                ]),
                'credit_history' => $user->creditTransactions->map(fn ($ct) => [
                    'id' => $ct->id,
                    'type' => $ct->type,
                    'amount' => $ct->amount,
                    'balance_after' => $ct->balance_after,
                    'description' => $ct->description,
                    'created_at' => $ct->created_at?->toIso8601String(),
                ]),
                'reports' => $user->reports->map(fn ($rep) => [
                    'id' => $rep->id,
                    'vin' => $rep->vin,
                    'report_type' => $rep->report_type,
                    'created_at' => $rep->created_at?->toIso8601String(),
                ]),
            ],
        ]);
    }

    /**
     * Adjust customer credits with balance guard, ledger tracking, idempotency, and audit logging.
     */
    public function adjustCredits(
        AdjustCreditsRequest $request,
        User $user,
        CreditService $creditService,
    ): RedirectResponse {
        $admin = $request->user();
        assert($admin instanceof User);

        if ($user->role !== 'user') {
            throw ValidationException::withMessages([
                'user' => ['Only customer accounts with the user role can have credits adjusted through this endpoint.'],
            ]);
        }

        $creditService->adjustCredits(
            admin: $admin,
            customer: $user,
            delta: (int) $request->validated('delta'),
            reason: (string) $request->validated('reason'),
            idempotencyKey: (string) $request->validated('idempotency_key'),
            request: $request,
        );

        return back()->with('success', "Credits for {$user->name} adjusted successfully.");
    }

    /**
     * Suspend a customer account.
     */
    public function suspend(
        SuspendCustomerRequest $request,
        User $user,
    ): RedirectResponse {
        $admin = $request->user();
        assert($admin instanceof User);

        if ($user->id === $admin->id) {
            throw ValidationException::withMessages([
                'user' => ['You cannot suspend your own administrative account.'],
            ]);
        }

        if ($user->role !== 'user') {
            throw ValidationException::withMessages([
                'user' => ['Only customer accounts with the user role can be suspended through this endpoint.'],
            ]);
        }

        if ($user->is_suspended) {
            throw ValidationException::withMessages([
                'user' => ['This customer is already suspended.'],
            ]);
        }

        $reason = (string) $request->validated('reason');

        DB::transaction(function () use ($user, $admin, $reason, $request) {
            $user->update([
                'is_suspended' => true,
                'suspended_at' => now(),
                'suspended_reason' => $reason,
                'suspended_by' => $admin->id,
            ]);

            $this->auditService->log(
                admin: $admin,
                action: 'customer.suspended',
                targetType: 'customer',
                targetId: $user->id,
                reason: $reason,
                outcome: 'success',
                metadata: [
                    'customer_email' => $user->email,
                    'customer_name' => $user->name,
                ],
                request: $request,
            );
        });

        return back()->with('success', "Customer {$user->name} has been suspended.");
    }

    /**
     * Reactivate a suspended customer account.
     */
    public function reactivate(
        ReactivateCustomerRequest $request,
        User $user,
    ): RedirectResponse {
        $admin = $request->user();
        assert($admin instanceof User);

        if ($user->role !== 'user') {
            throw ValidationException::withMessages([
                'user' => ['Only customer accounts with the user role can be reactivated through this endpoint.'],
            ]);
        }

        if (! $user->is_suspended) {
            throw ValidationException::withMessages([
                'user' => ['This customer is not currently suspended.'],
            ]);
        }

        $reason = (string) $request->validated('reason');
        $previousReason = $user->suspended_reason;

        DB::transaction(function () use ($user, $admin, $reason, $previousReason, $request) {
            $user->update([
                'is_suspended' => false,
                'suspended_at' => null,
                'suspended_reason' => null,
                'suspended_by' => null,
            ]);

            $this->auditService->log(
                admin: $admin,
                action: 'customer.reactivated',
                targetType: 'customer',
                targetId: $user->id,
                reason: $reason,
                outcome: 'success',
                metadata: [
                    'customer_email' => $user->email,
                    'customer_name' => $user->name,
                    'previous_suspended_reason' => $previousReason,
                ],
                request: $request,
            );
        });

        return back()->with('success', "Customer {$user->name} has been reactivated.");
    }
}
