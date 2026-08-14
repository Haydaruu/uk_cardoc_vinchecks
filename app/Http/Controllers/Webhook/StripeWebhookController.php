<?php

namespace App\Http\Controllers\Webhook;

use App\Models\User;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;   

class StripeWebhookController extends Controller
{
    public function handle(Request $request, CreditService $creditService)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        try{
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (UnexpectedValueException|SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed'. ['error' => $e->getMessage()]);

            return response->json(['error' => 'invalid signature'], 400);
        }

        if($event->type == 'payment_intent.succeeded') {
            $intent = $event->data->object;

            $userId = $intent->metadata->user_id ?? null;
            $credit = (int) ($intent->metadata->credits ?? 0);

            if(!$userId | $credit <= 0) {
                Log::warning('Stripe webhook: metadata hilang di payment_intent',[
                    'payment_intent' => $intent->id,
                ]);

                return response()->json(['received' => true]);
            }

            $user = user::find($userId);

            if($user) {
                $creditService->grantCredits(
                    user : $user,
                    credits : $credit,
                    type : 'purchase',
                    referenceId : $intent->id,
                    description : "Stripe purchase : {$intent->id}",
                    idempotencyKey : $event->id
                );
            }
        }

        return response()->json(['received' => true]);
    }   
}
