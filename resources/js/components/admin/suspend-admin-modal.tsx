import { useForm } from '@inertiajs/react';
import { AlertTriangle, Loader2, UserX, X } from 'lucide-react';
import React from 'react';

interface SuspendAdminModalProps {
    isOpen: boolean;
    onClose: () => void;
    admin: {
        id: number;
        name: string;
        email: string;
        role: string;
    } | null;
}

export default function SuspendAdminModal({
    isOpen,
    onClose,
    admin,
}: SuspendAdminModalProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        reason: '',
    });

    const formErrors = errors as Record<string, string | undefined>;

    if (!isOpen || !admin) {
        return null;
    }

    const isReasonValid = data.reason.trim().length >= 5;
    const canSubmit = isReasonValid && !processing;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (!canSubmit) {
            return;
        }

        post(`/admin/administration/admins/${admin.id}/suspend`, {
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
                {/* Red warning top bar */}
                <div className="h-1.5 rounded-t-xl bg-error" />

                <div className="p-6">
                    <div className="flex items-center justify-between pb-4 border-b border-outline-variant/60">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-error/10 text-error">
                                <UserX className="h-5 w-5" />
                            </div>
                            <div>
                                <h3 className="font-sans text-base font-bold text-on-surface">
                                    Deactivate Administrator
                                </h3>
                                <p className="text-xs text-on-surface-variant">
                                    {admin.name} ({admin.email}) — <span className="font-semibold uppercase">{admin.role}</span>
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
                        <div className="flex items-start gap-3 rounded-lg border border-error/30 bg-error-container/20 p-3 text-xs text-error">
                            <AlertTriangle className="h-4 w-4 shrink-0 mt-0.5" />
                            <div className="space-y-1 text-on-surface-variant">
                                <p className="font-semibold text-error">
                                    Revoke Console Access
                                </p>
                                <p>
                                    Deactivating this administrator immediately blocks login and operational access. Per security specification, accounts are never permanently deleted. The backend strictly prevents deactivating the last remaining active Super Admin.
                                </p>
                            </div>
                        </div>

                        {formErrors.user && (
                            <div className="rounded-lg border border-error/40 bg-error/10 p-3 text-xs font-semibold text-error">
                                {formErrors.user}
                            </div>
                        )}

                        <div>
                            <label
                                htmlFor="suspend-admin-reason"
                                className="block text-xs font-semibold text-on-surface"
                            >
                                Mandatory Deactivation Reason <span className="text-error">*</span>
                            </label>
                            <textarea
                                id="suspend-admin-reason"
                                rows={3}
                                value={data.reason}
                                onChange={(e) => setData('reason', e.target.value)}
                                disabled={processing}
                                placeholder="e.g. Employee departure or extended leave of absence"
                                className="mt-1.5 w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface transition focus:border-error focus:outline-hidden disabled:opacity-50"
                            />
                            <div className="mt-1 flex items-center justify-between text-[11px]">
                                <span className={data.reason.length < 5 && data.reason.length > 0 ? 'text-error' : 'text-on-surface-variant/70'}>
                                    {data.reason.length} / 5 minimum characters
                                </span>
                                {errors.reason && (
                                    <span className="text-error">{errors.reason}</span>
                                )}
                            </div>
                        </div>

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
                                {processing && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
                                <span>{processing ? 'Deactivating...' : 'Confirm Deactivation'}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
}
