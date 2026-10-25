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

    public function tokenForCompletedPurchase(
        GuestReportPurchase $purchase,
    ): ?string {
        if (
            $purchase->payment_status !== GuestReportPurchase::STATUS_PAID
            || $purchase->fulfillment_status !== GuestReportPurchase::FULFILLMENT_COMPLETED
        ) {
            return null;
        }

        $report = $purchase->report;

        if (
            ! $report
            || $report->user_id !== null
            || $report->report_type !== 'premium'
        ) {
            return null;
        }

        $appKey = config('app.key');

        if (! is_string($appKey) || $appKey === '') {
            return null;
        }

        $material = sprintf(
            'vincheck-guest-report-access:report:%d:purchase:%d',
            $purchase->report_id,
            $purchase->id,
        );

        $token = hash_hmac('sha256', $material, $appKey);

        $isActive = GuestReportAccessToken::query()
            ->where('guest_report_id', $purchase->report_id)
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->exists();

        return $isActive ? $token : null;
    }
}
