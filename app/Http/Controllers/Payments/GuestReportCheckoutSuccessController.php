<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\GuestReportAccessToken;
use App\Models\GuestReportPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Stripe\StripeClient;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class GuestReportCheckoutSuccessController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $sessionId = $request->query('session_id');
        $guestToken = $request->query('guest_token');

        abort_unless(
            is_string($sessionId)
            && preg_match('/\Acs_(?:test|live)_[A-Za-z0-9]+\z/', $sessionId) === 1,
            404,
        );

        abort_unless(
            is_string($guestToken)
            && preg_match('/\A[a-f0-9]{64}\z/', $guestToken) === 1,
            403,
        );

        $purchase = GuestReportPurchase::query()
            ->with('report')
            ->where('provider_order_id', $sessionId)
            ->firstOrFail();

        $report = $purchase->report;

        abort_unless(
            $report !== null && $report->user_id === null,
            404,
        );

        $activeTokenExists = GuestReportAccessToken::query()
            ->where('guest_report_id', $purchase->report_id)
            ->where('token_hash', hash('sha256', $guestToken))
            ->whereNull('revoked_at')
            ->exists();

        abort_unless($activeTokenExists, 403);

        $secret = config('services.stripe.secret');

        abort_unless(
            is_string($secret) && $secret !== '',
            503,
        );

        try {
            $session = (new StripeClient($secret))
                ->checkout->sessions->retrieve($sessionId, []);
        } catch (Throwable $exception) {
            report($exception);

            abort(
                503,
                'Unable to verify your checkout session. Please check your email for updates.',
            );
        }

        $sessionMatchesPurchase =
            $session->id === $sessionId
            && $session->status === 'complete'
            && $session->mode === 'payment'
            && (string) ($session->client_reference_id ?? '')
                === (string) $purchase->id
            && (string) data_get($session, 'metadata.purpose')
                === 'guest_report_purchase'
            && (string) data_get($session, 'metadata.guest_report_purchase_id')
                === (string) $purchase->id
            && (string) data_get($session, 'metadata.report_id')
                === (string) $purchase->report_id;

        abort_unless($sessionMatchesPurchase, 403);

        if ($this->isReadyForInvoice($purchase)) {
            return redirect()->to($this->invoiceUrl($purchase));
        }

        $statusUrl = URL::temporarySignedRoute(
            'guest-report.checkout.status',
            now()->addHours(2),
            ['purchase' => $purchase->id],
        );

        return response()->view('guest-checkout-pending', [
            'statusUrl' => $statusUrl,
        ]);
    }

    public function status(
        GuestReportPurchase $purchase,
    ): JsonResponse {
        if (! $this->isReadyForInvoice($purchase)) {
            return response()->json([
                'ready' => false,
            ], 202);
        }

        return response()->json([
            'ready' => true,
            'invoiceUrl' => $this->invoiceUrl($purchase),
        ]);
    }

    private function isReadyForInvoice(
        GuestReportPurchase $purchase,
    ): bool {
        $report = $purchase->report;

        return $purchase->payment_status === GuestReportPurchase::STATUS_PAID
            && $purchase->fulfillment_status === GuestReportPurchase::FULFILLMENT_COMPLETED
            && $report !== null
            && $report->user_id === null
            && $report->report_type === 'premium';
    }

    private function invoiceUrl(
        GuestReportPurchase $purchase,
    ): string {
        return URL::temporarySignedRoute(
            'guest-report.invoice.show',
            now()->addDays(30),
            ['purchase' => $purchase->id],
        );
    }
}
