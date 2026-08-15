<?php

namespace App\Http\Controllers;

use App\Models\Report; 
use Illuminate\Http\Request;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;
use App\Services\CreditService;
use Inertia\Inertia;

class PaymentController extends Controller
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
        ]);
    }
    public function createOneTimeIntent(Request $request)
    {
        $request->validate([
            'plan' => 'required|string',
        ]);

        $plan = config("credit_plans.{$request->plan}");

        if (!$plan || $plan['type'] !== 'one_time') {
            return response()->json(['message' => 'Plan tidak valid.'], 422);
        }

        $user = $request->user();
        $stripe = new StripeClient(config('services.stripe.secret'));

        try { 
            $price = $stripe->prices->retrieve($plan['price_id']);
        } catch (ApiErrorException $e) {
            return response()->json(['message' => 'Gagal mengambil harga dari Stripe.'], 422);
        }

        $paymentIntent = $stripe->paymentIntents->create([
            'amount' => $price->unit_amount,
            'currency' => $price->currency,
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => [
                'user_id' => $user->id,
                'credits' => $plan['credits'],
                'price_id' => $price->id,
                'product_name' => $plan['label'],
                'plan' => $request->plan,
            ],
        ]);

        return response()->json([
            'clientSecret' => $paymentIntent->client_secret,
        ]);
    }

    public function success(Request $request)
    {
        $paymentIntentId = $request->query('payment_intent');

        if (!$paymentIntentId) {
            return redirect('/');
        }

        $stripe = new StripeClient(config('services.stripe.secret'));

        try {
            $intent = $stripe->paymentIntents->retrieve($paymentIntentId, [
                'expand' => ['payment_method'],
            ]);
        } catch (ApiErrorException $e) {
            return redirect('/')->with('modal', 'payment_failed');
        }

        if ($intent->status !== 'succeeded') {
            return redirect('/')->with('modal', 'payment_failed');
        }

        $user = $request->user();
        $credits = (int) ($intent->metadata->credits ?? 0);

        if ($credits > 0) {
            app(CreditService::class)->grantCredits(
                user: $user,
                amount: $credits,
                type: 'purchase',
                referenceId: $intent->id,
                idempotencyKey: $intent->id,
                description: "Stripe purchase: {$intent->id}",
            );
        }

        $card = $intent->payment_method->card ?? null;

        return Inertia::render('user/checkout/checkout-success', [
            'order' => [
                'number' => 'UKC-' . strtoupper(substr($intent->id, 3, 8)),
                'date' => now()->format('F j, Y'),
                'item' => $intent->metadata->product_name ?? "{$credits} Credit Top-up",
                'amount' => number_format($intent->amount / 100, 2),
                'currency' => strtoupper($intent->currency),
                'cardBrand' => $card->brand ?? null,
                'cardLast4' => $card->last4 ?? null,
            ],
            'creditsAvailable' => $user->fresh()->credits,
        ]);
    }
}
