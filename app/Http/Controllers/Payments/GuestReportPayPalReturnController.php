<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\GuestReportPurchase;
use App\Services\GuestReportCaptureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class GuestReportPayPalReturnController extends Controller
{
    public function __invoke(
        Request $request,
        GuestReportCaptureService $captureService,
    ): Response {
        // 1. Validate the temporary signed URL.
        // PayPal appends query parameter `token` upon returning from 3DS challenge.
        // hasValidSignatureWhileIgnoring ensures this provider-added parameter does not invalidate
        // the signature of the merchant parameters (reference, order_id, expires).
        if (! $request->hasValidSignatureWhileIgnoring(['token'])) {
            Log::warning('GuestReportPayPalReturnController: Invalid or expired return signature', [
                'reference' => $request->query('reference'),
                'order_id' => $request->query('order_id'),
            ]);

            abort(403, 'Invalid or expired checkout return link.');
        }

        // 2. Resolve the purchase using the signed reference.
        $reference = $request->query('reference');

        if (! is_string($reference) || ! Str::isUuid($reference)) {
            abort(404, 'Invalid purchase reference.');
        }

        $purchase = GuestReportPurchase::query()
            ->with('report')
            ->where('payment_provider', 'paypal')
            ->where('checkout_reference', $reference)
            ->first();

        if (! $purchase) {
            abort(404, 'Guest report purchase not found.');
        }

        // 3. Ensure the signed provider order ID strictly matches the purchase.
        $signedOrderId = $request->query('order_id');

        if (! is_string($signedOrderId) || $signedOrderId === '' || $purchase->provider_order_id !== $signedOrderId) {
            Log::warning('GuestReportPayPalReturnController: Signed order ID mismatch', [
                'purchase_id' => $purchase->id,
                'expected_order_id' => $purchase->provider_order_id,
                'signed_order_id' => $signedOrderId,
            ]);

            abort(403, 'Order ID mismatch.');
        }

        // 4. Verify callback token if sent by PayPal. It must match the signed provider order ID.
        $callbackToken = $request->query('token');

        if ($callbackToken !== null && $callbackToken !== '') {
            if (! is_string($callbackToken) || ! hash_equals((string) $purchase->provider_order_id, $callbackToken)) {
                Log::warning('GuestReportPayPalReturnController: Callback token mismatch', [
                    'purchase_id' => $purchase->id,
                    'expected_order_id' => $purchase->provider_order_id,
                    'callback_token' => $callbackToken,
                ]);

                abort(403, 'Callback token mismatch.');
            }
        }

        // 5. Continue capture using existing attempt and idempotency key.
        $result = $captureService->continuePayerAction($purchase->provider_order_id);

        if ($result->isCompleted() && $result->redirect) {
            return redirect()->to($result->redirect);
        }

        if ($result->isProcessing()) {
            $statusUrl = URL::temporarySignedRoute(
                'guest-report.checkout.status',
                now()->addHours(2),
                ['purchase' => $purchase->id],
            );

            return response()->view('guest-checkout-pending', [
                'statusUrl' => $statusUrl,
            ]);
        }

        if ($result->isPayerActionRequired() && $result->payerActionUrl) {
            return redirect()->away($result->payerActionUrl);
        }

        $errorMessage = $result->isFundingDeclined()
            ? ($result->errorMessage ?? 'Payment method was declined. Please try another payment method.')
            : ($result->errorMessage ?? 'PayPal payment could not be confirmed. Please try again.');

        return redirect()->route('guest-report.checkout.show', [
            'report' => $purchase->report_id,
        ])->withErrors([
            'checkout' => $errorMessage,
        ]);
    }
}
