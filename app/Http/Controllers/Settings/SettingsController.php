<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateProfileRequest;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function profile(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('user/settings/profile', [
            'profile' => [
                'name' => $user->name,
                'phone_number' => $user->phone_number,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'avatar' => $user->avatar,
                'credits' => $user->credits,
                'created_at' => $user->created_at,
            ],
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request)
    {
        $request->user()->update(
            $request->validated()
        );

        return back()->with(
            'success',
            'Profile updated successfully.'
        );
    }

    public function security(Request $request): Response
    {  
        $user = $request->user();

        $currentSessionId = $request->session()->getId();

        $session = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(function ($session) use ($currentSessionId){ 

            $agent = $session->user_agent ?? '';

            $device = 'Unknown Device';
            $browser = 'Unknown Browser';

            if(str_contains($agent, 'Chrome')) {
                $browser = 'Chrome';
            }

            if(str_contains($agent, 'Windows')) {
                $device = 'Windows';
            }

            return[
                'key' => hash_hmac('sha256', $session->id, config('app.key')),
                'browser' => $browser,
                'device' => $device,
                'last_activity' => $session->last_activity,
                'is_current' => $session->id === $currentSessionId,
            ];

        });

        return Inertia::render('user/settings/security',[
            'security' => [
                'has_password' => ! is_null($request->user()->password),
                'sessions' => $session,
            ],
        ]);
    }

    public function connectedAccounts(Request $request): Response
    {
        $user = $request->user();

        $accounts = $user->socialAccounts()
            ->get()
            ->keyBy('provider');

        $hasPassword = ! is_null($user->password);

        $loginMethods = $accounts->count() + ($hasPassword ? 1 : 0);

        return Inertia::render('user/settings/connected-accounts', [
            'connectedAccounts' => [
                'has_password' => $hasPassword,

                'google' => [
                    'connected' => $accounts->has('google'),
                    'email' => $accounts->get('google')?->provider_email,
                    'can_disconnect' => $accounts->has('google') && $loginMethods > 1,
                ],

                 'microsoft' => [
                    'connected' => $accounts->has('microsoft'),
                    'email' => $accounts->get('microsoft')?->provider_email,
                    'can_disconnect' => $accounts->has('microsoft') && $loginMethods > 1,
                ],
            ],
        ]);
    }

    public function purchaseHistory(Request $request): Response
    {
        $user = $request->user();

        $search = $request->string('search')->toString();
        $period = $request->string('preiod', 'all')->toString();
        $status = $request->string('status', 'all')->toString();

        $baseQuery = Transaction::query()
            ->where('user_id', $user->id);

        $totalSpent = (clone $baseQuery)
            ->where('status', 'success')
            ->where('type', 'payment')
            ->sum('amount');

        $creditPurchases = $totalSpent;
        
        $transaction = $baseQuery
            ->when($search, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query
                        ->where('invoice_id', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($status !== 'all', function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when($period === '30', function ($query) {
                $query->where('paid_at', '>=', now()->subDays(30));
            })
            ->when($period === '90', function ($query) {
                $query->where('paid_at', '>=', now()->subDays(90));
            })
            ->when($period === 'year', function ($query) {
                $query->whereYear('paid_at', now()->year);
            })
            ->latest('paid_at')
            ->paginate(10)
            ->withQueryString()
            ->through(fn ($transaction) => [
                'id' => $transaction->id,
                'invoice_id' => $transaction->invoice_id,
                'description' => $transaction->description,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'type' => $transaction->type,
                'category' => $transaction->category,
                'status' => $transaction->status,
                'payment_method' => $transaction->payment_method,
                'paid_at' => $transaction->paid_at,
            ]);

        return Inertia::render('user/settings/purchase-history',[ 
            'transactions' => $transaction, 
            
            'summary' => [
                'total_spent' => $totalSpent,
                'credit_purchases' => $creditPurchases,
                'subscription_payments' => 0,
            ],

            'filters' => [
                'search' => $search,
                'period' => $period,
                'status' => $status,
            ],
        ]);
    }

    public function subscription(Request $request): Response
    {
        $user = $request->user();

        $subscription = $user->subscriptions()->latest()->first();

        $currentPlan = $subscription ? config("credit_plans.{$subscription->plan_name}") : null;
        $availablePlans = collect(config('credit_plans'))->filter(fn ($plan) => $plan['type'] === 'subscription')
        ->map(fn ($plan, $slug) => [
            'slug' => $slug,
            'name' => $plan['label'],
            'price' => $plan['amount_display'],
            'monthly_credits' => $plan['credits'],
        ])
        ->values();

        $plan = config('credit_plans.premium-monthly');

        $recentInvoices = Transaction::query()
            ->where('user_id', $user->id)
            ->where('category', 'subscription')
            ->where('status', 'success')
            ->latest('paid_at')
            ->limit(5)
            ->get()
            ->map(fn ($transaction) => [
                'id' => $transaction->id,
                'invoice_id' => $transaction->invoice_id,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'status' => $transaction->status,
                'paid_at' => $transaction->paid_at,
                'description' => $transaction->description,
            ]);

        return Inertia::render('user/settings/subscription',[
            'subscription' => $subscription
                ?[
                    'plan_name' => $subscription->plan_name,
                    'price' => $subscription->price,
                    'status' => $subscription->status,
                    'monthly_credits' => $subscription->monthly_credits,
                    'payment_method' => $subscription->payment_method,
                    'start_date' => $subscription->start_date,
                    'current_period_end' => $subscription->current_period_end,
                    'cancel_at_period_end' => $subscription->cancel_at_period_end,
                    'cancelled_at' => $subscription->cancelled_at,
                ]
                : null,

            'plan' => $currentPlan
                ?[
                    'name' => $currentPlan['label'],
                    'price' => $currentPlan['amount_display'],
                    'monthly_credits' => $currentPlan['credits'],
                ]
                : null,

            'availablePlans' => $availablePlans,

            'recentInvoices' => $recentInvoices,
        ]);
    }

    public function help(): Response
    {
        return Inertia::render('user/settings/help');
    }
}