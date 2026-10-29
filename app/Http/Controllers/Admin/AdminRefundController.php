<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RefundGuestReportRequest;
use App\Http\Requests\Admin\RefundTransactionRequest;
use App\Models\GuestReportPurchase;
use App\Models\RefundRecord;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\AdminRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminRefundController extends Controller
{
    /**
     * Process a full refund for a registered customer transaction.
     */
    public function refundTransaction(
        RefundTransactionRequest $request,
        Transaction $transaction,
        AdminRefundService $refundService,
    ): RedirectResponse|JsonResponse {
        $admin = $request->user();
        assert($admin instanceof User);

        $record = $refundService->executeRefund(
            admin: $admin,
            source: $transaction,
            reason: (string) $request->validated('reason'),
            idempotencyKey: (string) $request->validated('idempotency_key'),
            request: $request,
        );

        return $this->buildRefundResponse($request, $record, "Transaction #{$transaction->id}");
    }

    /**
     * Process a full refund for a guest vehicle report purchase.
     */
    public function refundGuestReport(
        RefundGuestReportRequest $request,
        GuestReportPurchase $guestReportPurchase,
        AdminRefundService $refundService,
    ): RedirectResponse|JsonResponse {
        $admin = $request->user();
        assert($admin instanceof User);

        $record = $refundService->executeRefund(
            admin: $admin,
            source: $guestReportPurchase,
            reason: (string) $request->validated('reason'),
            idempotencyKey: (string) $request->validated('idempotency_key'),
            request: $request,
        );

        return $this->buildRefundResponse($request, $record, "Guest Report Purchase #{$guestReportPurchase->id}");
    }

    /**
     * Format a transparent response reflecting the exact provider outcome.
     */
    protected function buildRefundResponse(Request $request, RefundRecord $record, string $label): RedirectResponse|JsonResponse
    {
        if ($record->status === RefundRecord::STATUS_SUCCESS) {
            $msg = "Refund for {$label} processed and confirmed by {$record->gateway} (Ref: {$record->refund_gateway_ref}).";

            if ($request->wantsJson()) {
                return response()->json([
                    'status' => 'success',
                    'message' => $msg,
                    'refund_record' => $record,
                ], 200);
            }

            return back()->with('success', $msg);
        }

        if ($record->status === RefundRecord::STATUS_UNKNOWN) {
            $msg = "Refund for {$label} was dispatched, but provider response was ambiguous or timed out. Credit hold is maintained for reconciliation.";

            if ($request->wantsJson()) {
                return response()->json([
                    'status' => 'unknown',
                    'message' => $msg,
                    'refund_record' => $record,
                ], 202);
            }

            return back()->with('warning', $msg)->with('refund_status', 'unknown');
        }

        // STATUS_FAILED
        $msg = "Gateway rejected refund for {$label}: ".($record->error_message ?: 'Definitive gateway rejection').'. Uncommitted credit holds have been reversed.';

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'failed',
                'message' => $msg,
                'refund_record' => $record,
            ], 422);
        }

        return back()->withErrors(['refund' => $msg])->with('refund_status', 'failed');
    }
}
