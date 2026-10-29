import { useForm } from '@inertiajs/react';
import { AlertCircle, AlertTriangle, Ban, Loader2, X } from 'lucide-react';
import React from 'react';

interface CancelSubscriptionModalProps {
    isOpen: boolean;
    onClose: () => void;
    subscription: {
        id: number;
        plan_name: string;
        customer_name: string;
        customer_email: string;
        gateway: string;
        current_period_end?: string | null;
        end_date?: string | null;
    };
}

export default function CancelSubscriptionModal({
    isOpen,
    onClose,
    subscription,
}: CancelSubscriptionModalProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        reason: '',
    });

    if (!isOpen) {
        return null;
    }

    const isReasonValid = data.reason.trim().length >= 5;
    const canSubmit = isReasonValid && !processing;
    const subscriptionError =
        (errors as Record<string, string | undefined>).subscription ||
        (errors as Record<string, string | undefined>).error;

    const accessEnds =
        subscription.current_period_end ?? subscription.end_date ?? null;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (!canSubmit) {
            return;
        }

        post(`/admin/subscriptions/${subscription.id}/cancel`, {
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
                {/* Red accent bar */}
                <div className="h-1.5 rounded-t-xl bg-error" />

                <div className="p-6">
                    {/* Header */}
                    <div className="flex items-center justify-between border-b border-outline-variant/60 pb-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-error/10 text-error">
                                <Ban className="h-5 w-5" />
                            </div>
                            <div>
                                <h3 className="font-sans text-base font-bold text-on-surface">
                                    Cancel Subscription
                                </h3>
                                <p className="text-xs text-on-surface-variant">
                                    {subscription.customer_name} (
                                    {subscription.customer_email})
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
                        {/* Subscription details */}
                        <div className="grid grid-cols-2 gap-3 rounded-lg border border-outline-variant/80 bg-surface p-3 text-xs">
                            <div>
                                <span className="block text-[10px] font-bold tracking-wider text-on-surface-variant uppercase">
                                    Plan
                                </span>
                                <span className="mt-0.5 block font-bold text-primary">
                                    {subscription.plan_name}
                                </span>
                            </div>
                            <div>
                                <span className="block text-[10px] font-bold tracking-wider text-on-surface-variant uppercase">
                                    Gateway
                                </span>
                                <span className="mt-0.5 block font-semibold capitalize text-on-surface">
                                    {subscription.gateway}
                                </span>
                            </div>
                            <div className="col-span-2">
                                <span className="block text-[10px] font-bold tracking-wider text-on-surface-variant uppercase">
                                    Customer
                                </span>
                                <span className="mt-0.5 block font-medium text-on-surface">
                                    {subscription.customer_name}
                                </span>
                                <span className="block text-[10px] text-on-surface-variant">
                                    {subscription.customer_email}
                                </span>
                            </div>
                            {accessEnds && (
                                <div className="col-span-2">
                                    <span className="block text-[10px] font-bold tracking-wider text-on-surface-variant uppercase">
                                        Access ends
                                    </span>
                                    <span className="mt-0.5 block font-medium text-on-surface">
                                        {new Date(accessEnds).toLocaleDateString(
                                            'en-GB',
                                            {
                                                day: 'numeric',
                                                month: 'long',
                                                year: 'numeric',
                                            },
                                        )}
                                    </span>
                                </div>
                            )}
                        </div>

                        {/* Warning notice */}
                        <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                            <div className="space-y-1 text-amber-900">
                                <p className="font-semibold">
                                    Cancellation at Period End
                                </p>
                                <p>
                                    Cancellation will stop automatic renewal at
                                    the end of the current period. The customer
                                    retains access until then. This action is
                                    not immediate termination.
                                </p>
                            </div>
                        </div>

                        {/* Subscription error from backend (gateway rejection / failure) */}
                        {subscriptionError && (
                            <div className="flex items-center gap-2 rounded-lg border border-error/30 bg-error-container/30 p-2.5 text-xs text-error">
                                <AlertCircle className="h-4 w-4 shrink-0" />
                                <span>{subscriptionError}</span>
                            </div>
                        )}

                        {/* Reason */}
                        <div>
                            <label
                                htmlFor="cancel-reason"
                                className="block text-xs font-semibold text-on-surface"
                            >
                                Cancellation Reason{' '}
                                <span className="text-error">*</span>
                            </label>
                            <p className="mt-0.5 text-[11px] text-on-surface-variant">
                                Document the reason for cancelling this
                                subscription (min 5 characters, max 1000).
                            </p>
                            <textarea
                                id="cancel-reason"
                                rows={3}
                                value={data.reason}
                                onChange={(e) =>
                                    setData('reason', e.target.value)
                                }
                                disabled={processing}
                                maxLength={1000}
                                placeholder="e.g. Customer requested cancellation via support ticket #5601."
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
                                className="flex items-center gap-2 rounded-lg bg-error px-5 py-2 text-xs font-semibold text-white transition hover:bg-error/90 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {processing && (
                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                )}
                                <span>
                                    {processing
                                        ? 'Cancelling...'
                                        : 'Confirm Cancellation at Period End'}
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
}
