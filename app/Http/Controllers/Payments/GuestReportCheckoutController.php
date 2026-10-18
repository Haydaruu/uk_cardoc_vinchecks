<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Services\GuestReportCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use PHPUnit\Framework\TestStatus\Success;
use RuntimeException;

class GuestReportCheckoutController extends Controller
{
    public function store(Request $request, Report $report, GuestReportCheckoutService $CheckoutService)
    {
        $validated = $request->validate([
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:254',
            ],
        ]);

        $reportUrl = route('page.my-report.show',[
            'report' => $report->id,
        ]);

        try{
            $checkout = $CheckoutService->startCheckout(
                report: $report,
                email: $validated['email'],
                successUrl: $reportUrl. '?checkout=return&session_id={CHECKOUT_SESSION_ID}',
                cancelUrl: $reportUrl. '?checkout=cancelled', 
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
}
