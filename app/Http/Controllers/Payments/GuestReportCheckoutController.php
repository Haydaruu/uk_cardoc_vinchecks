<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\SystemSetting;
use App\Services\GuestReportCaptureService;
use App\Services\GuestReportCheckoutService;
use App\Services\PayPalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class GuestReportCheckoutController extends Controller
{
    public function store(
        Request $request,
        Report $report,
        GuestReportCheckoutService $checkoutService,
    ): SymfonyResponse {
        if (! SystemSetting::getBool('enable_guest_checkout', true)) {
            return redirect()->route('page.my-report.show', [
                'report' => $report->id,
            ])->withErrors([
                'checkout' => 'Guest checkout is currently disabled by administrator.',
            ]);
        }

        $sessionKey = "guest_report_checkout.{$report->id}";
        $contact = $request->session()->get($sessionKey);

        if (
            ! is_array($contact)
            || ! is_string($contact['name'] ?? null)
            || ! is_string($contact['email'] ?? null)
            || ! is_string($contact['phone'] ?? null)
        ) {
            return redirect()->route('page.my-report.show', [
                'report' => $report->id,
            ])->withErrors([
                'checkout' => 'Please enter your contact details before continuing.',
            ]);
        }

        $reportUrl = route('page.my-report.show', [
            'report' => $report->id,
        ]);

        try {
            $checkout = $checkoutService->startCheckout(
                report: $report,
                name: $contact['name'],
                email: $contact['email'],
                phone: $contact['phone'],
                successUrl: route('guest-report.checkout.success')
                    .'?checkout=return&session_id={CHECKOUT_SESSION_ID}',
                cancelUrl: $reportUrl.'?checkout=cancelled',
            );
        } catch (RuntimeException $exception) {
            Log::warning('Guest report checkout could not be started.', [
                'report_id' => $report->id,
                'exception' => $exception::class,
            ]);

            return back()->withErrors([
                'checkout' => 'Checkout is currently unavailable. Please try again later.',
            ]);
        }

        return Inertia::location($checkout['checkout_url']);
    }

    public function createPayPalOrder(
        Request $request,
        Report $report,
        GuestReportCheckoutService $checkoutService,
        PayPalService $payPalService,
    ): JsonResponse {
        if (! SystemSetting::getBool('enable_guest_checkout', true)) {
            return response()->json([
                'message' => 'Guest checkout is currently disabled.',
            ], 403);
        }

        $contact = $request->session()->get(
            "guest_report_checkout.{$report->id}"
        );

        if (
            ! is_array($contact)
            || ! is_string($contact['name'] ?? null)
            || ! is_string($contact['email'] ?? null)
            || ! is_string($contact['phone'] ?? null)
        ) {
            return response()->json([
                'message' => 'Your contact details are missing. Please return to checkout.',
            ], 422);
        }

        try {
            $prepared = $checkoutService->preparePayPalPurchase(
                report: $report,
                name: $contact['name'],
                email: $contact['email'],
                phone: $contact['phone'],
            );

            /** @var GuestReportPurchase $purchase */
            $purchase = $prepared['purchase'];

            // Reuse the existing order for a retry, rather than creating duplicates.

            // Reuse and safely migrate an existing order for checkout retries.
            if ($purchase->provider_order_id) {
                $existingOrder = $payPalService->getOrder(
                    $purchase->provider_order_id,
                );

                $existingOrder = $payPalService->prepareGuestReportOrderForCapture(
                    $purchase,
                    $existingOrder,
                );

                if (! in_array(
                    $existingOrder['status'] ?? null,
                    ['CREATED', 'APPROVED'],
                    true,
                )) {
                    throw new RuntimeException(
                        'The existing PayPal order cannot be resumed. Please contact support.',
                    );
                }

                return response()->json([
                    'id' => $purchase->provider_order_id,
                ]);
            }

            $order = $payPalService->createGuestReportOrder($purchase);

            $purchase->update([
                'provider_order_id' => $order['id'],
            ]);

            return response()->json([
                'id' => $order['id'],
            ]);
        } catch (RuntimeException $exception) {
            Log::warning('PayPal guest report order could not be created.', [
                'report_id' => $report->id,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'message' => 'Unable to start PayPal checkout. Please try again.',
            ], 422);
        }
    }

    public function capturePayPalOrder(
        Request $request,
        Report $report,
        GuestReportCaptureService $captureService,
    ): JsonResponse {
        $validated = $request->validate([
            'order_id' => ['required', 'string', 'max:64'],
        ]);

        $orderId = $validated['order_id'];

        // Enforce that the purchase exists for this report and provider order before delegating
        $purchase = GuestReportPurchase::query()
            ->where('report_id', $report->id)
            ->where('payment_provider', 'paypal')
            ->where('provider_order_id', $orderId)
            ->firstOrFail();

        $result = $captureService->captureInitialBuyer($orderId);

        if ($result->isCompleted()) {
            return response()->json([
                'status' => 'success',
                'redirect' => $result->redirect,
            ]);
        }

        if ($result->isPayerActionRequired()) {
            return response()->json([
                'status' => 'payer_action_required',
                'payerActionUrl' => $result->payerActionUrl,
                'message' => $result->errorMessage ?? 'Customer authentication required.',
            ], 200);
        }

        if ($result->isProcessing()) {
            return response()->json([
                'status' => 'processing',
                'message' => $result->errorMessage ?? 'Payment is being processed. Please wait a moment.',
            ], 202);
        }

        if ($result->isFundingDeclined()) {
            return response()->json([
                'status' => 'funding_declined',
                'message' => $result->errorMessage ?? 'Payment method declined. Please choose a different payment method.',
            ], 422);
        }

        return response()->json([
            'status' => 'failed',
            'message' => $result->errorMessage ?? 'PayPal payment could not be confirmed. Please try again.',
        ], $result->httpStatus ?? 422);
    }

    public function prepare(Request $request, Report $report): RedirectResponse
    {
        if (! SystemSetting::getBool('enable_guest_checkout', true)) {
            return redirect()->route('page.my-report.show', [
                'report' => $report->id,
            ])->withErrors([
                'checkout' => 'Guest checkout is currently disabled by administrator.',
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'phone' => [
                'nullable',
                'string',
                'max:32',
                'regex:/^[0-9+().\s-]{7,32}$/',
            ],
        ]);

        $request->session()->put(
            "guest_report_checkout.{$report->id}",
            [
                'name' => trim($validated['name']),
                'email' => mb_strtolower(trim($validated['email'])),
                'phone' => trim((string) ($validated['phone'] ?? '')),
            ],
        );

        return redirect()->route('guest-report.checkout.show', [
            'report' => $report->id,
        ]);
    }

    public function show(
        Request $request,
        Report $report,
        GuestReportCheckoutService $checkoutService,
    ): RedirectResponse|Response {
        if (! SystemSetting::getBool('enable_guest_checkout', true)) {
            return redirect()->route('page.my-report.show', [
                'report' => $report->id,
            ])->withErrors([
                'checkout' => 'Guest checkout is currently disabled by administrator.',
            ]);
        }

        $sessionKey = "guest_report_checkout.{$report->id}";
        $contact = $request->session()->get($sessionKey);

        if (
            ! is_array($contact)
            || ! is_string($contact['name'] ?? null)
            || ! is_string($contact['email'] ?? null)
            || ! is_string($contact['phone'] ?? null)
        ) {
            return redirect()->route('page.my-report.show', [
                'report' => $report->id,
            ])->withErrors([
                'checkout' => 'Please enter your contact details before continuing.',
            ]);
        }

        try {
            $summary = $checkoutService->getCheckoutSummary($report);
        } catch (RuntimeException $exception) {
            Log::warning('Guest checkout page could not be opened.', [
                'report_id' => $report->id,
                'exception' => $exception::class,
            ]);

            return redirect()->route('page.my-report.show', [
                'report' => $report->id,
            ])->withErrors([
                'checkout' => 'This report is currently unavailable for purchase.',
            ]);
        }

        $data = $report->report_data ?? [];

        return Inertia::render('user/checkout/checkout', [
            'checkoutMode' => 'guest_report',

            // Props yang dibutuhkan checkout.tsx
            'plan' => 'guest_full_report',
            'planType' => 'one_time',
            'label' => 'Full Vehicle Report',
            'amountDisplay' => $summary['amountDisplay'],
            'credits' => 0,

            // PayPal guest checkout follows the configured PayPal environment.
            'paypalClientId' => config('services.paypal.client_id'),
            'paypalEnvironment' => config('services.paypal.mode') === 'live'
                ? 'production'
                : 'sandbox',

            'paypalEligibility' => [
                'amount' => number_format(
                    $summary['amountMinor'] / 100,
                    2,
                    '.',
                    ''
                ),
                'currencyCode' => strtoupper($summary['currency']),
            ],

            'guestReport' => [
                'id' => $report->id,
                'make' => data_get($data, 'make', ''),
                'model' => data_get($data, 'model', ''),
                'registrationNumber' => data_get(
                    $data,
                    'registrationNumber',
                    $report->vin ?? '',
                ),
            ],
        ]);
    }
}
