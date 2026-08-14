<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

class PaymentController extends Controller
{
    public function createIntent(Request $request)
    {
        $request->validate([
            'price_id' => 'required|string',
            'report_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        $stripe = new StripeClient(config('services.stipe.secret'));

        try {
            $price = $stripe->prices-retrieve($request->price_id,[
                'expand' => ['product'],
            ]);
        } catch (ApiErrorException $e) {
            return response()->json(['message' => 'Price ID tidak valid'], 422);
        }

        $credits = (int) ($price->product->metadata['credits'] ?? 0);

        if ($credits <= 0) {
            return response()->json(['message' => 'Produk belum diset metadata credits.'], 422);
        }


        $paymentIntent = $stripe->paymentIntents->create([
            'amount' => $price->unit_amount,
            'currency' => $price->currency,
            'automatic_payment_methods' => ['enabled' => true ],
            'metadata' => [
                'user_id' => $user->id,
                'credits' => $credits,
                'price_id' => $price->id,
                'report_id' => $request->report_id,
            ],
        ]);

        return response()->json([
            'clientSecret' => $paymentIntent->client_secret,
        ]);
    }
}
