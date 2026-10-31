import { useForm } from '@inertiajs/react';
import { CheckCircle2, Loader2, UserCheck, X } from 'lucide-react';
import React from 'react';

interface ReactivateCustomerModalProps {
    isOpen: boolean;
    onClose: () => void;
    customer: {
        id: number;
        name: string;
        suspended_reason?: string | null;
    };
}

export default function ReactivateCustomerModal({
    isOpen,
    onClose,
    customer,
}: ReactivateCustomerModalProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        reason: '',
    });

    if (!isOpen) {
        return null;
    }

    const isReasonValid = data.reason.trim().length >= 5;
    const canSubmit = isReasonValid && !processing;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (!canSubmit) {
            return;
        }

        post(`/admin/customers/${customer.id}/reactivate`, {
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
                {/* Green accent line */}
                <div className="h-1.5 rounded-t-xl bg-emerald-600" />

                <div className="p-6">
                    {/* Header */}
                    <div className="flex items-center justify-between border-b border-outline-variant/60 pb-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                                <UserCheck className="h-5 w-5" />
                            </div>
                            <div>
                                <h3 className="font-sans text-base font-bold text-on-surface">
                                    Reactivate Customer Account
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
                        {/* Status notification */}
                        <div className="flex items-start gap-3 rounded-lg border border-emerald-200 bg-emerald-50/60 p-3 text-xs text-emerald-800">
                            <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                            <div className="space-y-1">
                                <p className="font-semibold">
                                    Restoring Account Privileges
                                </p>
                                <p className="text-on-surface-variant">
                                    Reactivating this customer will immediately
                                    allow them to run vehicle checks and
                                    purchase credits.
                                </p>
                                {customer.suspended_reason && (
                                    <p className="mt-1 text-[11px] text-on-surface-variant italic">
                                        Suspended Reason was: &ldquo;
                                        {customer.suspended_reason}&rdquo;
                                    </p>
                                )}
                            </div>
                        </div>

                        {/* Reactivation reason */}
                        <div>
                            <label
                                htmlFor="reactivate-reason"
                                className="block text-xs font-semibold text-on-surface"
                            >
                                Mandatory Reactivation Reason{' '}
                                <span className="text-error">*</span>
                            </label>
                            <p className="mt-0.5 text-[11px] text-on-surface-variant">
                                Record the reason for restoring account access
                                (minimum 5 characters).
                            </p>
                            <textarea
                                id="reactivate-reason"
                                rows={3}
                                value={data.reason}
                                onChange={(e) =>
                                    setData('reason', e.target.value)
                                }
                                disabled={processing}
                                placeholder="e.g. Identity verified, payment resolved, customer dispute settled."
                                className="mt-1.5 w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface transition focus:border-emerald-600 focus:outline-hidden disabled:opacity-50"
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
                                className="flex items-center gap-2 rounded-lg bg-emerald-600 px-5 py-2 text-xs font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {processing && (
                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                )}
                                <span>
                                    {processing
                                        ? 'Reactivating...'
                                        : 'Confirm Reactivation'}
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
}
