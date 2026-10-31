import { useForm } from '@inertiajs/react';
import { AlertCircle, Coins, Loader2, X } from 'lucide-react';
import React, { useState } from 'react';

interface AdjustCreditsModalProps {
    isOpen: boolean;
    onClose: () => void;
    customer: {
        id: number;
        name: string;
        credits: number;
    };
}

export default function AdjustCreditsModal({
    isOpen,
    onClose,
    customer,
}: AdjustCreditsModalProps) {
    const [rawDelta, setRawDelta] = useState<string>('');

    const { data, setData, post, processing, errors, reset } = useForm({
        delta: 0,
        reason: '',
        idempotency_key: crypto.randomUUID(),
    });

    if (!isOpen) {
        return null;
    }

    const deltaNum = parseInt(rawDelta, 10);
    const isValidDelta = !isNaN(deltaNum) && deltaNum !== 0;
    const projectedBalance = isValidDelta
        ? customer.credits + deltaNum
        : customer.credits;
    const isNegativeBalance = isValidDelta && projectedBalance < 0;
    const isReasonValid = data.reason.trim().length >= 5;
    const canSubmit =
        isValidDelta && !isNegativeBalance && isReasonValid && !processing;

    const handleDeltaChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const val = e.target.value;
        setRawDelta(val);
        const parsed = parseInt(val, 10);
        setData('delta', isNaN(parsed) ? 0 : parsed);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (!canSubmit) {
            return;
        }

        post(`/admin/customers/${customer.id}/credits`, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs">
            <div className="relative w-full max-w-lg rounded-xl border border-outline-variant bg-white shadow-2xl">
                {/* Top accent line */}
                <div className="h-1.5 rounded-t-xl bg-primary" />

                <div className="p-6">
                    {/* Header */}
                    <div className="flex items-center justify-between border-b border-outline-variant/60 pb-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <Coins className="h-5 w-5" />
                            </div>
                            <div>
                                <h3 className="font-sans text-base font-bold text-on-surface">
                                    Adjust Customer Credits
                                </h3>
                                <p className="text-xs text-on-surface-variant">
                                    {customer.name} (ID #{customer.id})
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
                        {/* Balance preview card */}
                        <div className="grid grid-cols-3 gap-3 rounded-lg border border-outline-variant/80 bg-surface p-3 text-center">
                            <div>
                                <span className="block text-[10px] font-bold tracking-wider text-on-surface-variant uppercase">
                                    Current
                                </span>
                                <span className="mt-0.5 block font-mono text-lg font-bold text-on-surface">
                                    {customer.credits}
                                </span>
                            </div>
                            <div>
                                <span className="block text-[10px] font-bold tracking-wider text-on-surface-variant uppercase">
                                    Adjustment
                                </span>
                                <span
                                    className={`mt-0.5 block font-mono text-lg font-bold ${
                                        isValidDelta
                                            ? deltaNum > 0
                                                ? 'text-emerald-600'
                                                : 'text-error'
                                            : 'text-on-surface-variant/40'
                                    }`}
                                >
                                    {isValidDelta
                                        ? deltaNum > 0
                                            ? `+${deltaNum}`
                                            : deltaNum
                                        : '0'}
                                </span>
                            </div>
                            <div>
                                <span className="block text-[10px] font-bold tracking-wider text-on-surface-variant uppercase">
                                    Resulting
                                </span>
                                <span
                                    className={`mt-0.5 block font-mono text-lg font-bold ${
                                        isNegativeBalance
                                            ? 'text-error'
                                            : 'text-primary'
                                    }`}
                                >
                                    {isValidDelta
                                        ? projectedBalance
                                        : customer.credits}
                                </span>
                            </div>
                        </div>

                        {isNegativeBalance && (
                            <div className="flex items-center gap-2 rounded-lg border border-error/30 bg-error-container/30 p-2.5 text-xs text-error">
                                <AlertCircle className="h-4 w-4 shrink-0" />
                                <span>
                                    Customer balance cannot drop below zero.
                                    Maximum possible deduction is -
                                    {customer.credits}.
                                </span>
                            </div>
                        )}

                        {/* Delta input */}
                        <div>
                            <label
                                htmlFor="delta"
                                className="block text-xs font-semibold text-on-surface"
                            >
                                Credit Adjustment Amount{' '}
                                <span className="text-error">*</span>
                            </label>
                            <p className="mt-0.5 text-[11px] text-on-surface-variant">
                                Use positive integer (e.g. 5) to grant, or
                                negative (e.g. -2) to deduct.
                            </p>
                            <input
                                id="delta"
                                type="number"
                                step="1"
                                value={rawDelta}
                                onChange={handleDeltaChange}
                                disabled={processing}
                                placeholder="e.g. 5 or -2"
                                className="mt-1.5 w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface transition focus:border-primary focus:outline-hidden disabled:opacity-50"
                            />
                            {errors.delta && (
                                <p className="mt-1 text-xs text-error">
                                    {errors.delta}
                                </p>
                            )}
                        </div>

                        {/* Reason input */}
                        <div>
                            <label
                                htmlFor="reason"
                                className="block text-xs font-semibold text-on-surface"
                            >
                                Mandatory Audit Reason{' '}
                                <span className="text-error">*</span>
                            </label>
                            <p className="mt-0.5 text-[11px] text-on-surface-variant">
                                Provide an explicit rationale for this manual
                                ledger modification (min 5 characters).
                            </p>
                            <textarea
                                id="reason"
                                rows={3}
                                value={data.reason}
                                onChange={(e) =>
                                    setData('reason', e.target.value)
                                }
                                disabled={processing}
                                placeholder="e.g. Goodwill credit adjustment per support ticket #4812"
                                className="mt-1.5 w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface transition focus:border-primary focus:outline-hidden disabled:opacity-50"
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

                        {/* Technical idempotency note */}
                        <div className="rounded border border-outline-variant/60 bg-surface-container/30 px-3 py-2 text-[10px] text-on-surface-variant">
                            <span className="font-mono font-semibold">
                                Idempotency Key:
                            </span>{' '}
                            <span className="font-mono text-[9px]">
                                {data.idempotency_key}
                            </span>
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
                                className="hover:bg-primary-hover flex items-center gap-2 rounded-lg bg-primary px-5 py-2 text-xs font-semibold text-white transition disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {processing && (
                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                )}
                                <span>
                                    {processing
                                        ? 'Applying Adjustment...'
                                        : 'Confirm & Apply Adjustment'}
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
}
