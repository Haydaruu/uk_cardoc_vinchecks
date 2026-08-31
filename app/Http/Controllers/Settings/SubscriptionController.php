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
        $planSlug = 'premium-monthly';
        $plan = config("credit_plans.{$planSlug}");

        if (! $plan || $plan['type'] !== 'subscription') {
            abort(422, 'Invalid subscription plan. ');
        }

        if( $user->activeSubscription()) {
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
        
    }
}
