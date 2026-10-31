<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Jobs\SendTelegramSaleNotification;
use App\Models\Transaction;
use App\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class StripeController extends Controller
{
    public function createOneTimeIntent(Request $request): JsonResponse
    {
        $request->validate([
            'plan' => 'required|string',
        ]);

        $plan = config("credit_plans.{$request->plan}");

        if (! $plan || $plan['type'] !== 'one_time') {
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
                'user_id' => (string) $user->id,
                'credits' => (string) $plan['credits'],
                'price_id' => (string) $price->id,
                'product_name' => (string) $plan['label'],
                'plan' => (string) $request->plan,
            ],
        ]);

        return response()->json([
            'clientSecret' => $paymentIntent->client_secret,
        ]);
    }

    public function success(Request $request): RedirectResponse|InertiaResponse
    {
        $paymentIntentId = $request->query('payment_intent');

        if (! $paymentIntentId) {
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

        if ($credits <= 0) {
            return redirect('/')->with('modal', 'payment_failed');
        }
        app(CreditService::class)->grantCredits(
            user: $user,
            amount: $credits,
            type: 'purchase',
            referenceId: $intent->id,
            idempotencyKey: $intent->id,
            description: "Stripe purchase: {$intent->id}",
        );

        $paidAt = $intent->created
            ? date('Y-m-d H:i:s', $intent->created)
            : now();

        $transaction = Transaction::updateOrCreate(
            [
                'payment_gateway_ref' => $intent->id,
            ],
            [
                'user_id' => $user->id,

                'invoice_id' => 'UKC-'.
                    strtoupper(
                        substr(
                            $intent->id,
                            3,
                            8
                        )
                    ),

                'currency' => strtoupper($intent->currency),

                'amount' => $intent->amount / 100,

                'payment_method' => 'stripe',

                'type' => 'payment',

                'category' => 'credit_purchase',

                'description' => $intent->metadata->product_name
                    ?? "{$credits} Credit Top-up",

                'status' => 'success',

                'paid_at' => $paidAt,
            ]
        );

        if ($transaction->wasRecentlyCreated) {
            $amount = number_format(
                (float) $transaction->amount,
                2,
                '.',
                ','
            );

            SendTelegramSaleNotification::dispatch(
                'Credit purchase',
                'Stripe',
                (string) $intent->id,
                strtoupper((string) $transaction->currency).' '.$amount,
            )->afterCommit();
        }

        $card = $intent->payment_method->card ?? null;

        return Inertia::render('user/checkout/checkout-success', [
            'transaction' => [
                'id' => $transaction->id,
                'invoice_id' => $transaction->invoice_id,
                'description' => $transaction->description,
                'amount' => (string) $transaction->amount,
                'currency' => strtoupper($transaction->currency),
                'status' => $transaction->status,
                'payment_method' => $transaction->payment_method,
                'payment_gateway_ref' => $transaction->payment_gateway_ref,
                'paid_at' => $transaction->paid_at?->toISOString(),
            ],

            'order' => [
                'number' => $transaction->invoice_id,
                'date' => $transaction->paid_at?->format('F j, Y')
                    ?? now()->format('F j, Y'),
                'item' => $transaction->description,
                'amount' => number_format(
                    (float) $transaction->amount,
                    2
                ),
                'currency' => strtoupper(
                    $transaction->currency
                ),
                'cardBrand' => $card->brand ?? null,
                'cardLast4' => $card->last4 ?? null,
            ],
            'creditsAvailable' => $user->fresh()->credits,
        ]);
    }
}
