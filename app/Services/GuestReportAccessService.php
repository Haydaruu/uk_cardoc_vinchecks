<?php

namespace App\Services;

use App\Models\GuestReportAccessToken;
use App\Models\GuestReportPurchase;
use App\Models\Report;

class GuestReportAccessService
{
    /**
     * Create a new class instance.
     */
    public function hasCompletedPurchase(Report $report, mixed $accessToken): bool
    {
        if (
            $report->user_id !== null
            || $report->report_type !== 'premium'
            || ! is_string($accessToken)
            || preg_match('/\A[a-f0-9]{64}\z/', $accessToken) !== 1
        ) {
            return false;
        }

        $tokenHash = hash('sha256', $accessToken);

        $hashValidAccesToken = GuestReportAccessToken::query()
            ->where('guest_report_id', $report->id)
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->exists();
        if (! $hashValidAccesToken) {
            return false;
        }

        return GuestReportPurchase::query()
            ->where('report_id', $report->id)
            ->where('payment_status', GuestReportPurchase::STATUS_PAID)
            ->where('fulfillment_status', GuestReportPurchase::FULFILLMENT_COMPLETED)
            ->exists();
    }
}
