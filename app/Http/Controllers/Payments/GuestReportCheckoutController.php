<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Services\GuestReportPurchaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class GuestReportCheckoutController extends Controller
{
    public function start(Report $report, Request $request)
    {
        abort_if($request->user() && $report->user_id && $request->user()->id !== $report->user_id, 403);

        if ($report->report_type === 'premium') {
            return redirect()->route('page.my-report.show', $report->id);
        }

        abort_unless($report->vin_check_id, 422, 'This report cannot be purchased.');

        $priceId = config('services.stripe.report_price_id');
        abort_unless($priceId, 503, 'Direct report checkout is not configured.');

        $purchase = GuestReportPurchase::firstOrCreate(
            ['report_id' => $report->id],
            [
                'amount_minor' => 0,
                'currency' => 'GBP',
                'status' => 'pending',
            ],
        );

        if ($purchase->status === 'completed') {
            return redirect()->route('guest-report.show', $purchase);
        }

        if ($purchase->status === 'processing') {
            return redirect()->route('guest-report.show', $purchase);
        }

        $accessToken = Str::random(64);
        $purchase->update([
            'access_token_hash' => hash('sha256', $accessToken),
            'status' => 'pending',
        ]);

        $stripe = new StripeClient(config('services.stripe.secret'));

        try {
            $price = $stripe->prices->retrieve($priceId);
            abort_unless($price->active && $price->unit_amount > 0, 503, 'Direct report price is not available.');

            $purchase->update([
                'amount_minor' => $price->unit_amount,
                'currency' => strtoupper($price->currency),
                'provider' => 'stripe',
            ]);

            $session = $stripe->checkout->sessions->create([
                'mode' => 'payment',
                'line_items' => [['price' => $priceId, 'quantity' => 1]],
                'client_reference_id' => (string) $purchase->id,
                'metadata' => [
                    'guest_report_purchase_id' => (string) $purchase->id,
                    'report_id' => (string) $report->id,
                ],
                'payment_intent_data' => [
                    'metadata' => [
                        'guest_report_purchase_id' => (string) $purchase->id,
                        'report_id' => (string) $report->id,
                    ],
                ],
                'success_url' => route('guest-report.payment-success', $purchase) . '?token=' . $accessToken . '&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('guest-report.show', $purchase) . '?token=' . $accessToken . '&cancelled=1',
            ], [
                'idempotency_key' => 'guest-report-' . $purchase->id,
            ]);
        } catch (ApiErrorException $exception) {
            Log::error('Unable to create guest report checkout session', [
                'purchase_id' => $purchase->id,
                'error' => $exception->getMessage(),
            ]);

            return back()->withErrors([
                'checkout' => 'We could not start checkout. Please try again.',
            ]);
        }

        return Inertia::location($session->url);
    }

    public function paymentSuccess(
        GuestReportPurchase $purchase,
        Request $request,
        GuestReportPurchaseService $purchaseService,
    ) {
        $this->authorizeToken($purchase, (string) $request->query('token'));

        $sessionId = $request->query('session_id');
        abort_unless(is_string($sessionId) && str_starts_with($sessionId, 'cs_'), 422);

        $stripe = new StripeClient(config('services.stripe.secret'));

        try {
            $session = $stripe->checkout->sessions->retrieve($sessionId);
        } catch (ApiErrorException $exception) {
            Log::warning('Unable to verify guest report checkout', [
                'purchase_id' => $purchase->id,
                'error' => $exception->getMessage(),
            ]);

            return redirect()->route('guest-report.show', [
                'purchase' => $purchase,
                'token' => $request->query('token'),
            ])->with('paymentStatus', 'pending');
        }

        abort_unless(
            (string) ($session->metadata->guest_report_purchase_id ?? '') === (string) $purchase->id
            && (string) ($session->metadata->report_id ?? '') === (string) $purchase->report_id,
            403,
        );

        if ($session->payment_status !== 'paid') {
            return redirect()->route('guest-report.show', [
                'purchase' => $purchase,
                'token' => $request->query('token'),
            ])->with('paymentStatus', 'pending');
        }

        abort_unless(
            (int) $session->amount_total === (int) $purchase->amount_minor
            && strtoupper((string) $session->currency) === strtoupper($purchase->currency),
            422,
            'Payment amount did not match the report price.',
        );

        $purchase->update([
            'gateway_reference' => $session->payment_intent ?: $session->id,
            'paid_at' => $purchase->paid_at ?? now(),
        ]);

        $purchaseService->markPaidAndDispatch($purchase);

        return redirect()->route('guest-report.show', [
            'purchase' => $purchase,
            'token' => $request->query('token'),
        ])->with('paymentStatus', 'success');
    }

    public function show(GuestReportPurchase $purchase, Request $request)
    {
        $this->authorizeToken($purchase, (string) $request->query('token'));

        $report = $purchase->report()->with('vinCheck')->firstOrFail();

        return Inertia::render('report/guest-purchase-status', [
            'purchase' => [
                'status' => $purchase->status,
                'reportId' => $report->id,
                'registrationNumber' => $report->vinCheck?->registration_number,
                'reportUrl' => $purchase->status === 'completed'
                    ? route('guest-report.view', [
                        'purchase' => $purchase,
                        'token' => $request->query('token'),
                    ])
                    : null,
            ],
            'paymentStatus' => session('paymentStatus'),
            'cancelled' => $request->boolean('cancelled'),
        ]);
    }

    public function view(GuestReportPurchase $purchase, Request $request)
    {
        $this->authorizeToken($purchase, (string) $request->query('token'));
        abort_unless($purchase->status === 'completed', 404);

        $report = $purchase->report()->with('vinCheck')->firstOrFail();

        return app(\App\Http\Controllers\ReportController::class)->show($report, $request);
    }

    private function authorizeToken(GuestReportPurchase $purchase, string $token): void
    {
        abort_unless(
            $token !== ''
            && $purchase->access_token_hash
            && hash_equals($purchase->access_token_hash, hash('sha256', $token)),
            404,
        );
    }
}
