<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CreditService;
use App\Services\PayPalService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayPalWebhookController extends Controller
{
    public function handle(Request $request, PayPalService $payPalService, CreditService $creditService)
    {
        if (! $payPalService->verifyWebhook($request)) {
            Log::warning('Invalid PayPal webhook signature.');

            return response()->json([
                'message' => 'Invalid webhook siganture',
            ], 400);
        }

        $event = $request->json()->all();
        $eventType = $event['event_type'] ?? null;

        try{
            match($eventType) {
                'PAYMENT.CAPTURE.COMPLETED' => $this->handleOnetimePayment(
                    $event,
                    $payPalService,
                    $creditService
                ),

                'PAYMENT.SALE.COMPLETED' => $this->handleSubscriptionPayment(
                    $event,
                    $payPalService,
                    $creditService
                ),

                'BILLING.SUBSCRIPTION.ACTIVATED' => $this->handleSubscriptionActivated($event),
                'BILLING.SUBSCRIPTION.CANCELLED' => $this->handleSubscriptionCancelled($event),
                'BILLING.SUBSCRIPTION.SUSPENDED' => $this->handleSubscriptionSuspended($event),
                'BILLING.SUBSCRIPTION.EXPIRED' => $this->handleSubscriptionExpired($event),
                'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => $this->handleSubscriptionPaymentFailed($event),
                default => null,
            };
        } catch(\Throwable $e){
            report($e);

            return response()->json([
                'message' => 'Unable to process PayPal webhook.',
            ], 500);
        }
        return response()->json([
            'received' => true,
        ]);

        if($eventType !== 'PAYMENT.CAPTURE.COMPLETED') {
            return response()->json([
                'message' => true,
            ]);
        }
    }

    private function handleOneTimePayment(array $event, PayPalService $payPalService, CreditService $creditService): void
    {
        $capture = $event['resource'] ?? null;

        if(! $capture) return; 

        $captureId = $capture['id'] ?? null;
        $orderId = $capture['supplementary_data']['related_ids']['order_id'] ?? null;

        if(!$captureId || !$orderId) {
            Log::warning('PayPal webhook missing capture/order ID',[
                    'event_id' => $event['id'] ?? null,
            ]);

            return;
        }

        $order = $payPalService->getOrder($orderId);
        $purchaseUnit = $order['purchase_units'][0] ?? null;

        if(! $purchaseUnit) {
            throw new \RuntimeException('Invalid PayPal order.');
        }

        $planSlug = $purchaseUnit['reference_id'] ?? null;
        $customId = $purchaseUnit['custom_id'] ?? null;

        [$userId, $customPlanSlug] = array_pad(explode('|',(string) $customId,2),2,null);

        if(! $userId || !$planSlug || $planSlug !== $customPlanSlug) {
           throw new \RuntimeException('PayPal order metadata is invalid. ');
        }
        $user = User::find($userId);
        $plan = config("credit_plans.{$planSlug}");

        if (! $user || ! $plan || $plan['type'] !== 'one_time') {
            throw new \RuntimeException('Unable to resolve PayPal purchase. ');
        }

        $expectedAmount = number_format($plan['amount_minor'] / 100, 2, '.', '');
        $expectedCurrency = strtoupper($plan['currency']);

        $captureAmount = $capture['amount']['value'] ?? null;
        $captureCurrency = strtoupper($capture['amount']['currency_code'] ?? '');

        if ($captureAmount !== $expectedAmount || $captureCurrency !== $expectedCurrency) {
            throw new \RuntimeException('PayPal payment amount mismatch.');
        }

        $creditService->grantCredits(
            user: $user,
            amount: (int) $plan['credits'],
            type: 'purchase',
            referenceId: $captureId,
            idempotencyKey: $captureId,
            description: "PayPal purchase: {$captureId}",
        );

        $paidAt = isset($capture['create_time']) ? Carbon::parse($capture['create_time']): now();

        Transaction::updateOrCreate(
            [
                'payment_gateway_ref' => $captureId
            ],
            [
                'user_id' => $user->id,
                'invoice_id' => 'UKC-PPL-'. strtoupper(substr($captureId, 0, 8)),
                'currency' => $captureCurrency,
                'amount' => $captureAmount,
                'type' => 'payment',
                'category' => 'credit_purchase',
                'payment_method' => 'paypal',
                'description' => $plan['label'],
                'status' => 'success',
                'paid_at' => $paidAt,

            ],
        );
    }

    private function handleSubscriptionPayment(array $event, PayPalService $payPalService, CreditService $creditService): void
    {
        $sale = $event['resource'] ?? null;
        if(! $sale) return;

        $saleId = $sale['id'] ?? null;

        $subscriptionId = $sale['billing_agreement_id'] ?? $sale['billing_agreement']['id'] ?? null;

        if(! $saleId || ! $subscriptionId) {
            Log::warning('PayPal subscription payment missing sale/subscription ID', [
                'event_id' => $event['id'] ?? null,
                'sale_id' => $saleId, 
            ]);

            return;
        }

        $paypalSubscription = $payPalService->getSubscription($subscriptionId);
        $customId = $paypalSubscription['custom_id'] ?? null;

        [$userId, $planSlug] = array_pad(explode('|', (string)$customId,2),2,null);

        if(! $userId || ! $planSlug) {
            throw new \RuntimeException('Unable to resolve PayPal subscription');
        }

        $user = User::find($userId);
        $plan = config("credit_plans.{$planSlug}");

        if(! $user || ! $plan || $plan['type'] !== 'subscription') {
            throw new \RuntimeException('Unable to resolve PayPal subscription.');
        }

        $paypalPlanId = $paypalSubscription['plan_id'] ?? null;

        if(! $paypalPlanId || $paypalPlanId !== ($plan['paypal_plan_id'] ?? null)) {
            throw new \RuntimeException('PayPal subscription plan mismacth.');
        }

        $saleAmount = $sale['amount']['total'] ?? $sale['amount']['value'] ?? null;
        $saleCurrency = strtoupper($sale['amount']['currency'] ?? $sale['amount']['currency_code'] ?? '');

        $expectedAmount = number_format($plan['amount_minor'] / 100, 2, '.', '');
        $expectedCurrency = strtoupper($plan['currency']);

        if($saleAmount !== $expectedAmount || $saleCurrency !== $expectedCurrency) {
            Log::warning('PayPal subscription payment amount mismacth', [
                'sale_id' => $saleId,
                'subscription_id' => $subscriptionId,
                'expected_amount' => $expectedAmount,
                'received_amount' => $saleAmount,
                'expected_currency' => $expectedCurrency,
                'received_currency' => $saleCurrency,
            ]);

            throw new \RuntimeException('PayPal subscription payment amount mismatch.');
        }

         $creditService->grantCredits(
            user: $user,
            amount: (int) $plan['credits'],
            type: 'subscription',
            referenceId: $saleId,
            idempotencyKey: $saleId,
            description: "PayPal subscription payment: {$saleId}",
        );

        $paidAt = isset($sale['create_time'])
            ? Carbon::parse($sale['create_time'])
            : now();

        Transaction::updateOrCreate(
            [
                'payment_gateway_ref' => $saleId,
            ],
            [
                'user_id' => $user->id,
                'invoice_id' => 'UKC-PPS-'. strtoupper(substr($saleId, 0, 8)),
                'currency' => $saleCurrency,
                'amount' => $saleAmount,
                'type' => 'payment',
                'category' => 'subscription',
                'payment_method' => 'paypal',
                'description' => $plan['label'],
                'status' => 'success',
                'paid_at' => $paidAt,
            ]
        );

        $nextBillingTime = isset($paypalSubscription['billing_info']['next_billing_time'])
            ? Carbon::parse($paypalSubscription['billing_info']['next_billing_time'])
            : null;

        Subscription::updateOrCreate(
            [
                'paypal_subscription_id' => $subscriptionId,
            ],
            [
                'user_id' => $user->id,
                'plan_name' => $planSlug,
                'price' => $plan['amount_minor'] / 100,
                'payment_method' => 'paypal',
                'start_date' => isset($paypalSubscription['start_time'])
                    ? Carbon::parse($paypalSubscription['start_time'])
                    : now(),
                'end_date' => null,
                'status' => 'active',
                'monthly_credits' => $plan['credits'],
                'paypal_plan_id' => $paypalPlanId,
                'current_period_end' => $nextBillingTime,
                'cancelled_at' => null,
                'cancel_at_period_end' => false,
            ]
        );

        Log::info('PayPal subscription credits granted', [
            'user_id' => $user->id,
            'subscription_id' => $subscriptionId,
            'sale_id' => $saleId,
            'credits' => $plan['credits'],
        ]);
    }

    private function handleSubscriptionActivated(array $event): void
    {
        $resource = $event['resource'] ?? [];
        $subscriptionId = $resource['id'] ?? null;

        if(! $subscriptionId) return;

        Subscription::where('paypal_subscription_id', $subscriptionId)->update([
            'status' => 'active',
            'cancelled_at' => null,
        ]);
    }

    private function handleSubscriptionCancelled(array $event): void
    {
        $subscriptionId = $event['resource']['id'] ?? null;

        if(! $subscriptionId) return;

        $subscription = Subscription::where(
            'paypal_subscription_id',
            $subscriptionId
        )->first();

        if(! $subscription) return;

        if(
            $subscription->current_period_end &&
            $subscription->current_period_end->isFuture()
        ) {
            $subscription->update([
                'cancel_at_period_end' => true,
                'cancelled_at' => now(),
                'end_date' => $subscription->current_period_end,
            ]);

            return;
        }

        $subscription->update([
            'status' => 'cancelled',
            'cancel_at_period_end' => false,
            'cancelled_at' => now(),
            'end_date' => now(),
        ]);
    }

    private function handleSubscriptionSuspended(array $event): void
    {
        $subscriptionId = $event['resource']['id'] ?? null;

        if(! $subscriptionId) return;

        Subscription::where('paypal_subscription_id', $subscriptionId)->update([
            'status' => 'pending',
        ]);
    }

    private function handleSubscriptionExpired(array $event): void
    {
        $subscriptionId = $event['resource']['id'] ?? null;

        if(! $subscriptionId) return;

        Subscription::where('paypal_subscription_id', $subscriptionId)->update([
            'status' => 'expired',
            'end_date' => now(),
        ]);
    }

    private function handleSubscriptionPaymentFailed(array $event): void
    {
        $subscriptionId = $event['resource']['id'] ?? null;

        if(! $subscriptionId) return;

        Subscription::where('paypal_subscription_id', $subscriptionId)->update([
            'status' => 'pending',
        ]);

        Log::warning('PayPal subscription payment failed', [
            'subscription_id' => $subscriptionId,
        ]);
    }
}
