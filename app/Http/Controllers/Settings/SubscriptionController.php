<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Stripe\StripeClient;

class SubscriptionController extends Controller
{
    public function checkout(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'plan' => ['nullable', 'string'],
        ]);

        $planSlug = $request->string('plan', 'premium-monthly')->toString();

        $plan = config("credit_plans.{$planSlug}");

        if (! $plan || $plan['type'] !== 'subscription') {
            abort(422, 'Invalid subscription plan. ');
        }

        $existingSubscription = $user->subscriptions()
            ->whereIn('status', ['active', 'pending'])
            ->latest()
            ->first();
        
        if( $existingSubscription ) {
            return back()->with(
                'error',
                ' You already have an active subscription. '
            );
        }

        $stripe = new StripeClient(config('services.stripe.secret'));

        if (! $user->stripe_customer_id) {
            $customer = $stripe->customers->create([
                'email' => $user->email,
                'name' => $user->name,
                'metadata' => [
                    'user_id' => (string)$user->id,
                ],
            ]);

             $user->update([
                'stripe_customer_id' => $customer->id,
            ]);
        }

        $session = $stripe->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $user->stripe_customer_id,
            'line_items' => [
                [
                    'price' => $plan['price_id'],
                    'quantity' => 1,
                ],
            ],
            
            'subscription_data' => [
                'metadata' => [
                    'user_id' => (string)$user->id,
                    'plan' => $planSlug,
                    'credits' => (string)$plan['credits'],
                ],
            ],

            'metadata' => [
                'user_id' => (string)$user->id,
                'plan' => $planSlug,
                'credits' => (string) $plan['credits'],
            ],

            'success_url' => 
                route('settings.subscription')
                . '?checkout=success',
            
            'cancel_url' => 
                route('settings.subscription')
                . '?checkout=cancelled',
        ]);

        return Inertia::location($session->url);
    }

    public function cancel(Request $request)
    {
        $user = $request->user();
        $subscription = $user->activeSubscription();

        if(! $subscription || ! $subscription->stripe_subscription_id) {
            return back()->with(
                'error',
                'No active subscription found.'
            );
        }
        $stripe = new StripeClient(config('services.stripe.secret'));

        $stripe->subscriptions->update($subscription->stripe_subscription_id, 
            [
                'cancel_at_period_end' => true
            ]
        );

        return back()->with(
            'success',
            'Your subscription will be cancelled at the end of the current billing period.'
        );
    }

    public function changePlan(Request $request)
    {
        $request->validate([
            'plan' => ['required', 'string'],
        ]);

        $user = $request->user();

        $subscription = $user->activeSubscription();

        if(! $subscription || ! $subscription->stripe_subscription_id) {
            return back()->with(
                'error',
                'No active subscription found.'
            );
        }

        $planSlug = $request->string('plan')->toString();
        $plan = config("credit_plans.{$planSlug}");

        if(! $plan || $plan['type'] !== 'subscription') {
            abort(422, 'Invalid subscription plan.');
        }

        if ($subscription->plan_name === $planSlug) {
            return back()->with(
                'info',
                'You are already subscribed to this plan.'
            );
        }

        $stripe = new StripeClient(config('services.stripe.secret'));

        $stripeSubscription = $stripe->subscriptions->retrieve($subscription->stripe_subscription_id);

        $item = $stripeSubscription->items->data[0] ?? null;

        if (! $item) {
            abort(422, 'Subscription item not found.');
        }

        $updateSubscription = $stripe->subscriptions->update($subscription->stripe_subscription_id, [
                'items' => [
                    [
                        'id' => $item->id,
                        'price' => $plan['price_id'],
                    ],
                ],

                'proration_behavior' => 'none',

                'metadata' => [
                    'user_id' => (string)$user->id,
                    'plan' => $planSlug,
                    'credits' => (string)$plan['credits'],
                ],
            ]
        );

        $stripePrice = $stripe->prices->retrieve($plan['price_id']);

        $subscription->update([
            'plan_name' => $planSlug,
            'stripe_price_id' => $plan['price_id'],
            'price' => $stripePrice->unit_amount / 100,
            'monthly_credits' => $plan['credits'],
        ]);

        return back()->with(
            'success',
            'Your subscription plan has been updated successfully.'
        );
    }
}
