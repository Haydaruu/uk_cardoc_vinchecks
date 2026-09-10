<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Services\CreditService;
use App\Services\PayPalService;
use Carbon\Carbon;
use Inertia\Inertia;
use RuntimeException;

class PayPalController extends Controller
{
    public function createOrder(Request $request, PayPalService $payPalService)
    {
        $request->validate([
            'plan' => ['required', 'string'],
        ]);

        $planSlug = $request->string('plan')->toString();

        $plan = config("credit_plans.{$planSlug}");

        if(! $plan || $plan['type'] !== 'one_time') {
            return response()->json([
                'message' => 'Invalid credit plan.',
            ], 422);
        }

        try{
            $order = $payPalService->createOrder($request->user(),$planSlug,);
        } catch (RuntimeException $e) {
            report($e);

            return  response()->json([
                'message' => 'Unable to create PayPal order.',
            ], 502);
        }

        return response()->json([
            'id' => $order['id'],
        ]);
    }

    public function capture(Request $request, PayPalService $payPalService, CreditService $creditService)
    {
        $request->validate([
            'order_id' => ['required', 'string'],
        ]);

        $user = $request->user();
        $orderId = $request->string('order_id')->toString();

        try{
            $order = $payPalService->getOrder($orderId);
            $purchaseUnit = $order['purchase_units'][0] ?? null;

            if(! $purchaseUnit) {
                return response()->json([
                    'message' => 'Invalid PayPal order. '
                ], 422);
            }

            $planSlug = $purchaseUnit['reference_id'] ?? null;
            $customId = $purchaseUnit['custom_id'] ?? null;

            [$orderUserId, $customPlanSlug] = array_pad(explode('|', (string) $customId,2),2,null);

            if((string) $orderUserId !== (string) $user->id || $planSlug !== $customPlanSlug){
                return response()->json([
                    'message' => 'PayPal order validation failed'
                ], 403);
            }

            $plan = config("credit_plans.{$planSlug}");

            if(! $plan || $plan['type'] !== 'one_time') {
                return response()->json([
                    'message' => 'Invalid PayPal credit plan.',
                ],422);
            }

            if(($order['status'] ?? null) === 'COMPLETED') {
                $capturedOrder = $order;
            } else {
                $capturedOrder = $payPalService->captureOrder($orderId);
            }

        } catch (RuntimeException $e) {
                report($e);

                return response()->json([
                    'message' => 'Unable to capture PayPal payment.',
                ],502);
            }

            if(($capturedOrder['status'] ?? null) !== 'COMPLETED') {
                return response()->json([
                    'message' => 'PayPal payment has not completed.',
                ], 422);
            }

            $purchaseUnit = $capturedOrder['purchase_units'][0] ?? null;
            $capture = $purchaseUnit['payments']['captures'][0] ?? null;

            if(! $capture || ($capture['status'] ?? null) !== 'COMPLETED') {
                return response()->json([
                    'message' => 'PayPal capture is not completed.',
                ], 422);
            }

            $captureId = $capture['id'] ?? null;

            if(! $captureId) {
                return response()->json([
                    'message' => 'PayPal capture ID is missing',
                ], 422);
            }
            $expectedAmount = number_format($plan['amount_minor']/100,2,'.','');

            $expectedCurrency = strtoupper($plan['currency']);

            $captureAmount = $capture['amount']['value'] ?? null;

            $captureCurrency = strtoupper($capture['amount']['currency_code'] ?? '');

            if ($captureAmount !== $expectedAmount || $captureCurrency !== $expectedCurrency) {
                return response()->json([
                    'message' => 'PayPal payment amount does not match the selected plan.',
                ], 422);
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
                    'payment_gateway_ref' => $captureId,
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
                'status' => 'success',
                'redirect' => route('checkout.paypal.success',
                    [
                        'order_id' => $orderId,
                    ]
                ),
            ]);
    }

    public function success(Request $request, PayPalService $payPalService)
    {
        $request->validate([
            'order_id'=> ['required', 'string'],
        ]);

        $user = $request->user();

        try {
            $order = $payPalService->getOrder($request->string('order_id')->toString());
        } catch (RuntimeException $e) {
            report($e);

            return redirect('/')->with('modal', 'payment_failed');
        }

        if (($order['status'] ?? null) !== 'COMPLETED') {
            return redirect('/')->with('modal', 'payment_failed');
        }

        $purchaseUnit = $order['purchase_units'][0] ?? null;
        $planSlug = $purchaseUnit['reference_id'] ?? null;
        $customId = $purchaseUnit['custom_id'] ?? null;
        [$orderUserId, $customPlanSlug] = array_pad(explode('|', (string)$customId,2),2,null);

        if ((string) $orderUserId !== (string) $user->id || $planSlug !== $customPlanSlug) {
            abort(403);
        }

        $plan = config("credit_plans.{$planSlug}");

        if(!$plan) {
            return redirect('/');
        }

        $capture = $purchaseUnit['payments']['captures'][0] ?? null;

        if(! $capture || ($capture['status'] ?? null) !== 'COMPLETED'){
            return redirect('/')->with('modal', 'payment_failed');
        }

        $captureId = $capture['id'];
        $paidAt = isset($capture['create_time']) ? Carbon::parse($capture['create_time']):now();

        return Inertia::render('user/checkout/checkout-success',
            [
                'order' => [
                    'number' => 'UKC-PPL-'. strtoupper(substr($captureId, 0,8)),
                    'date' => $paidAt->format('F j, Y'),
                    'item' => $plan['label'],
                    'amount' => number_format((float)$capture['amount']['value'],2),
                    'currency' => strtoupper($capture['amount']['currency_code']),
                    'cardBrand' => null,
                    'cardLast4' => null,
                ],
                
                'creditsAvailable' => $user->fresh()->credits,
            ],
        );
    }
}
