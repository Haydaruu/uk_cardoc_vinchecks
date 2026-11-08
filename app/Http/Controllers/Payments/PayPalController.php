<?php

namespace App\Http\Controllers\Payments;

use App\Exceptions\PayPalCaptureDeniedException;
use App\Exceptions\PayPalCapturePendingException;
use App\Exceptions\PayPalOrderOwnershipException;
use App\Http\Controllers\Controller;
use App\Models\CreditCheckoutAttempt;
use App\Models\Transaction;
use App\Services\CreditCaptureService;
use App\Services\CreditCheckoutService;
use App\Services\PayPalService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use RuntimeException;

class PayPalController extends Controller
{
    public function createOrder(Request $request, CreditCheckoutService $checkoutService): JsonResponse
    {
        $request->validate([
            'plan' => ['required', 'string'],
            'intent_reference' => ['nullable', 'string', 'max:64'],
        ]);

        $planSlug = $request->string('plan')->toString();

        $plan = config("credit_plans.{$planSlug}");

        if (! $plan || ($plan['type'] ?? null) !== 'one_time') {
            return response()->json([
                'message' => 'Invalid credit plan.',
            ], 422);
        }

        $intentReference = $request->filled('intent_reference')
            ? $request->string('intent_reference')->toString()
            : null;

        if ($intentReference === null) {
            $sessionKey = "checkout_intent.{$planSlug}";
            $cachedIntent = $request->session()->get($sessionKey);

            if (is_string($cachedIntent) && $cachedIntent !== '') {
                $existing = CreditCheckoutAttempt::query()
                    ->where('user_id', $request->user()->id)
                    ->where('intent_reference', $cachedIntent)
                    ->first();

                if ($existing && ! $existing->isCompleted() && ! $existing->isExpired() && $existing->status !== CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE) {
                    $intentReference = $cachedIntent;
                }
            }

            if ($intentReference === null) {
                $intentReference = (string) Str::uuid();
                $request->session()->put($sessionKey, $intentReference);
            }
        }

        try {
            $result = $checkoutService->createPayPalOrder($request->user(), $planSlug, $intentReference);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to create PayPal order.',
            ], 502);
        }

        if (($result['status'] ?? null) === 'processing') {
            return response()->json([
                'status' => 'processing',
                'attempt_id' => $result['attempt_id'],
                'retry_after' => $result['retry_after'] ?? 2,
            ], 202);
        }

        return response()->json([
            'id' => $result['id'],
            'attempt_id' => $result['attempt_id'],
        ]);
    }

    public function capture(Request $request, CreditCaptureService $captureService): JsonResponse
    {
        $request->validate([
            'order_id' => ['required', 'string'],
        ]);

        $user = $request->user();
        $orderId = $request->string('order_id')->toString();

        try {
            $captureService->captureOrderAndFulfill(
                orderId: $orderId,
                user: $user,
            );
        } catch (PayPalOrderOwnershipException $e) {
            return response()->json(['message' => 'PayPal order ownership validation failed.'], 403);
        } catch (PayPalCapturePendingException $e) {
            return response()->json([
                'status' => 'pending',
                'capture_status' => 'PENDING',
                'message' => 'PayPal payment is pending clearance. Your credits will be added automatically once cleared.',
            ], 202);
        } catch (PayPalCaptureDeniedException $e) {
            return response()->json([
                'status' => 'failed',
                'capture_status' => $e->getCaptureStatus(),
                'message' => 'PayPal payment capture was declined or failed.',
            ], 422);
        } catch (RuntimeException $e) {
            report($e);

            if (
                str_contains($e->getMessage(), 'already being processed')
                || str_contains($e->getMessage(), 'timed out')
                || str_contains($e->getMessage(), 'reconciliation')
            ) {
                return response()->json([
                    'status' => 'processing',
                    'message' => 'Payment capture is currently being processed or reconciled. Please wait a moment.',
                    'retry_after' => 3,
                ], 202);
            }

            if (
                str_contains($e->getMessage(), 'Invalid')
                || str_contains($e->getMessage(), 'missing')
            ) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json([
                'status' => 'processing',
                'message' => 'Payment capture is being reconciled with PayPal. Please check back shortly.',
                'retry_after' => 5,
            ], 202);
        }

        return response()->json([
            'status' => 'success',
            'redirect' => route('checkout.paypal.success', ['order_id' => $orderId]),
        ]);
    }

    public function success(Request $request, PayPalService $payPalService): RedirectResponse|InertiaResponse
    {
        $request->validate([
            'order_id' => ['required', 'string'],
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
        $parts = explode('|', (string) $customId, 3);
        $orderUserId = $parts[0] ?? null;
        $customPlanSlug = $parts[1] ?? null;

        if ((string) $orderUserId !== (string) $user->id || $planSlug !== $customPlanSlug) {
            abort(403);
        }

        $plan = config("credit_plans.{$planSlug}");

        if (! $plan) {
            return redirect('/');
        }

        $capture = $purchaseUnit['payments']['captures'][0] ?? null;

        if (! $capture || ($capture['status'] ?? null) !== 'COMPLETED') {
            return redirect('/')->with('modal', 'payment_failed');
        }

        $captureId = $capture['id'];
        $paidAt = isset($capture['create_time']) ? Carbon::parse($capture['create_time']) : now();

        $transaction = Transaction::updateOrCreate(
            [
                'payment_gateway_ref' => $captureId,
            ],
            [
                'user_id' => $user->id,

                'invoice_id' => 'UKC-PPL-'.
                    strtoupper(
                        substr(
                            $captureId,
                            0,
                            8
                        )
                    ),

                'currency' => strtoupper(
                    $capture['amount']['currency_code']
                ),

                'amount' => $capture['amount']['value'],
                'payment_method' => 'paypal',
                'type' => 'payment',
                'category' => 'credit_purchase',
                'description' => $plan['label'],
                'status' => 'success',
                'paid_at' => $paidAt,
            ]
        );

        return Inertia::render('user/checkout/checkout-success',
            [
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
                    'date' => $transaction->paid_at ? $transaction->paid_at->format('F j, Y') : $paidAt->format('F j, Y'),
                    'item' => $transaction->description,
                    'amount' => number_format((float) $transaction->amount, 2),
                    'currency' => strtoupper($transaction->currency),
                    'cardBrand' => null,
                    'cardLast4' => null,
                ],

                'creditsAvailable' => $user->fresh()->credits,
            ],
        );
    }
}
