<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CreditService;
use App\Services\PayPalService;
use Carbon\Carbon;

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


        if($eventType !== 'PAYMENT.CAPTURE.COMPLETED') {
            return response()->json([
                'message' => true,
            ]);
        }

        $capture = $event['resource'] ?? null;

        if(! $capture) {
            return response()->json([
                'message' => true,
            ]);
        }

        $captureId = $capture['id'] ?? null;

        $orderId = $capture['suplementary_data']['related_ids']['order_id'] ?? null;

        if(!$captureId || !$orderId) {
            Log::warning('PayPal webhook missing capture/order ID',
                [
                    'event_id' => $event['id'] ?? null,
                ]
            );

            return response()->json([
                'received' => true,
            ]);
        }

        try {
            $order = $payPalService ->getOrder($orderId);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to retrieve PayPal order.',
            ], 500);
        }

        $purchaseUnit = $order['purchase_unit'][0] ?? null;

        if(! $purchaseUnit) {
            return response()->json([
                'message' => 'Invalid PayPal order',
            ], 422);
        }

        $planSlug = $purchaseUnit['reference_id'] ?? null;

        $customId = $purchaseUnit['custom_id'] ?? null;

        [$userId, $customPlanSlug] = array_pad(explode('|',(string) $customId,2),2,null);

        if(! $userId || !$planSlug || $planSlug !== $customPlanSlug) {
            return response()->json([
                'message' => 'PayPal order metadata is invalid',
            ], 422);
        }

        $user = User::find($userId);

        $plan = config("credit_plans.{$planSlug}");

        if (! $user || ! $plan || $plan['type'] !== 'one_time') {
            return response()->json([
                'message' => 'Unable to resolve PayPal purchase'
            ], 422);
        }

        $expectedAmount = number_format($plan['amount_minor'] / 100, 2, '.', '');

        $expectedCurrency = strtoupper($plan['currency']);

        $captureAmount = $capture['amount']['value'] ?? null;

        $captureCurrency = strtoupper($capture['amount']['currency_code'] ?? '');

        if ($captureAmount !== $expectedAmount || $captureCurrency !== $expectedCurrency) {
            Log::warning('PayPal webhook amount mismacth.', 
                [
                    'capture_id' => $captureId,
                    'expected_amount' => $expectedAmount,
                    'capture_amount' => $captureAmount,
                ]
            );

            return response()->json([
                'message' => 'Payment amount mismacth',
            ], 422);
        }

        $creditService->grantCredits(
            user: $user,
            amount: (int) $plan['credits'],
            type: 'purchase',
            referenceId: $captureId,
            idempotecyKey: $captureId,
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

        return response()->json([
            'received' => true,
        ]);
    }
}
