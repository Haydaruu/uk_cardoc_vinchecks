import { useForm } from '@inertiajs/react';
import { AlertTriangle, Loader2, UserX, X } from 'lucide-react';
import React from 'react';

interface SuspendCustomerModalProps {
    isOpen: boolean;
    onClose: () => void;
    customer: {
        id: number;
        name: string;
        email: string;
    };
}

export default function SuspendCustomerModal({
    isOpen,
    onClose,
    customer,
}: SuspendCustomerModalProps) {
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

        post(`/admin/customers/${customer.id}/suspend`, {
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
                {/* Red warning bar */}
                <div className="h-1.5 rounded-t-xl bg-error" />

                <div className="p-6">
                    {/* Header */}
                    <div className="flex items-center justify-between pb-4 border-b border-outline-variant/60">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-error/10 text-error">
                                <UserX className="h-5 w-5" />
                            </div>
                            <div>
                                <h3 className="font-sans text-base font-bold text-on-surface">
                                    Suspend Customer Account
                                </h3>
                                <p className="text-xs text-on-surface-variant">
                                    {customer.name} ({customer.email})
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
                        {/* Caution notice */}
                        <div className="flex items-start gap-3 rounded-lg border border-error/30 bg-error-container/20 p-3 text-xs text-error">
                            <AlertTriangle className="h-4 w-4 shrink-0 mt-0.5" />
                            <div className="space-y-1 text-on-surface-variant">
                                <p className="font-semibold text-error">
                                    Immediate Account Suspension
                                </p>
                                <p>
                                    Suspending this customer will immediately
                                    block them from running vehicle checks,
                                    purchasing credit packs, and starting
                                    subscriptions. Historical reports and
                                    invoices remain viewable.
                                </p>
                            </div>
                        </div>

                        {/* Suspension reason */}
                        <div>
                            <label
                                htmlFor="suspend-reason"
                                className="block text-xs font-semibold text-on-surface"
                            >
                                Mandatory Suspension Reason{' '}
                                <span className="text-error">*</span>
                            </label>
                            <p className="mt-0.5 text-[11px] text-on-surface-variant">
                                Document the official justification for this
                                suspension (minimum 5 characters).
                            </p>
                            <textarea
                                id="suspend-reason"
                                rows={3}
                                value={data.reason}
                                onChange={(e) =>
                                    setData('reason', e.target.value)
                                }
                                disabled={processing}
                                placeholder="e.g. Chargeback claim initiated via payment processor, suspicious rapid queries."
                                className="mt-1.5 w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface transition focus:border-error focus:outline-hidden disabled:opacity-50"
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
                        <div className="mt-6 flex items-center justify-end gap-3 pt-3 border-t border-outline-variant/60">
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
                                className="flex items-center gap-2 rounded-lg bg-error px-5 py-2 text-xs font-semibold text-white transition hover:bg-error/90 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {processing && (
                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                )}
                                <span>
                                    {processing
                                        ? 'Suspending...'
                                        : 'Confirm Suspension'}
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
}
