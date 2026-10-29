import { useForm } from '@inertiajs/react';
import { AlertCircle, AlertTriangle, Loader2, RotateCcw, X } from 'lucide-react';
import React from 'react';

interface RefundModalProps {
    isOpen: boolean;
    onClose: () => void;
    transaction: {
        raw_id: number;
        source_type: 'registered' | 'guest';
        reference: string;
        customer_name: string;
        customer_email: string;
        amount: number;
        currency: string;
        status: string;
        payment_method: string;
    };
}

export default function RefundModal({
    isOpen,
    onClose,
    transaction,
}: RefundModalProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        reason: '',
        idempotency_key: crypto.randomUUID(),
    });

    if (!isOpen) {
        return null;
    }

    const isReasonValid = data.reason.trim().length >= 5;
    const canSubmit = isReasonValid && !processing;
    const refundError = (errors as Record<string, string | undefined>).refund;

    const route =
        transaction.source_type === 'registered'
            ? `/admin/transactions/${transaction.raw_id}/refund`
            : `/admin/guest-reports/${transaction.raw_id}/refund`;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (!canSubmit) {
            return;
        }

        post(route, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    const amountDisplay =
        transaction.currency === 'GBP'
            ? `£${transaction.amount.toFixed(2)}`
            : `${transaction.amount.toFixed(2)} ${transaction.currency}`;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs">
            <div className="relative w-full max-w-lg rounded-xl border border-outline-variant bg-white shadow-2xl">
                {/* Amber accent bar */}
                <div className="h-1.5 rounded-t-xl bg-amber-500" />

                <div className="p-6">
                    {/* Header */}
                    <div className="flex items-center justify-between border-b border-outline-variant/60 pb-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600">
                                <RotateCcw className="h-5 w-5" />
                            </div>
                            <div>
                                <h3 className="font-sans text-base font-bold text-on-surface">
                                    Initiate Payment Refund
                                </h3>
                                <p className="text-xs text-on-surface-variant">
                                    {transaction.reference} —{' '}
                                    {transaction.customer_name}
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={processing}
                            className="rounded-lg p-1.5 text-on-surface-variant/70 transition hover:bg-surface-container hover:text-on-surface disabled:opacity-50"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    </div>

                    <form onSubmit={handleSubmit} className="mt-5 space-y-4">
                        {/* Transaction details */}
                        <div className="grid grid-cols-2 gap-3 rounded-lg border border-outline-variant/80 bg-surface p-3 text-xs">
                            <div>
                                <span className="block text-[10px] font-bold tracking-wider text-on-surface-variant uppercase">
                                    Reference
                                </span>
                                <span className="mt-0.5 block font-mono font-bold text-primary">
                                    {transaction.reference}
                                </span>
                            </div>
                            <div>
                                <span className="block text-[10px] font-bold tracking-wider text-on-surface-variant uppercase">
                                    Amount
                                </span>
                                <span className="mt-0.5 block font-mono font-bold text-on-surface">
                                    {amountDisplay}
                                </span>
                            </div>
                            <div>
                                <span className="block text-[10px] font-bold tracking-wider text-on-surface-variant uppercase">
                                    Customer
                                </span>
                                <span className="mt-0.5 block font-medium text-on-surface">
                                    {transaction.customer_name}
                                </span>
                                <span className="block text-[10px] text-on-surface-variant">
                                    {transaction.customer_email}
                                </span>
                            </div>
                            <div>
                                <span className="block text-[10px] font-bold tracking-wider text-on-surface-variant uppercase">
                                    Payment Method
                                </span>
                                <span className="mt-0.5 block font-medium capitalize text-on-surface">
                                    {transaction.payment_method}
                                </span>
                                <span className="block text-[10px] text-on-surface-variant">
                                    Status: {transaction.status}
                                </span>
                            </div>
                        </div>

                        {/* Warning notice */}
                        <div className="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-3 text-xs">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
                            <div className="space-y-1 text-red-800">
                                <p className="font-semibold">
                                    Real Payment Refund — Cannot Be Undone
                                </p>
                                <p>
                                    This will initiate a real payment refund
                                    through the connected gateway. This action
                                    cannot be undone. The customer's credit hold
                                    will be maintained until the provider
                                    confirms the refund.
                                </p>
                            </div>
                        </div>

                        {/* Refund error from backend (failed status) */}
                        {refundError && (
                            <div className="flex items-center gap-2 rounded-lg border border-error/30 bg-error-container/30 p-2.5 text-xs text-error">
                                <AlertCircle className="h-4 w-4 shrink-0" />
                                <span>{refundError}</span>
                            </div>
                        )}

                        {/* Reason */}
                        <div>
                            <label
                                htmlFor="refund-reason"
                                className="block text-xs font-semibold text-on-surface"
                            >
                                Refund Reason{' '}
                                <span className="text-error">*</span>
                            </label>
                            <p className="mt-0.5 text-[11px] text-on-surface-variant">
                                Provide an explicit reason for this refund (min
                                5 characters, max 1000).
                            </p>
                            <textarea
                                id="refund-reason"
                                rows={3}
                                value={data.reason}
                                onChange={(e) =>
                                    setData('reason', e.target.value)
                                }
                                disabled={processing}
                                maxLength={1000}
                                placeholder="e.g. Customer requested refund due to duplicate charge on invoice #1234."
                                className="mt-1.5 w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface transition focus:border-amber-500 focus:outline-hidden disabled:opacity-50"
                            />
                            <div className="mt-1 flex items-center justify-between text-[11px]">
                                <span
                                    className={
                                        data.reason.length < 5 &&
                                        data.reason.length > 0
                                            ? 'text-error'
                                            : 'text-on-surface-variant/70'
                                    }
                                >
                                    {data.reason.length} / 5 minimum characters
                                </span>
                                {errors.reason && (
                                    <span className="text-error">
                                        {errors.reason}
                                    </span>
                                )}
                            </div>
                        </div>

                        {/* Idempotency key display */}
                        <div className="rounded border border-outline-variant/60 bg-surface-container/30 px-3 py-2 text-[10px] text-on-surface-variant">
                            <span className="font-mono font-semibold">
                                Idempotency Key:
                            </span>{' '}
                            <span className="font-mono text-[9px]">
                                {data.idempotency_key}
                            </span>
                            {errors.idempotency_key && (
                                <p className="mt-1 text-error">
                                    {errors.idempotency_key}
                                </p>
                            )}
                        </div>

                        {/* Actions */}
                        <div className="mt-6 flex items-center justify-end gap-3 border-t border-outline-variant/60 pt-3">
                            <button
                                type="button"
                                onClick={onClose}
                                disabled={processing}
                                className="rounded-lg border border-outline-variant px-4 py-2 text-xs font-semibold text-on-surface transition hover:bg-surface-container disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={!canSubmit}
                                className="flex items-center gap-2 rounded-lg bg-amber-500 px-5 py-2 text-xs font-semibold text-white transition hover:bg-amber-600 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {processing && (
                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                )}
                                <span>
                                    {processing
                                        ? 'Processing Refund...'
                                        : 'Confirm & Initiate Refund'}
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
}
