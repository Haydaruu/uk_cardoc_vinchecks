<?php

namespace App\Http\Controllers;

use App\Jobs\SendTelegramGuestActivityNotification;
use App\Models\Report;
use App\Services\GuestReportAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function show(
        Report $report,
        Request $request,
        GuestReportAccessService $guestReportAccess
    ): Response|RedirectResponse {
        $user = $request->user();

        if ($report->user_id !== null && (! $user || (int) $report->user_id !== $user->id)) {
            abort(403);
        }

        if ($report->user_id === null && $report->report_type === 'premium') {
            abort_unless(
                $guestReportAccess->hasCompletedPurchase(
                    $report,
                    $request->query('guest_token')
                ),
                403
            );
        }

        $guestContact = null;

        if (
            $report->user_id === null
            && $report->report_type === 'basic'
        ) {
            $vinCheck = $report->vinCheck;

            abort_unless(
                $vinCheck !== null
                && $vinCheck->user_id === null
                && $vinCheck->status === 'success',
                403
            );

            // Only the browser that initiated this guest vehicle check may access it.
            abort_unless(
                $request->session()->get(
                    "guest_vehicle_check_access.{$vinCheck->id}"
                ) === true,
                404
            );

            if (! $report->guestLead()->exists()) {
                return redirect()->route('guest-lead.show', $report);
            }

            $contact = $request->session()->get(
                "guest_lead_contact.{$report->id}"
            );

            // Redirect to contact capture ONLY when the session is missing or invalid.
            if (
                ! is_array($contact)
                || ! is_string($contact['email'] ?? null)
                || ! is_string($contact['phone'] ?? null)
            ) {
                return redirect()->route('guest-lead.show', $report);
            }

            // Valid session: allow the basic preview.
            $guestContact = [
                'email' => $contact['email'],
                'phone' => $contact['phone'],
            ];

            $notificationKey = "guest_preview_notification_sent.{$report->id}";

            if (
                $user === null
                && $request->session()->get($notificationKey) !== true
            ) {
                $message = implode("\n", [
                    'UKCARDOC - GUEST PREVIEW VIEWED',
                    '',
                    'Report ID: '.$report->id,
                    'Registration: '.$vinCheck->registration_number,
                    'Email: '.$guestContact['email'],
                    'Phone: '.($guestContact['phone'] ?: '-'),
                    'Time: '.now()->format('d M Y H:i:s'),
                ]);

                SendTelegramGuestActivityNotification::dispatch(
                    'guest.preview.viewed',
                    (string) $report->id,
                    $message
                );

                $request->session()->put($notificationKey, true);
            }
        }

        // Authenticated preview notification — dispatched once per report per session only for the report owner.
        if (
            $user !== null
            && $report->user_id !== null
            && (int) $report->user_id === (int) $user->id
        ) {
            $authNotificationKey = "auth_preview_notification_sent.{$report->id}";

            if ($request->session()->get($authNotificationKey) !== true) {
                $vinCheck = $vinCheck ?? $report->vinCheck;
                $registration = $vinCheck?->registration_number;

                $message = implode("\n", [
                    'UKCARDOC - AUTH PREVIEW VIEWED',
                    '',
                    'User ID: '.$user->id,
                    'Email: '.$user->email,
                    'Report ID: '.$report->id,
                    'Report Type: '.$report->report_type,
                    'Registration: '.($registration ?? 'N/A'),
                    'Time: '.now()->format('d M Y H:i:s'),
                ]);

                SendTelegramGuestActivityNotification::dispatch(
                    'auth.preview.viewed',
                    (string) $report->id,
                    $message
                );

                $request->session()->put($authNotificationKey, true);
            }
        }

        $reportData = $report->report_data ?? [];

        $isNormalized = data_get($reportData, 'meta.format') === 'normalized'
            && (int) data_get($reportData, 'meta.schema_version', 0) >= 2;

        if ($report->report_type === 'premium') {
            $component = $isNormalized
                ? 'report/normalized-full-report'
                : 'report/full-report';
        } else {
            $component = 'report/show';
        }

        return Inertia::render($component, [
            'report' => [
                'id' => $report->id,
                'report_type' => $report->report_type,
                'data' => $reportData,
                'generated_at' => $report->generated_at,
            ],
            'guestContact' => $guestContact,
        ]);
    }
}
