<?php

namespace App\Http\Controllers\Webhook;

use App\Models\User;
use App\Http\Controllers\Controller;
use App\Services\CreditService;
use App\Models\Transaction;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
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
            Log::warning('Stripe webhook signature verification failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'invalid signature'], 400);
        }

        switch($event->type) {
            case 'payment_intent.succeeded':
                $intent = $event->data->object;
                $userId = $intent->metadata->user_id ?? null;
                $credit = (int) ($intent->metadata->credits ?? 0);

                if(!$userId || $credit <= 0) {
                    Log::warning('Stripe webhook: metadata hilang di payment_intent',[
                        'payment_intent' => $intent->id,
                    ]);

                    return response()->json(['received' => true]);
                }
                $user = User::find($userId);

                if(! $user) {
                    Log::warning('Stripe webhook: user not found', [
                        'user_id' => $userId
                    ]);

                    break;
                }
                    $creditService->grantCredits(
                        user : $user,
                        amount : $credit,
                        type : 'purchase',
                        referenceId : $intent->id,
                        description : "Stripe purchase : {$intent->id}",
                        idempotencyKey : $intent->id
                    );

                 Transaction::updateOrCreate(
                    [
                        'payment_gateway_ref' => $intent->id,
                    ],
                    [
                        'user_id' => $user->id,
                        'invoice_id' =>
                        'UKC-' . strtoupper(substr($intent->id, 3, 8)),
                        'currency' => strtoupper($intent->currency),
                        'amount' => $intent->amount / 100,
                        'type' => 'payment',
                        'category' => 'credit_purchase',
                        'description' =>
                            $intent->metadata->product_name
                            ?? 'Credit purchase',
                        'status' => 'success',
                        'paid_at' => now(),
                    ]
                );

                break;
            
            case 'checkout.session.completed':
                $session = $event->data->object;
                $userId = $session->metadata->user_id ?? null;
                $planSlug = $session->metadata->plan ?? null;

                if(!$userId || !$planSlug || !$session->subscription) {
                    break;
                }
                
                $user = User::find($userId);
                $plan = config("credit_plans.{$planSlug}");

                if (!$user || !$plan) {
                    break;
                }

                $stripe = new StripeClient(config('services.stripe.secret'));
                $stripeSubscription = $stripe->subscriptions->retrieve($session->subscription);

                $item = $stripeSubscription->items->data[0] ?? null;

                $priceId = $item?->price->id;
                $priceAmount = $item?->price?->unit_amount ? $item->price->unit_amount /100 : 0; 
                $currentPeriodEnd = $item?->current_period_end;

                $status = $this->mapSubscriptionStatus($stripeSubscription->status);

                Subscription::updateOrCreate(
                    [
                        'stripe_subscription_id' => $stripeSubscription-> id,
                    ],
                    [
                        'user_id' => $user->id,
                        'plan_name' => $planSlug,
                        'price' => $priceAmount,
                        'stripe_price_id' => $priceId,
                        'status' => $status,
                        'monthly_credits' => $plan['credits'],
                        'start_date' => now(),
                        'current_period_end' =>
                            $currentPeriodEnd
                                ? date('Y-m-d H:i:s', $currentPeriodEnd)
                                : null,
                        'cancel_at_period_end' =>
                            $stripeSubscription->cancel_at_period_end,
                    ]

                );

                break;
            
            case 'invoice.paid':
                $invoice = $event->data->object;

                Log::info('invoice.paid received', [
                    'invoice_id' => $invoice->id,
                ]);

                $stripeSubscriptionId = 
                    $invoice->parent?->subscription_details?->subscription ?? $invoice->subscription ?? null;

                    if(! $stripeSubscriptionId) {
                        break;
                    }

                    $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();

                    if(! $subscription) {
                        $stripe = new StripeClient(config('services.stripe.secret'));
                    

                        $stripeSubscription = $stripe->subscriptions->retrieve($stripeSubscriptionId);

                        $userId = $stripeSubscription->metadata->user_id ?? null;
                        $planSlug = $stripeSubscription->metadata->plan ?? null;

                        if(! $userId || !$planSlug) {
                            Log::warning('Cannot recover subscription from stripe',
                                [
                                    'stripe_subscription_id' => $stripeSubscription,
                                    'invoice_id' => $invoice->id,
                                ]
                            );

                            break;
                        }

                        $user = User::find($userId);
                        $plan = config("credit_plans.{$planSlug}");

                        if (!$user || !$plan) {
                            break;
                        }

                        $item = $stripeSubscription->items->data[0] ?? null;
                        $priceId = $item?->price->id;

                        $priceAmount = $item?->price?->unit_amount ? $item->price->unit_amount /100 : 0;

                        $currentPeriodEnd = $item?->current_period_end;

                        $subscription = Subscription::updateOrCreate(
                            [
                                'stripe_subscription_id' => $stripeSubscription-> id,
                            ],
                            [
                                'user_id' => $user->id,
                                'plan_name' => $planSlug,
                                'price' => $priceAmount,
                                'stripe_price_id' => $priceId,
                                'status' => $this->mapSubscriptionStatus($stripeSubscription->status),
                                'monthly_credits' => $plan['credits'],
                                'start_date' => now(),
                                'current_period_end' =>
                                    $currentPeriodEnd
                                        ? date('Y-m-d H:i:s', $currentPeriodEnd)
                                        : null,
                                'cancel_at_period_end' =>
                                    (bool)$stripeSubscription->cancel_at_period_end,
                            ]

                        );
                    }

                    $user = $subscription->user;

                    if (!$user) {
                        break;
                    }

                    if ($subscription->status !== 'active') {
                        $subscription->update([
                            'status' => 'active',
                        ]);
                    }

                    $credits = (int) $subscription->monthly_credits;

                    if ($credits <= 0 ){
                        Log::warning('Subscription has no monthly credits', [
                            'subscription_id' => $subscription->id,
                            'invoice_id' => $invoice->id,
                        ]);
                        break;
                    }

                    Log::info('Granting subscription credits', [
                        'user_id' => $user->id,
                        'credits' => $credits,
                        'invoice_id' => $invoice->id,
                    ]);

                    $creditService->grantCredits(
                        user: $user,
                        amount: $credits,
                        type: 'subscription',
                        referenceId: $invoice->id,
                        idempotencyKey: $invoice->id,
                        description: "Subscription payment: {$invoice->id}",
                    );

                    $planConfig = config("credit_plans.{$subscription->plan_name}");

                    Transaction::updateOrCreate(
                        [
                            'payment_gateway_ref' => $invoice->id,
                        ],
                        [
                            'user_id' => $user->id,
                            'invoice_id' => 'UKC-SUB-'. strtoupper(substr($invoice->id, 3, 8)),
                            'currency' => strtoupper($invoice->currency),
                            'amount' => $invoice->amount_paid /100,
                            'type' => 'payment',
                            'category' => 'subscription',
                            'description' => $planConfig['label']  ?? $subscription->plan_name,
                            'status' => 'success',
                            'paid_at' => now(),
                        ],
                    );
                break;
            case 'customer.subscription.updated':
                $stripeSubscription = $event->data->object;

                $subscription = Subscription::where('stripe_subscription_id', $stripeSubscription->id)->first();
                if(! $subscription) {
                    break;
                }

                $item = $stripeSubscription->items->data[0] ?? null;
                $currentPeriodEnd = $item?->current_period_end;
                $subscription->update([
                    'status' => $this->mapSubscriptionStatus($stripeSubscription->status),
                    'cancel_at_period_end' => (bool)$stripeSubscription->cancel_at_period_end,
                    'current_period_end' =>  $currentPeriodEnd ? date('Y-m-d H:i:s', $currentPeriodEnd): null,
                ]);

                break;

            case 'customer.subscription.deleted':
                $stripeSubscription = $event->data->object;
                $subscription = Subscription::where('stripe_subscription_id', $stripeSubscription->id)->first();

                if (! $subscription){
                    break;
                }

                $subscription->update([
                    'status' => 'cancelled',
                    'cancel_at_period_end' => false,
                    'cancelled_at' => now(),
                    'end_date' => now(),
                ]);

                break;
                
            case 'invoice.payment_failed':
                $invoice = $event->data->object;

                $stripeSubscriptionId = $invoice->parent?->subscription_details->subscription ?? $invoice->subscription ?? null;

                if(! $stripeSubscriptionId) {
                    break;
                }

                $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();

                if(! $subscription) {
                    break;
                }

                $subscription->update([
                    'status' => 'pending',
                ]);

                Log::warning('Subscription invoice payment failed', [
                    'subscription_id' => $subscription->id,
                    'invoice_id' => $invoice->id,
                ]);

                break;
        }
            
        return response()->json(['received' => true,]);
    }

    private function mapSubscriptionStatus(string $status): string
    {
        return match ($status){
            'active', 
            'trialing' => 'active',

            'canceled' => 'cancelled',
            'incomplete_expired' => 'expired',
            'incomplete',
            'past_due',
            'unpaid',
            'pause' => 'pending',

            default => 'pending',
        };
    }
}
