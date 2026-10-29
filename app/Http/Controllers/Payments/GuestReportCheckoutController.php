<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\SystemSetting;
use App\Services\GuestReportCheckoutService;
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
                'required',
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
                'phone' => trim($validated['phone']),
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

            // PayPal khusus guest belum tersedia
            'paypalClientId' => null,
            'paypalEnvironment' => 'sandbox',

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
