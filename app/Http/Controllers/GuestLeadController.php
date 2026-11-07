<?php

namespace App\Http\Controllers;

use App\Models\GuestLead;
use App\Models\Report;
use App\Models\VinCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuestLeadController extends Controller
{
    public function show(
        Report $report,
        Request $request,
    ): Response|RedirectResponse {
        $vinCheck = $this->ensureEligibleGuestReport($report, $request);

        $lead = $report->guestLead()->first();

        if ($lead !== null) {
            $this->storeContactInSession($request, $report, $lead);

            return redirect()->route('page.my-report.show', $report);
        }

        $report->loadMissing('vehicle');

        return Inertia::render('guest/lead-capture', [
            'report' => [
                'id' => $report->id,
                'registration_number' => $vinCheck->registration_number
                    ?? $report->vehicle?->registration_number,
                'make' => $report->vehicle?->brand,
                'model' => $report->vehicle?->model,
            ],
        ]);
    }

    public function store(
        Request $request,
        Report $report,
    ): RedirectResponse {
        $vinCheck = $this->ensureEligibleGuestReport($report, $request);

        $existingLead = $report->guestLead()->first();

        if ($existingLead !== null) {
            $this->storeContactInSession(
                $request,
                $report,
                $existingLead,
            );

            return redirect()->route('page.my-report.show', $report);
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $phone = trim((string) ($validated['phone'] ?? ''));

        $lead = GuestLead::query()->firstOrCreate(
            [
                'report_id' => $report->id,
            ],
            [
                'vin_check_id' => $vinCheck->id,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
                'captured_at' => now(),
            ],
        );

        $this->storeContactInSession($request, $report, $lead);

        return redirect()->route('page.my-report.show', $report)
            ->with('success', 'Your contact details have been saved.');
    }

    private function ensureEligibleGuestReport(
        Report $report,
        Request $request,
    ): VinCheck {
        abort_unless(
            $report->user_id === null && $report->report_type === 'basic',
            404
        );

        $vinCheck = $report->vinCheck;

        abort_unless(
            $vinCheck !== null
            && $vinCheck->user_id === null
            && $vinCheck->status === 'success',
            404
        );

        abort_unless(
            $request->session()->get(
                "guest_vehicle_check_access.{$vinCheck->id}"
            ) === true,
            404
        );

        return $vinCheck;
    }

    private function storeContactInSession(
        Request $request,
        Report $report,
        GuestLead $lead,
    ): void {
        $request->session()->put(
            "guest_lead_contact.{$report->id}",
            [
                'email' => $lead->email,
                'phone' => $lead->phone ?? '',
            ],
        );
    }
}
