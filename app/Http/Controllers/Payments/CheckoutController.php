<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CheckoutController extends Controller
{
    public function show(Request $request)
    {
        $planSlug = $request->query('plan');
        $plan = config("credit_plans.{$planSlug}");

        if(!$plan || $plan['type'] !== 'one_time') {
            return redirect()->route('page.pricing');
        }

        return Inertia::render('user/checkout/checkout-stripe', [
            'plan' => $planSlug,
            'label' => $plan['label'],
            'amountDisplay' => $plan['amount_display'],

            'paypalClientId' => config('services.paypal.client_id'),
            'paypalEnvironment' => config('services.paypal.mode') === 'live' ? 'production' : 'sandbox',
        ]);
    }
}
